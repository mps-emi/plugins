<?php
/**
 * The MPS payment method: a redirect to the MPS-hosted checkout.
 *
 * @package MpsPayments
 */

defined( 'ABSPATH' ) || exit;

/**
 * Opens an MPS internet payment for an order, sends the shopper to the hosted checkout, and settles
 * the order from what the gateway says the payment is — never from which URL the shopper came back on.
 *
 * Three things can tell this plugin to look again: the shopper returning, MPS's webhook, and a
 * scheduled check. All three do the same thing — read the payment and act on its state — so none of
 * them has to be trusted on its own, and a shop that cannot receive webhooks still settles.
 */
class Mps_Payments_Gateway extends WC_Payment_Gateway {

	const ID = 'mps_payments';

	const DEFAULT_GATEWAY_URL = 'https://gateway.mps-emi.com';

	/** The option WooCommerce keeps this payment method's settings in. */
	const SETTINGS_OPTION = 'woocommerce_mps_payments_settings';

	/** Settings of versions before 1.0.0, which connected with a client ID and secret: removed on upgrade. */
	const REMOVED_SETTINGS = array( 'client_id', 'client_secret' );

	/** Meta holding every MPS payment opened for an order, one row per checkout attempt. */
	const META_REFERENCE = '_mps_payment_reference';

	/** Meta holding the attempt the shopper was last sent to. */
	const META_CURRENT_REFERENCE = '_mps_payment_reference_current';

	/** Meta holding the Idempotency-Key of the attempt being opened, until MPS answers it with a payment. */
	const META_PAYMENT_KEY = '_mps_payment_idempotency_key';

	/** Meta holding the last state read, so a repeat read adds no second order note. */
	const META_LAST_STATE = '_mps_payment_state';

	/** Meta holding the MPS refund id: on the WooCommerce refund, and one row per MPS refund on the order. */
	const META_REFUND_ID = '_mps_refund_id';

	/** Meta on the WooCommerce refund: the outcome MPS notified (refunded), so it is noted once. */
	const META_REFUND_STATUS = '_mps_refund_status';

	/** Meta on the WooCommerce refund: the order's status before it, to put back if the refund is undone. */
	const META_ORDER_STATUS = '_mps_order_status_before_refund';

	/** Scheduled re-reads after a payment is opened: covers a closed tab on a shop without webhooks. */
	const CHECK_HOOK = 'mps_payments_check';

	/** Seconds after opening at which a scheduled check runs; it stops at the first final state. */
	const CHECK_DELAYS = array( 600, 1800, 3600, 21600 );

	/**
	 * The WooCommerce refund being created, handed over by the woocommerce_create_refund action: WooCommerce
	 * passes process_refund the amount, never the refund itself.
	 *
	 * @var WC_Order_Refund|null
	 */
	private static $refund_in_progress = null;

