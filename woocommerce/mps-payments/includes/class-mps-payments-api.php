<?php
/**
 * The MPS payments gateway, as this plugin uses it: a token, create an internet payment, read it back.
 *
 * @package MpsPayments
 */

defined( 'ABSPATH' ) || exit;

/**
 * A small client for the gateway's published internet-payment surface.
 *
 * Everything else about the merchant — which wallet, which shop name the shopper sees — comes from the
 * credentials, never from a request, so there is nothing here to configure beyond them.
 */
class Mps_Payments_Api {

	const SCOPES = 'internet:create internet:read';

	/**
	 * The gateway, without a trailing slash.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * The API client id.
	 *
	 * @var string
	 */
	private $client_id;

	/**
	 * Its secret.
	 *
	 * @var string
	 */
	private $client_secret;

	/**
	 * Receives ( message, level ), or null when nothing is logged.
	 *
	 * @var callable|null
	 */
	private $log;

	/**
	 * A client for one key on one gateway.
	 *
	 * @param string        $base_url      The gateway, e.g. https://gateway.mps-emi.com.
	 * @param string        $client_id     The API client id (a test key's starts with test_).
	 * @param string        $client_secret Its secret.
	 * @param callable|null $log           Receives ( message, level ) to log, or null for none.
	 */
	public function __construct( $base_url, $client_id, $client_secret, $log = null ) {
		$this->base_url      = untrailingslashit( $base_url );
		$this->client_id     = $client_id;
		$this->client_secret = $client_secret;
		$this->log           = $log;
	}

	/**
	 * Opens a payment on the hosted checkout.
	 *
	 * @param array $body amount (cents), reference, successUrl, failureUrl and optionally callbackUrl.
	 * @return array paymentReference and checkoutUrl.
	 * @throws Exception When the gateway refuses or cannot be reached.
	 */
	public function create_payment( array $body ) {
		return $this->call( 'POST', '/api/payments/internet/create', $body );
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
	 * Fetches a fresh token, bypassing the cache — proves the credentials when they are saved.
	 *
	 * @throws Exception When the credentials are refused.
	 */
	public function verify_credentials() {
		$this->forget_token();
		$this->token();
	}

	/**
	 * One authenticated call to the gateway.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path under the gateway.
	 * @param array|null $body   JSON body, if any.
	 * @param bool       $retry  Whether a 401 may fetch a new token and try once more.
	 * @return array The response's data.
	 * @throws Exception On anything other than 2xx.
	 */
	private function call( $method, $path, $body = null, $retry = true ) {
		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->token(),
				'Accept'        => 'application/json',
			),
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

		$status = (int) wp_remote_retrieve_response_code( $response );

		// A token can expire between being cached and being used; one new token, one retry.
		if ( 401 === $status && $retry ) {
			$this->forget_token();
			return $this->call( $method, $path, $body, false );
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 ) {
			$this->log( sprintf( '%s %s answered %d in %d ms: %s', $method, $path, $status, $elapsed, wp_remote_retrieve_body( $response ) ), 'warning' );
			throw new Exception( sprintf( 'MPS gateway answered %d on %s %s', $status, $method, $path ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped where shown (WC_Admin_Settings, WC_Logger).
		}

		$data = isset( $decoded['data'] ) && is_array( $decoded['data'] ) ? $decoded['data'] : array();

		$this->log( sprintf( '%s %s answered %d in %d ms%s', $method, $path, $status, $elapsed, self::describe( $body, $data ) ), 'info' );

		return $data;
	}

	/**
	 * A token for these credentials, from the cache while it lasts. The token endpoint is rate limited
	 * far more tightly than the payment endpoints; one token serves every payment for 15 minutes.
	 *
	 * @return string
	 * @throws Exception When the credentials are refused.
	 */
	private function token() {
		$cached = get_transient( $this->token_key() );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$response = wp_remote_post(
			$this->base_url . '/connect/token',
			array(
				'timeout' => 20,
				'body'    => array(
					'grant_type'    => 'client_credentials',
					'client_id'     => $this->client_id,
					'client_secret' => $this->client_secret,
					'scope'         => self::SCOPES,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log( 'Token request failed: ' . $response->get_error_message(), 'error' );
			throw new Exception( 'MPS gateway unreachable: ' . $response->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped where shown (WC_Admin_Settings, WC_Logger).
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status || empty( $decoded['access_token'] ) ) {
			$this->log( sprintf( 'Token request for %s answered %d: %s', $this->client_id, $status, wp_remote_retrieve_body( $response ) ), 'warning' );
			throw new Exception( sprintf( 'MPS refused the credentials (%d)', $status ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped where shown (WC_Admin_Settings, WC_Logger).
		}

		$lifetime = isset( $decoded['expires_in'] ) ? (int) $decoded['expires_in'] : 900;
		$this->log( sprintf( 'New token for %s, valid %d s', $this->client_id, $lifetime ), 'info' );

		// Expire a minute early so a token is never handed out in its last seconds.
		set_transient( $this->token_key(), $decoded['access_token'], max( 60, $lifetime - 60 ) );

		return $decoded['access_token'];
	}

	/**
	 * Drops the cached token, so the next call fetches a new one.
	 */
	private function forget_token() {
		delete_transient( $this->token_key() );
	}

	/**
	 * Keyed by gateway, client and secret, so changing any of them never reuses a token for the old ones.
	 */
	private function token_key() {
		return 'mps_payments_token_' . md5( $this->base_url . '|' . $this->client_id . '|' . $this->client_secret );
	}

	/**
	 * What a successful call was about, for the log. Deliberately a whitelist: the token, the secrets,
	 * checkoutUrl (which pays the payment) and the return URLs (which carry the order key) never appear.
	 *
	 * @param array|null $request  The request body.
	 * @param array      $response The response's data.
	 */
	private static function describe( $request, array $response ) {
		$parts = array();

		if ( is_array( $request ) ) {
			$parts[] = sprintf( 'amount %d, reference %s, webhook %s', $request['amount'], $request['reference'], isset( $request['callbackUrl'] ) ? 'yes' : 'no' );
		}

		foreach ( array( 'paymentReference', 'state', 'amount', 'currency' ) as $field ) {
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
