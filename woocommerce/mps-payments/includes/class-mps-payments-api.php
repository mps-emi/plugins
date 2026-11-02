<?php
/**
 * The MPS payments gateway, as this plugin uses it: create an internet payment, read it back, refund it.
 *
 * @package MpsPayments
 */

defined( 'ABSPATH' ) || exit;

/**
 * A small client for the gateway's published internet-payment surface.
 *
 * Everything else about the merchant — which wallet, which shop name the shopper sees — comes from the
 * API key, never from a request, so there is nothing here to configure beyond it.
 */
class Mps_Payments_Api {

	/**
	 * What an API key starts with: mps_live_ or mps_test_, then its key id and its secret.
	 */
	const TEST_API_KEY_PREFIX = 'mps_test_';

	/**
	 * A payment reference no payment has, read to prove an API key: MPS answers 404 to a key it accepts.
	 */
	const CREDENTIAL_CHECK_REFERENCE = 'mps-credential-check';

	/**
	 * The gateway, without a trailing slash.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * The API key, sent as the bearer on every call.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Receives ( message, level ), or null when nothing is logged.
	 *
	 * @var callable|null
	 */
	private $log;

	/**
	 * A client for one API key on one gateway.
	 *
	 * @param string        $base_url The gateway, e.g. https://gateway.mps-emi.com.
	 * @param string        $api_key  The API key (a test key's starts with mps_test_).
	 * @param callable|null $log      Receives ( message, level ) to log, or null for none.
	 */
	public function __construct( $base_url, $api_key, $log = null ) {
		$this->base_url = untrailingslashit( $base_url );
		$this->api_key  = $api_key;
		$this->log      = $log;
	}

	/**
	 * Opens a payment on the hosted checkout.
	 *
	 * @param array  $body            amount (cents), reference, successUrl, failureUrl and optionally callbackUrl.
	 * @param string $idempotency_key The same key, with the same body, for a resend of the same attempt, so it never opens a second payment.
	 * @return array paymentReference and checkoutUrl.
	 * @throws Exception When the gateway refuses or cannot be reached; a refusal is an Mps_Payments_Api_Exception.
	 */
	public function create_payment( array $body, $idempotency_key ) {
		return $this->call( 'POST', '/api/payments/internet/create', $body, array( 'Idempotency-Key' => $idempotency_key ) );
	}

	/**
	 * Reads a payment back: the only authority on whether it was paid.
	 *
	 * @param string $payment_reference As returned by create.
	 * @return array paymentReference, state, amount, currency and reference.
	 * @throws Exception When the gateway refuses or cannot be reached.
	 */
	public function get_payment( $payment_reference ) {
		return $this->call( 'GET', '/api/payments/internet/' . rawurlencode( $payment_reference ) );
	}

	/**
	 * Refunds part or all of a paid payment: POST /api/payments/{paymentReference}/refunds. A live key may do
	 * this only when MPS granted it payments:refund; a test key always may. The payment is named by the path
	 * only; the merchant's own reason stays in WooCommerce, MPS is told requested_by_customer.
	 *
	 * @param string   $payment_reference The payment's reference, as returned by create.
	 * @param int|null $amount            Cents to refund, or null for everything still refundable.
	 * @param string   $idempotency_key   The same key for a resend of the same refund, so it is never refunded twice.
	 * @return array refundId, paymentReference, status, amount, currency, failureReason, cancelableUntil.
	 * @throws Exception When the gateway refuses or cannot be reached; a refusal is an Mps_Payments_Api_Exception.
	 */
	public function refund_payment( $payment_reference, $amount, $idempotency_key ) {
		$body = null === $amount ? array() : array( 'amount' => $amount );

		$body['reason'] = 'requested_by_customer';

		return $this->call( 'POST', '/api/payments/' . rawurlencode( $payment_reference ) . '/refunds', $body, array( 'Idempotency-Key' => $idempotency_key ) );
	}