	/**
	 * Loads the settings and hooks the return and webhook handlers.
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->has_fields         = false;
		$this->method_title       = __( 'MPS Payments', 'mps-payments' );
		$this->method_description = __( 'Payments on the checkout page MPS hosts. Card details never reach your shop.', 'mps-payments' );
		$this->supports           = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();

		if ( defined( 'MPS_PAYMENTS_GATEWAY_URL' ) ) {
			$this->settings['gateway_url'] = MPS_PAYMENTS_GATEWAY_URL;
		}

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_api_mps_payments_return', array( $this, 'handle_return' ) );
		add_action( 'woocommerce_api_mps_payments_webhook', array( $this, 'handle_webhook' ) );
	}

	/**
	 * The settings form.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'        => array(
				'title'   => __( 'Enable', 'mps-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Offer MPS Payments at checkout', 'mps-payments' ),
				'default' => 'no',
			),
			'title'          => array(
				'title'       => __( 'Title', 'mps-payments' ),
				'type'        => 'text',
				'description' => __( 'The name of this payment method at checkout.', 'mps-payments' ),
				'default'     => __( 'MPS', 'mps-payments' ),
				'desc_tip'    => true,
			),
			'description'    => array(
				'title'       => __( 'Description', 'mps-payments' ),
				'type'        => 'textarea',
				'description' => __( 'Shown under the title at checkout.', 'mps-payments' ),
				'default'     => __( 'Pay by card or with your banking app on the secure MPS payment page.', 'mps-payments' ),
				'desc_tip'    => true,
			),
			'credentials'    => array(
				'title'       => __( 'Connection', 'mps-payments' ),
				'type'        => 'title',
				'description' => __( 'Paste the API key and the webhook signing secret from the MPS platform (Online › Get started). A test key, which starts with mps_test_, accepts only the MPS test cards: no card is charged and nothing is paid out.', 'mps-payments' )
					. '</p><p>' . __( 'This payment method sends the order amount, order number and your shop\'s return addresses to MPS (Mobile Payment Solutions) to open each payment.', 'mps-payments' ),
			),
			'api_key'        => array(
				'title' => __( 'API key', 'mps-payments' ),
				'type'  => 'password',
			),
			'webhook_secret' => array(
				'title'       => __( 'Webhook signing secret', 'mps-payments' ),
				'type'        => 'password',
				'description' => __( 'Shown with your key in the MPS platform. Payment notifications are only acted on when their signature matches; without a secret they are ignored and orders are settled by the plugin\'s own checks.', 'mps-payments' ),
				'desc_tip'    => true,
			),
			'advanced'       => array(
				'title' => __( 'Advanced', 'mps-payments' ),
				'type'  => 'title',
			),
			'gateway_url'    => array(
				'title'       => __( 'Gateway URL', 'mps-payments' ),
				'type'        => 'text',
				'default'     => self::DEFAULT_GATEWAY_URL,
				'description' => __( 'Leave as is unless MPS asks you to change it.', 'mps-payments' ),
				'desc_tip'    => true,
			),
		);

		// wp-config.php wins over the setting; show what is actually called rather than a value that is ignored.
		if ( defined( 'MPS_PAYMENTS_GATEWAY_URL' ) ) {
			$this->form_fields['gateway_url'] = array(
				'title'             => __( 'Gateway URL', 'mps-payments' ),
				'type'              => 'text',
				'default'           => MPS_PAYMENTS_GATEWAY_URL,
				'description'       => __( 'Set by MPS_PAYMENTS_GATEWAY_URL in wp-config.php.', 'mps-payments' ),
				'custom_attributes' => array( 'readonly' => 'readonly' ),
			);
		}

		$this->form_fields += array(
			'debug' => array(
				'title'   => __( 'Debug log', 'mps-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Log every call to MPS in WooCommerce › Status › Logs (never secrets or payment links)', 'mps-payments' ),
				'default' => 'no',
			),
		);
	}

	/**
	 * Offered only with a key configured and the shop in EUR, the one currency MPS takes.
	 *
	 * @return bool
	 */
	public function is_available() {
		return parent::is_available()
			&& $this->has_credentials()
			&& 'EUR' === get_woocommerce_currency();
	}

	/**
	 * Whether there is an API key to pay with. A client ID and secret no longer connect.
	 *
	 * @return bool
	 */
	private function has_credentials() {
		return '' !== $this->get_option( 'api_key' );
	}

	/**
	 * Whether the configured key is a test key. A test key accepts only the MPS test cards, charges
	 * nothing; it is worth telling the merchant so when the key is saved.
	 */
	public function is_test_key() {
		return Mps_Payments_Api::is_test_key( $this->get_option( 'api_key' ) );
	}

	/**
	 * What to tell the shop's admin when the payment method is enabled but has no API key, so is not offered at
	 * checkout: typically a shop set up with a client ID and secret before 1.0.0, which no longer connect.
	 *
	 * @param array|false $settings The stored settings, or false when they were never saved.
	 * @return string The message, or empty when there is nothing to say.
	 */
	public static function missing_api_key_message( $settings ) {
		if ( ! is_array( $settings ) || ! isset( $settings['enabled'] ) || 'yes' !== $settings['enabled'] || ! empty( $settings['api_key'] ) ) {
			return '';
		}

		return __( 'MPS Payments is enabled but not offered at checkout: it has no API key. A client ID and secret no longer connect to MPS. Paste the API key and the webhook signing secret from the MPS platform (Online › Get started) under WooCommerce › Settings › Payments › MPS Payments.', 'mps-payments' );
	}

