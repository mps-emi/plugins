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

	/** Meta holding every MPS payment opened for an order, one row per checkout attempt. */
	const META_REFERENCE = '_mps_payment_reference';

	/** Meta holding the attempt the shopper was last sent to. */
	const META_CURRENT_REFERENCE = '_mps_payment_reference_current';

	/** Meta holding the last state read, so a repeat read adds no second order note. */
	const META_LAST_STATE = '_mps_payment_state';

	/** Scheduled re-reads after a payment is opened: covers a closed tab on a shop without webhooks. */
	const CHECK_HOOK = 'mps_payments_check';

	/** Seconds after opening at which a scheduled check runs; it stops at the first final state. */
	const CHECK_DELAYS = array( 600, 1800, 3600, 21600 );

	/**
	 * Loads the settings and hooks the return and webhook handlers.
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->has_fields         = false;
		$this->method_title       = __( 'MPS Payments', 'mps-payments' );
		$this->method_description = __( 'Payments on the checkout page MPS hosts. Card details never reach your shop.', 'mps-payments' );
		$this->supports           = array( 'products' );

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
				'description' => __( 'Paste the client ID, secret and webhook signing secret from the MPS platform (Online › Get started). A test key, whose client ID starts with test_, works for 24 hours and accepts only the MPS test cards: no card is charged and nothing is paid out.', 'mps-payments' )
					. '</p><p>' . __( 'This payment method sends the order amount, order number and your shop\'s return addresses to MPS (Mobile Payment Solutions) to open each payment.', 'mps-payments' ),
			),
			'client_id'      => array(
				'title' => __( 'Client ID', 'mps-payments' ),
				'type'  => 'text',
			),
			'client_secret'  => array(
				'title' => __( 'Client secret', 'mps-payments' ),
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
			&& '' !== $this->get_option( 'client_id' )
			&& '' !== $this->get_option( 'client_secret' )
			&& 'EUR' === get_woocommerce_currency();
	}

	/**
	 * Whether the configured key is a test key. A test key accepts only the MPS test cards, charges
	 * nothing and stops working after 24 hours, which is worth telling the merchant when it is saved.
	 */
	public function is_test_key() {
		return 0 === strpos( $this->get_option( 'client_id' ), 'test_' );
	}

	/**
	 * Saves the settings, then proves the credentials with a real token request, so a wrong key is
	 * found now rather than by the first shopper.
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();

		if ( '' === $this->get_option( 'client_id' ) || '' === $this->get_option( 'client_secret' ) ) {
			return $saved;
		}

		try {
			$this->api()->verify_credentials();
			WC_Admin_Settings::add_message(
				$this->is_test_key()
					? __( 'Connected to MPS with a test key. It works for 24 hours and accepts only the MPS test cards; no card is charged.', 'mps-payments' )
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
			$payment = $this->api()->create_payment( $body );
		} catch ( Exception $e ) {
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
	 * MPS's notification that a payment arrived. Answered 2xx whenever it was understood, including
	 * for a payment this shop does not know, so MPS does not keep retrying something we will never act on.
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

		$event     = json_decode( (string) $body, true );
		$reference = is_array( $event ) && isset( $event['paymentReference'] ) ? (string) $event['paymentReference'] : '';
		$order     = '' === $reference ? null : self::find_order( $reference );

		$this->debug(
			sprintf(
				'Webhook %s for payment %s (signature valid): %s',
				isset( $_SERVER['HTTP_X_MPS_DELIVERY_ID'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_MPS_DELIVERY_ID'] ) ) : '-',
				'' === $reference ? '-' : $reference,
				$order ? 'order ' . $order->get_id() : 'no order of this shop'
			)
		);

		if ( $order ) {
			// The notification only says "look"; what the payment is comes from reading it.
			$this->settle( $order, $reference );
		}

		status_header( 200 );
		exit;
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
			$this->get_option( 'client_id' ),
			$this->get_option( 'client_secret' ),
			$debug ? static function ( $message, $level ) {
				wc_get_logger()->log( $level, $message, array( 'source' => 'mps-payments' ) );
			} : null
		);
	}

	/**
	 * The order an MPS payment was opened for, by any of its attempts.
	 *
	 * @param string $reference The MPS payment reference.
	 * @return WC_Order|null
	 */
	public static function find_order( $reference ) {
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => self::META_REFERENCE,
						'value' => $reference,
					),
				),
			)
		);

		return empty( $orders ) ? null : $orders[0];
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

		as_schedule_single_action( time() + self::CHECK_DELAYS[ $attempt ], self::CHECK_HOOK, array( $order_id, $attempt ), 'mps-payments' );
	}
}