	/**
	 * Proves the API key when it is saved: a payment that does not exist is read with it, and 404 means MPS
	 * accepted the key.
	 *
	 * @throws Exception When the key is refused.
	 */
	public function verify_credentials() {
		try {
			$this->get_payment( self::CREDENTIAL_CHECK_REFERENCE );
		} catch ( Exception $e ) {
			if ( 404 === $e->getCode() ) {
				return;
			}
			if ( in_array( $e->getCode(), array( 401, 403 ), true ) ) {
				throw new Exception( sprintf( 'MPS refused the API key (%d)', $e->getCode() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped where shown (WC_Admin_Settings, WC_Logger).
			}
			throw $e;
		}
	}

	/**
	 * Whether an API key is a test key: it starts with mps_test_.
	 *
	 * @param string $api_key The API key.
	 * @return bool
	 */
	public static function is_test_key( $api_key ) {
		return 0 === strpos( $api_key, self::TEST_API_KEY_PREFIX );
	}

	/**
	 * One authenticated call to the gateway.
	 *
	 * @param string     $method  HTTP method.
	 * @param string     $path    Path under the gateway.
	 * @param array|null $body    JSON body, if any.
	 * @param array      $headers Further headers, e.g. Idempotency-Key.
	 * @return array The response's data.
	 * @throws Exception When the gateway cannot be reached.
	 * @throws Mps_Payments_Api_Exception On anything other than 2xx.
	 */
	private function call( $method, $path, $body = null, array $headers = array() ) {
		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Accept'        => 'application/json',
			) + $headers,
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$started  = microtime( true );
		$response = wp_remote_request( $this->base_url . $path, $args );
		$elapsed  = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $response ) ) {
			$this->log( sprintf( '%s %s failed after %d ms: %s', $method, $path, $elapsed, $response->get_error_message() ), 'error' );
			throw new Exception( 'MPS gateway unreachable: ' . $response->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped where shown (WC_Admin_Settings, WC_Logger).
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 ) {
			$this->log( sprintf( '%s %s answered %d in %d ms: %s', $method, $path, $status, $elapsed, wp_remote_retrieve_body( $response ) ), 'warning' );
			throw new Mps_Payments_Api_Exception( sprintf( 'MPS gateway answered %d on %s %s', $status, $method, $path ), $status, self::errors( $decoded ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped where shown (WC_Admin_Settings, WC_Logger, the order screen).
		}

		$data = isset( $decoded['data'] ) && is_array( $decoded['data'] ) ? $decoded['data'] : array();

		$this->log( sprintf( '%s %s answered %d in %d ms%s', $method, $path, $status, $elapsed, self::describe( $body, $headers, $data ) ), 'info' );

		return $data;
	}

	/**
	 * What a refusal said was wrong: every message of a validation answer's errors, else its detail or title.
	 *
	 * @param mixed $decoded The decoded response body.
	 * @return string[]
	 */
	private static function errors( $decoded ) {
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$messages = array();
		if ( isset( $decoded['errors'] ) && is_array( $decoded['errors'] ) ) {
			foreach ( $decoded['errors'] as $field_messages ) {
				foreach ( (array) $field_messages as $message ) {
					if ( is_string( $message ) && '' !== $message ) {
						$messages[] = $message;
					}
				}
			}
		}

		if ( empty( $messages ) ) {
			foreach ( array( 'detail', 'title' ) as $field ) {
				if ( isset( $decoded[ $field ] ) && is_string( $decoded[ $field ] ) && '' !== $decoded[ $field ] ) {
					return array( $decoded[ $field ] );
				}
			}
		}

		return $messages;
	}

	/**
	 * What a successful call was about, for the log. Deliberately a whitelist: the API key, the secrets, checkoutUrl (which pays the payment) and the return URLs (which carry the order key) never
	 * appear.
	 *
	 * @param array|null $request  The request body.
	 * @param array      $headers  The further request headers.
	 * @param array      $response The response's data.
	 */
	private static function describe( $request, array $headers, array $response ) {
		$parts = array();

		if ( is_array( $request ) && isset( $request['reference'] ) ) {
			$parts[] = sprintf( 'amount %d, reference %s, webhook %s', $request['amount'], $request['reference'], isset( $request['callbackUrl'] ) ? 'yes' : 'no' );
		}

		if ( isset( $headers['Idempotency-Key'] ) ) {
			$parts[] = 'idempotency key ' . $headers['Idempotency-Key'];
		}

		foreach ( array( 'paymentReference', 'refundId', 'state', 'status', 'amount', 'currency', 'failureReason', 'cancelableUntil' ) as $field ) {
			if ( isset( $response[ $field ] ) && is_scalar( $response[ $field ] ) ) {
				$parts[] = $field . ' ' . $response[ $field ];
			}
		}

		return empty( $parts ) ? '' : ': ' . implode( ', ', $parts );
	}

	/**
	 * Hands a line to the logger, when there is one.
	 *
	 * @param string $message What happened.
	 * @param string $level   A PSR-3 level: info for a call that worked, warning for a refusal, error for no answer.
	 */
	private function log( $message, $level ) {
		if ( null !== $this->log ) {
			call_user_func( $this->log, $message, $level );
		}
	}
}