	/**
	 * The upgrade to 1.0.0: deletes the client ID and secret earlier versions connected with, and the tokens
	 * bought with them, so no secret is left in the shop's database. Runs on every load and does nothing once
	 * the settings no longer hold them.
	 */
	public static function forget_client_credentials() {
		$settings = get_option( self::SETTINGS_OPTION );
		if ( ! is_array( $settings ) || ! array_intersect_key( $settings, array_flip( self::REMOVED_SETTINGS ) ) ) {
			return;
		}

		update_option( self::SETTINGS_OPTION, array_diff_key( $settings, array_flip( self::REMOVED_SETTINGS ) ) );

		// The tokens were transients keyed by a hash of the client ID and secret; there is no other way to find them.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_mps\\_payments\\_token\\_%' OR option_name LIKE '\\_transient\\_timeout\\_mps\\_payments\\_token\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Saves the settings, then proves the API key with a real call to MPS, so a wrong key is found
	 * now rather than by the first shopper.
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();

		if ( ! $this->has_credentials() ) {
			return $saved;
		}

		try {
			$this->api()->verify_credentials();
			WC_Admin_Settings::add_message(
				$this->is_test_key()
					? __( 'Connected to MPS with a test key. It accepts only the MPS test cards; no card is charged.', 'mps-payments' )
					: __( 'Connected to MPS.', 'mps-payments' )
			);
		} catch ( Exception $e ) {
			WC_Admin_Settings::add_error(
				/* translators: %s: the reason the connection failed */
				sprintf( __( 'MPS did not accept these credentials: %s', 'mps-payments' ), $e->getMessage() )
			);
		}

		return $saved;
	}

	/**
	 * Opens an MPS payment for the order and sends the shopper to its checkout.
	 *
	 * The Idempotency-Key is kept on the order before MPS is called, and dropped once MPS answers with a
	 * payment. A resend of the same attempt — a double-submitted checkout, or placing the order again after a
	 * create that got no answer — finds it and sends it again with the same body, so MPS hands back the payment
	 * it already made. Once a payment is recorded, the shopper is here again only after it failed or was left,
	 * and that is a new attempt with a new key. The key names the amount, so a changed cart gets a new one too.
	 *
	 * @param int $order_id The order.
	 * @return array result, and redirect on success.
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		$body = array(
			'amount'     => (int) round( (float) $order->get_total() * 100 ),
			'reference'  => (string) $order->get_order_number(),
			'successUrl' => $this->return_url( $order, 'success' ),
			'failureUrl' => $this->return_url( $order, 'failure' ),
		);

		$callback_url = WC()->api_request_url( 'mps_payments_webhook' );
		if ( self::can_receive_webhooks( $callback_url ) ) {
			$body['callbackUrl'] = $callback_url;
		}

		try {
			$payment = $this->api()->create_payment( $body, self::payment_key( $order, $body['amount'] ) );
		} catch ( Exception $e ) {
			// A refusal opened nothing, so a new key cannot open a second payment; keeping this one would refuse the
			// order's next attempt too, if its body differs. No answer, a 5xx or a 409 (still being opened) keeps it.
			if ( $e instanceof Mps_Payments_Api_Exception && $e->getCode() >= 400 && $e->getCode() < 500 && 409 !== $e->getCode() ) {
				$order->delete_meta_data( self::META_PAYMENT_KEY );
				$order->save();
			}
			$order->add_order_note( __( 'MPS could not open a payment. Enable the debug log for details.', 'mps-payments' ) );
			wc_add_notice( __( 'The payment could not be started. Please try again or choose another payment method.', 'mps-payments' ), 'error' );
			return array( 'result' => 'failure' );
		}

		if ( empty( $payment['paymentReference'] ) || empty( $payment['checkoutUrl'] ) ) {
			wc_add_notice( __( 'The payment could not be started. Please try again or choose another payment method.', 'mps-payments' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$reference = $payment['paymentReference'];

		$order->add_meta_data( self::META_REFERENCE, $reference, false );
		$order->update_meta_data( self::META_CURRENT_REFERENCE, $reference );
		$order->delete_meta_data( self::META_PAYMENT_KEY );
		$order->add_order_note(
			/* translators: %s: the MPS payment reference */
			sprintf( __( 'MPS payment %s opened; shopper sent to the MPS checkout.', 'mps-payments' ), $reference )
		);
		$order->save();

		self::schedule_check( $order->get_id(), 0 );

		// checkoutUrl pays this payment for whoever holds it: hand it to the browser and nowhere else.
		return array(
			'result'   => 'success',
			'redirect' => $payment['checkoutUrl'],
		);
	}

	/**
	 * The Idempotency-Key for opening the order's payment: the one kept on the order while it is for the same
	 * amount, else a new one, saved on the order before it is sent.
	 *
	 * @param WC_Order $order The order.
	 * @param int      $cents The amount being opened.
	 * @return string wc-<order id>-payment-<cents>-<uuid>.
	 */
	private static function payment_key( $order, $cents ) {
		$prefix = sprintf( 'wc-%d-payment-%d-', $order->get_id(), $cents );
		$key    = (string) $order->get_meta( self::META_PAYMENT_KEY );

		if ( 0 !== strpos( $key, $prefix ) ) {
			$key = $prefix . wp_generate_uuid4();
			$order->update_meta_data( self::META_PAYMENT_KEY, $key );
			$order->save();
		}

		return $key;
	}

	/**
	 * Refunds through MPS, from WooCommerce's refund button. Pending is accepted: MPS holds every refund for
	 * 4 hours (cancelableUntil), during which it can be canceled in MPS, and then sends it.
	 *
	 * The Idempotency-Key is the order, the how-manieth MPS refund of the order this is, and the amount.
	 * WooCommerce deletes its refund when this answers an error, so a retry gets a new refund id; this key
	 * stays the same for it, and a timed-out refund that MPS did make is not made a second time.
	 *
	 * @param int        $order_id The order.
	 * @param float|null $amount   What to refund, in euros; null for everything still refundable.
	 * @param string     $reason   The merchant's reason, which stays in WooCommerce.
	 * @return bool|WP_Error True when MPS took the refund, else why not.
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order     = wc_get_order( $order_id );
		$reference = $order ? self::paid_reference( $order ) : '';
		$wc_refund = self::$refund_in_progress;

		self::$refund_in_progress = null;

		if ( '' === $reference ) {
			return new WP_Error( 'mps_payments_refund', __( 'This order has no MPS payment to refund.', 'mps-payments' ) );
		}

		$cents = null === $amount ? null : self::to_cents( $amount );
		$key   = sprintf( 'wc-%d-refund-%d-%s', $order->get_id(), count( $order->get_meta( self::META_REFUND_ID, false ) ) + 1, null === $cents ? 'all' : $cents );

		try {
			$refund = $this->api()->refund_payment( $reference, $cents, $key );
		} catch ( Exception $e ) {
			return new WP_Error( 'mps_payments_refund', self::refund_refusal( $e ) );
		}

		$refund_id = isset( $refund['refundId'] ) ? (string) $refund['refundId'] : '';
		$status    = isset( $refund['status'] ) ? (string) $refund['status'] : '';
		$refunded  = wc_price( ( isset( $refund['amount'] ) ? (int) $refund['amount'] : (int) $cents ) / 100 );

		if ( in_array( $status, array( 'failed', 'canceled' ), true ) ) {
			return new WP_Error(
				'mps_payments_refund',
				/* translators: 1: the MPS refund id, 2: why it failed, e.g. declined */
				sprintf( __( 'MPS refused refund %1$s (%2$s). Nothing was refunded.', 'mps-payments' ), $refund_id, isset( $refund['failureReason'] ) ? (string) $refund['failureReason'] : $status )
			);
		}

		$order->add_meta_data( self::META_REFUND_ID, $refund_id, false );

		if ( 'refunded' === $status ) {
			/* translators: 1: the MPS refund id, 2: the amount */
			$order->add_order_note( sprintf( __( 'MPS refund %1$s: %2$s refunded.', 'mps-payments' ), $refund_id, $refunded ) );
		} elseif ( ! empty( $refund['cancelableUntil'] ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: the MPS refund id, 2: the amount, 3: date and time it is sent */
					__( 'MPS refund %1$s of %2$s is being processed. It is sent after %3$s and can be canceled in MPS until then.', 'mps-payments' ),
					$refund_id,
					$refunded,
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( (string) $refund['cancelableUntil'] ) )
				)
			);
		} else {
			// Pending, or a status this plugin does not know yet: the refund exists in MPS and may be paid out.
			/* translators: 1: the MPS refund id, 2: the amount */
			$order->add_order_note( sprintf( __( 'MPS refund %1$s of %2$s is being processed.', 'mps-payments' ), $refund_id, $refunded ) );
		}

		$order->save();

		if ( $wc_refund && (int) $wc_refund->get_parent_id() === (int) $order->get_id() ) {
			$wc_refund->update_meta_data( self::META_REFUND_ID, $refund_id );
			// WooCommerce sets a fully refunded order to Refunded only after this returns.
			$wc_refund->update_meta_data( self::META_ORDER_STATUS, $order->get_status() );
			$wc_refund->save();
		}

		return true;
	}

	/**
	 * Keeps the refund WooCommerce is creating, for process_refund to put the MPS refund id on.
	 *
	 * @param WC_Order_Refund $refund The refund, saved right after this.
	 */
	public static function remember_refund( $refund ) {
		self::$refund_in_progress = $refund;
	}

	/**
	 * Euros as WooCommerce writes them, in cents, without float rounding: 19.99 is 1999.
	 *
	 * @param float|string $amount The amount.
	 * @return int
	 */
	public static function to_cents( $amount ) {
		return (int) str_replace( '.', '', number_format( (float) $amount, 2, '.', '' ) );
	}

	/**
	 * The payment a refund is for: the one paid (WooCommerce's transaction id), else the attempt the shopper
	 * was last sent to, for an order on hold after a partial approval.
	 *
	 * @param WC_Order $order The order.
	 * @return string
	 */
	private static function paid_reference( $order ) {
		$paid = (string) $order->get_transaction_id();
		return '' !== $paid ? $paid : (string) $order->get_meta( self::META_CURRENT_REFERENCE );
	}

	/**
	 * What to tell the merchant when MPS did not take a refund.
	 *
	 * @param Exception $e The refusal, or no answer.
	 * @return string
	 */
	private static function refund_refusal( Exception $e ) {
		switch ( $e->getCode() ) {
			case 400:
				$said = $e instanceof Mps_Payments_Api_Exception ? implode( ' ', $e->get_errors() ) : '';
				/* translators: %s: what MPS said was wrong, e.g. the amount is more than is left */
				return sprintf( __( 'MPS refused the refund: %s', 'mps-payments' ), '' !== $said ? $said : $e->getMessage() );
			case 401:
				return __( 'MPS refused the API key. Check it under WooCommerce › Settings › Payments › MPS Payments.', 'mps-payments' );
			case 403:
				return __( 'This MPS key may not make refunds: it does not hold the payments:refund scope. A live key holds it only when MPS granted it to that key, so ask MPS to grant it. A test key always holds it.', 'mps-payments' );
			case 404:
				return __( 'MPS does not know this order\'s payment for the configured key. A test key refunds only test payments, and a live key only live ones.', 'mps-payments' );
			case 409:
				return __( 'MPS cannot refund this payment right now: it is not paid yet, or the same refund is still being processed. Please try again in a moment.', 'mps-payments' );
		}

		return __( 'MPS did not answer. The refund may have been made: check the payment in MPS before trying again. Trying again with the same amount does not refund twice.', 'mps-payments' );
	}

	/**
	 * The shopper is back from the hosted checkout. Which URL they came back on is a hint for what to
	 * show them, never proof: the order is settled from the payment as MPS reads it.
	 */
	public function handle_return() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the order key is the credential here.
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$result   = isset( $_GET['result'] ) ? sanitize_key( $_GET['result'] ) : '';
		// phpcs:enable

		$order = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order || ! hash_equals( $order->get_order_key(), $key ) ) {
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}

		$state = $this->settle( $order, (string) $order->get_meta( self::META_CURRENT_REFERENCE ) );
		$this->debug( sprintf( 'Shopper returned for order %d on the %s URL; payment state %s', $order->get_id(), $result, $state ) );

		if ( 'executed' === $state || ( 'pending' === $state && 'success' === $result ) ) {
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
		}

		wc_add_notice( __( 'Your payment was not completed. Please try again or choose another payment method.', 'mps-payments' ), 'error' );
		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/**
	 * MPS's notification that a payment arrived, or that a refund was paid out, failed or was canceled.
	 * Answered 2xx whenever it was understood, including for a payment or refund this shop does not know,
	 * so MPS does not keep retrying something we will never act on.
	 */
	public function handle_webhook() {
		$body = file_get_contents( 'php://input' );

		$secret    = $this->get_option( 'webhook_secret' );
		$timestamp = isset( $_SERVER['HTTP_X_MPS_TIMESTAMP'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_MPS_TIMESTAMP'] ) ) : '';
		$signature = isset( $_SERVER['HTTP_X_MPS_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_MPS_SIGNATURE'] ) ) : '';

		switch ( self::webhook_verdict( $secret, (string) $body, $timestamp, $signature, time() ) ) {
			case 'ignore':
				$this->debug( 'Webhook ignored: no signing secret configured' );
				status_header( 200 );
				exit;
			case 'refuse':
				$this->debug( 'Webhook refused: signature or timestamp does not match', 'warning' );
				status_header( 401 );
				exit;
		}

		$event = json_decode( (string) $body, true );

		$this->handle_event(
			is_array( $event ) ? $event : array(),
			isset( $_SERVER['HTTP_X_MPS_DELIVERY_ID'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_MPS_DELIVERY_ID'] ) ) : '-'
		);

		status_header( 200 );
		exit;
	}

	/**
	 * Acts on a notification whose signature was valid. A refund event moves the WooCommerce refund it
	 * names; anything else (payment.received) is only a prompt to read the payment.
	 *
	 * @param array  $event       The decoded body.
	 * @param string $delivery_id X-Mps-Delivery-Id, for the log.
	 */
	public function handle_event( array $event, $delivery_id = '-' ) {
		$type      = isset( $event['eventType'] ) ? (string) $event['eventType'] : '';
		$reference = isset( $event['paymentReference'] ) ? (string) $event['paymentReference'] : '';
		$order     = '' === $reference ? null : self::find_order( $reference );

		$this->debug(
			sprintf(
				'Webhook %s %s for payment %s (signature valid): %s',
				$delivery_id,
				'' === $type ? '-' : $type,
				'' === $reference ? '-' : $reference,
				$order ? 'order ' . $order->get_id() : 'no order of this shop'
			)
		);

		if ( ! $order ) {
			return;
		}

		if ( 0 === strpos( $type, 'refund.' ) ) {
			$this->settle_refund( $order, substr( $type, strlen( 'refund.' ) ), $event );
			return;
		}

		// The notification only says "look"; what the payment is comes from reading it.
		$this->settle( $order, $reference );
	}

	/**
	 * A refund's outcome. Paid out: noted once. Failed or canceled: the money never left, so the WooCommerce
	 * refund is deleted as the order screen deletes one, and the amount can be refunded again. Repeats,
	 * and refunds this shop did not make, change nothing.
	 *
	 * The MPS refund id stays in the order's list, so the next refund's idempotency key is a new one.
	 *
	 * @param WC_Order $order   The order of the refunded payment.
	 * @param string   $outcome refunded, failed or canceled.
	 * @param array    $event   The notification: refundId and amount (cents) are read.
	 */
	private function settle_refund( $order, $outcome, array $event ) {
		$refund_id = isset( $event['refundId'] ) ? (string) $event['refundId'] : '';
		$wc_refund = '' === $refund_id ? null : self::find_refund( $order, $refund_id );

		if ( ! $wc_refund || ! in_array( $outcome, array( 'refunded', 'failed', 'canceled' ), true ) ) {
			$this->debug( sprintf( 'Refund %s %s: no refund of order %d to change', '' === $refund_id ? '-' : $refund_id, $outcome, $order->get_id() ) );
			return;
		}

		$amount = wc_price( ( isset( $event['amount'] ) ? (int) $event['amount'] : 0 ) / 100 );

		if ( 'refunded' === $outcome ) {
			if ( 'refunded' === $wc_refund->get_meta( self::META_REFUND_STATUS ) ) {
				return;
			}
			$wc_refund->update_meta_data( self::META_REFUND_STATUS, 'refunded' );
			$wc_refund->save();
			/* translators: 1: the MPS refund id, 2: the amount */
			$order->add_order_note( sprintf( __( 'MPS refund %1$s of %2$s was paid out.', 'mps-payments' ), $refund_id, $amount ) );
			$this->debug( sprintf( 'Refund %s of order %d paid out', $refund_id, $order->get_id() ) );
			return;
		}

		$wc_refund_id  = $wc_refund->get_id();
		$status_before = (string) $wc_refund->get_meta( self::META_ORDER_STATUS );

		// What WooCommerce's own Delete refund does (WC_AJAX::delete_refund); it restocks nothing.
		$wc_refund->delete( true );
		/**
		 * WooCommerce's own hook, fired as WC_AJAX::delete_refund fires it, so caches of the order's refunds are dropped.
		 *
		 * @param int $refund_id The WooCommerce refund deleted.
		 * @param int $order_id  Its order.
		 *
		 * @since 0.3.0
		 */
		do_action( 'woocommerce_refund_deleted', $wc_refund_id, $order->get_id() );

		$order->add_order_note(
			'failed' === $outcome
				/* translators: 1: the MPS refund id, 2: the amount, 3: the WooCommerce refund number */
				? sprintf( __( 'MPS refund %1$s of %2$s was not paid out (failed); the WooCommerce refund #%3$d is removed and the amount can be refunded again.', 'mps-payments' ), $refund_id, $amount, $wc_refund_id )
				/* translators: 1: the MPS refund id, 2: the amount, 3: the WooCommerce refund number */
				: sprintf( __( 'MPS refund %1$s of %2$s was canceled; the WooCommerce refund #%3$d is removed and the amount can be refunded again.', 'mps-payments' ), $refund_id, $amount, $wc_refund_id )
		);

		// Deleting a refund does not undo the Refunded status a full refund set; this refund's undoing does.
		if ( $order->has_status( 'refunded' ) ) {
			$order->update_status( '' !== $status_before ? $status_before : 'processing' );
		}

		$order->save();
		$this->debug( sprintf( 'Refund %s of order %d %s: WooCommerce refund #%d deleted', $refund_id, $order->get_id(), $outcome, $wc_refund_id ) );
	}

	/**
	 * The WooCommerce refund made for an MPS refund.
	 *
	 * @param WC_Order $order     The order.
	 * @param string   $refund_id The MPS refund id.
	 * @return WC_Order_Refund|null
	 */
	private static function find_refund( $order, $refund_id ) {
		foreach ( $order->get_refunds() as $wc_refund ) {
			if ( $wc_refund->get_meta( self::META_REFUND_ID ) === $refund_id ) {
				return $wc_refund;
			}
		}

		return null;
	}

	/**
	 * A scheduled re-read. Reschedules itself while the payment is still open.
	 *
	 * @param int $order_id The order.
	 * @param int $attempt  Which of CHECK_DELAYS this was.
	 */
	public function run_check( $order_id, $attempt ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->is_paid() ) {
			return;
		}

		$state = $this->settle( $order, (string) $order->get_meta( self::META_CURRENT_REFERENCE ) );

		if ( 'pending' === $state ) {
			self::schedule_check( $order_id, $attempt + 1 );
		}
	}

	/**
	 * Reads one payment and moves the order accordingly.
	 *
	 * @param WC_Order $order     The order.
	 * @param string   $reference The MPS payment reference, one of this order's attempts.
	 * @return string The state read: executed, failed, abandoned, or pending (also for anything unknown).
	 */
	private function settle( $order, $reference ) {
		if ( $order->is_paid() ) {
			return 'executed';
		}

		if ( '' === $reference ) {
			return 'pending';
		}

		try {
			$payment = $this->api()->get_payment( $reference );
		} catch ( Exception $e ) {
			return 'pending';
		}

		$state = isset( $payment['state'] ) ? (string) $payment['state'] : '';

		// Anything this plugin does not recognise is treated as not paid yet — never as paid.
		if ( ! in_array( $state, array( 'executed', 'failed', 'abandoned' ), true ) ) {
			return 'pending';
		}

		$noted = $order->get_meta( self::META_LAST_STATE ) === $reference . ':' . $state;
		$order->update_meta_data( self::META_LAST_STATE, $reference . ':' . $state );

		if ( 'executed' === $state ) {
			$approved = isset( $payment['amount'] ) ? (int) $payment['amount'] : 0;
			$expected = (int) round( (float) $order->get_total() * 100 );

			// A partial approval is only visible in the amount; the shortfall must not ship as paid.
			if ( $approved < $expected ) {
				if ( ! $noted ) {
					$order->update_status(
						'on-hold',
						/* translators: 1: approved amount, 2: order total, 3: MPS payment reference */
						sprintf( __( 'MPS approved %1$s of %2$s (payment %3$s). Check before shipping.', 'mps-payments' ), wc_price( $approved / 100 ), wc_price( $expected / 100 ), $reference )
					);
				}
				$order->save();
				return 'executed';
			}

			$order->payment_complete( $reference );
			/* translators: %s: the MPS payment reference */
			$order->add_order_note( sprintf( __( 'MPS payment %s paid.', 'mps-payments' ), $reference ) );
			return 'executed';
		}

		if ( ! $noted && $order->has_status( array( 'pending', 'failed' ) ) ) {
			if ( 'failed' === $state ) {
				/* translators: %s: the MPS payment reference */
				$order->update_status( 'failed', sprintf( __( 'MPS payment %s failed.', 'mps-payments' ), $reference ) );
			} else {
				/* translators: %s: the MPS payment reference */
				$order->add_order_note( sprintf( __( 'The shopper left MPS payment %s without paying.', 'mps-payments' ), $reference ) );
			}
		}

		$order->save();
		return $state;
	}

	/**
	 * Where MPS sends the shopper back to: the return handler, with the order key as credential.
	 *
	 * @param WC_Order $order  The order.
	 * @param string   $result success or failure, a hint for what to show.
	 * @return string
	 */
	private function return_url( $order, $result ) {
		return add_query_arg(
			array(
				'order_id' => $order->get_id(),
				'key'      => $order->get_order_key(),
				'result'   => $result,
			),
			WC()->api_request_url( 'mps_payments_return' )
		);
	}

	/**
	 * Writes to the mps-payments log when Debug log is on.
	 *
	 * @param string $message What happened.
	 * @param string $level   A PSR-3 level.
	 */
	private function debug( $message, $level = 'info' ) {
		if ( 'yes' === $this->get_option( 'debug' ) ) {
			wc_get_logger()->log( $level, $message, array( 'source' => 'mps-payments' ) );
		}
	}

	/**
	 * A client for the configured key and gateway.
	 *
	 * @return Mps_Payments_Api
	 */
	private function api() {
		$gateway_url = defined( 'MPS_PAYMENTS_GATEWAY_URL' ) ? MPS_PAYMENTS_GATEWAY_URL : $this->get_option( 'gateway_url', self::DEFAULT_GATEWAY_URL );
		$debug       = 'yes' === $this->get_option( 'debug' );

		return new Mps_Payments_Api(
			'' === $gateway_url ? self::DEFAULT_GATEWAY_URL : $gateway_url,
			$this->get_option( 'api_key' ),
			$debug ? static function ( $message, $level ) {
				wc_get_logger()->log( $level, $message, array( 'source' => 'mps-payments' ) );
			} : null
		);
	}

	/**
	 * The order an MPS payment was opened for, by any of its attempts. Orders only: a WooCommerce refund
	 * carries its order's meta, and without a type wc_get_orders returns refunds too, newest first.
	 *
	 * @param string $reference The MPS payment reference.
	 * @return WC_Order|null
	 */
	public static function find_order( $reference ) {
		$orders = wc_get_orders(
			array(
				'type'       => 'shop_order',
				'limit'      => 1,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => self::META_REFERENCE,
						'value' => $reference,
					),
				),
			)
		);

		if ( empty( $orders ) ) {
			return null;
		}

		// Should a refund still come back, the order is its parent.
		if ( $orders[0] instanceof WC_Order_Refund ) {
			$order = wc_get_order( $orders[0]->get_parent_id() );
			return $order ? $order : null;
		}

		return $orders[0];
	}

	/**
	 * Whether MPS could call this URL back. MPS refuses a callback that is not HTTPS on a publicly
	 * routable host — and refuses the whole payment with it — so a shop on localhost or a private
	 * address sends none and is settled by the return and the scheduled checks instead.
	 *
	 * @param string $url The webhook URL.
	 */
	public static function can_receive_webhooks( $url ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || 'https' !== $parts['scheme'] || empty( $parts['host'] ) ) {
			return false;
		}

		$host = strtolower( trim( $parts['host'], '[]' ) );

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}

		if ( false === strpos( $host, '.' ) ) {
			return false;
		}

		foreach ( array( '.localhost', '.local', '.test', '.internal', '.lan' ) as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * What to do with a notification. Without a signing secret it is ignored: MPS sends none for a key
	 * without one, so an unsigned call did not come from MPS, and the order is settled by the return and
	 * the scheduled checks. With a secret, only a valid signature goes on to read the payment.
	 *
	 * @param string $secret    The configured signing secret, empty when none.
	 * @param string $body      The raw request body, exactly as received.
	 * @param string $timestamp X-Mps-Timestamp, Unix seconds.
	 * @param string $signature X-Mps-Signature.
	 * @param int    $now       The current Unix time.
	 * @return string ignore (answer 200, do nothing), refuse (401) or settle (read the payment).
	 */
	public static function webhook_verdict( $secret, $body, $timestamp, $signature, $now ) {
		if ( '' === $secret ) {
			return 'ignore';
		}

		return self::is_valid_signature( $secret, $body, $timestamp, $signature, $now ) ? 'settle' : 'refuse';
	}

	/**
	 * Checks X-Mps-Signature: HMAC-SHA256 over "{timestamp}.{raw body}", hex, prefixed v1=. During a
	 * secret rotation the header carries two comma-separated signatures; either one matching is valid.
	 *
	 * @param string $secret    The signing secret.
	 * @param string $body      The raw request body, exactly as received.
	 * @param string $timestamp X-Mps-Timestamp, Unix seconds.
	 * @param string $header    X-Mps-Signature.
	 * @param int    $now       The current Unix time.
	 */
	public static function is_valid_signature( $secret, $body, $timestamp, $header, $now ) {
		if ( ! ctype_digit( $timestamp ) || abs( $now - (int) $timestamp ) > 300 ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );

		foreach ( explode( ',', $header ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( 0 === strpos( $candidate, 'v1=' ) && hash_equals( $expected, substr( $candidate, 3 ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Queues the next re-read, if there is one left.
	 *
	 * @param int $order_id The order.
	 * @param int $attempt  Index into CHECK_DELAYS.
	 */
	public static function schedule_check( $order_id, $attempt ) {
		if ( $attempt >= count( self::CHECK_DELAYS ) || ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		// Each check is queued by the one before it, so wait only the gap since that one: the delays are from opening.
		$after = self::CHECK_DELAYS[ $attempt ] - ( $attempt > 0 ? self::CHECK_DELAYS[ $attempt - 1 ] : 0 );

		as_schedule_single_action( time() + $after, self::CHECK_HOOK, array( $order_id, $attempt ), 'mps-payments' );
	}
}
