<?php
/**
 * The plugin's pure rules, tested without WordPress: `php tests/run.php` (build.sh runs it in a
 * php:cli container). Exits non-zero on the first failure.
 *
 * @package MpsPayments
 */

define( 'ABSPATH', __DIR__ );

// The few WordPress pieces the code under test touches.
class WC_Payment_Gateway {
	public $id;
	public $has_fields;
	public $method_title;
	public $method_description;
	public $supports = array( 'products' );
	public $form_fields;
	public $title;
	public $description;
	public $enabled;
	public $settings = array();

	public function init_settings() {}

	public function get_option( $key, $empty_value = '' ) {
		return isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : $empty_value;
	}

	public function can_refund_order( $order ) {
		return $order && in_array( 'refunds', $this->supports, true );
	}

	public function is_available() {
		return 'yes' === $this->enabled;
	}
}

function get_woocommerce_currency() {
	return 'EUR';
}

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function __( $text, $domain = 'default' ) {
	return $text;
}

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {}

$actions = array();

function do_action( $hook, ...$args ) {
	global $actions;
	$actions[] = array_merge( array( $hook ), $args );
}

// The orders wc_get_orders finds by payment reference.
// As WooCommerce does: without a 'type', refunds are orders too, and the newest match (a refund, which
// carries its order's meta) comes first. $orders_query_ignores_type makes it hand a refund back regardless.
$orders_query_ignores_type = false;

function wc_get_orders( $query ) {
	global $orders, $orders_query_ignores_type;
	$key       = $query['meta_query'][0]['key'];
	$reference = $query['meta_query'][0]['value'];
	$refunds   = $orders_query_ignores_type || ! isset( $query['type'] ) || in_array( 'shop_order_refund', (array) $query['type'], true );
	$found     = array();
	foreach ( $orders as $order ) {
		if ( $refunds ) {
			foreach ( $order->get_refunds() as $refund ) {
				if ( in_array( $reference, $refund->get_meta( $key, false ), true ) ) {
					$found[] = $refund;
				}
			}
		}
	}
	foreach ( $orders as $order ) {
		if ( in_array( $reference, $order->get_meta( $key, false ), true ) ) {
			$found[] = $order;
		}
	}
	return array_slice( $found, 0, $query['limit'] );
}

// The stored options: the date formats the refund notes use, and whatever a test stores, e.g. the settings.
$wp_options = array();

function get_option( $key, $default_value = false ) {
	global $wp_options;
	return ( $wp_options + array( 'date_format' => 'Y-m-d', 'time_format' => 'H:i' ) )[ $key ] ?? $default_value;
}

function update_option( $key, $value ) {
	global $wp_options;
	$wp_options[ $key ] = $value;
	return true;
}

// The database, as far as the upgrade's clean-up queries it: every query is recorded.
class Fake_Wpdb {
	public $options = 'wp_options';
	public $queries = array();

	public function query( $sql ) {
		$this->queries[] = $sql;
		return 0;
	}
}

$wpdb = new Fake_Wpdb();

function wp_date( $format, $timestamp ) {
	return gmdate( $format, $timestamp );
}

function wc_price( $amount ) {
	return 'EUR ' . number_format( $amount, 2, '.', '' );
}

// An order and its refunds, as far as the payment and refund code reads and writes them.
class Fake_Order {
	public $id             = 1042;
	public $transaction_id = '';
	public $total          = '19.99';
	public $meta           = array();
	public $notes          = array();
	public $status         = 'processing';
	public $paid           = false;
	public $refunds        = array();

	public function get_id() {
		return $this->id;
	}

	public function get_status() {
		return $this->status;
	}

	public function has_status( $status ) {
		return in_array( $this->status, (array) $status, true );
	}

	public function update_status( $status, $note = '' ) {
		$this->status = $status;
		if ( '' !== $note ) {
			$this->notes[] = $note;
		}
	}

	public function is_paid() {
		return $this->paid;
	}

	public function get_refunds() {
		return array_values( array_filter( $this->refunds, static function ( $refund ) {
			return ! $refund->deleted;
		} ) );
	}

	public function get_total() {
		return $this->total;
	}

	public function get_order_number() {
		return (string) $this->id;
	}

	public function get_order_key() {
		return 'wc_order_abc';
	}

	public function get_transaction_id() {
		return $this->transaction_id;
	}

	public function get_meta( $key, $single = true ) {
		$values = isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : array();
		return $single ? ( empty( $values ) ? '' : end( $values ) ) : $values;
	}

	public function add_meta_data( $key, $value, $unique = false ) {
		$this->meta[ $key ][] = $value;
	}

	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = array( $value );
	}

	public function delete_meta_data( $key ) {
		unset( $this->meta[ $key ] );
	}

	public function add_order_note( $note ) {
		$this->notes[] = $note;
	}

	public function save() {}
}

class WC_Order_Refund extends Fake_Order {}

class Fake_Refund extends WC_Order_Refund {
	public $saved   = false;
	public $deleted = false;

	public function delete( $force_delete = false ) {
		$this->deleted = $force_delete;
		return true;
	}

	public function get_parent_id() {
		return 1042;
	}

	public function save() {
		$this->saved = true;
	}
}

$orders = array();

function wc_get_order( $order_id ) {
	global $orders;
	return isset( $orders[ $order_id ] ) ? $orders[ $order_id ] : false;
}

function wp_parse_url( $url ) {
	return parse_url( $url );
}

// What process_payment needs from WordPress and WooCommerce to build the create body.
class Fake_WC {
	public function api_request_url( $request ) {
		return 'http://shop.localhost/wc-api/' . $request . '/';
	}
}

function WC() {
	return new Fake_WC();
}

function add_query_arg( $args, $url ) {
	return $url . '?' . http_build_query( $args );
}

$notices = array();

function wc_add_notice( $message, $type = 'success' ) {
	global $notices;
	$notices[] = $message;
}

function wp_generate_uuid4() {
	return sprintf( '%04x%04x-%04x-4%03x-a%03x-%04x%04x%04x', mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xfff ), mt_rand( 0, 0xfff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ) );
}

// The HTTP functions the API client calls: every request is recorded in $requests and answered from
// $answers in turn, as array( status, body ).
$requests = array();
$answers  = array();

function mps_answer( $url, $args ) {
	global $requests, $answers;
	$requests[] = array( 'url' => $url ) + $args;
	$answer     = array_shift( $answers );
	if ( $answer instanceof WP_Error ) {
		return $answer;
	}
	list( $code, $body ) = $answer;
	return array(
		'code' => $code,
		'body' => $body,
	);
}

function wp_remote_request( $url, $args ) {
	return mps_answer( $url, $args );
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['code'];
}

function wp_remote_retrieve_body( $response ) {
	return $response['body'];
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function wp_json_encode( $data ) {
	return json_encode( $data );
}

function untrailingslashit( $url ) {
	return rtrim( $url, '/' );
}

require __DIR__ . '/../mps-payments/includes/class-mps-payments-gateway.php';
require __DIR__ . '/../mps-payments/includes/class-mps-payments-api.php';
require __DIR__ . '/../mps-payments/includes/class-mps-payments-api-exception.php';

$failures = 0;

function check( $name, $condition ) {
	global $failures;
	echo ( $condition ? 'PASS ' : 'FAIL ' ) . $name . PHP_EOL;
	if ( ! $condition ) {
		$failures++;
	}
}

// The worked example from the public docs (internet-payments.mdx, "A notification you can test against").
$secret    = 'whsec_EXAMPLE_not_a_real_secret_000000000000';
$timestamp = '1791892905';
$signature = 'v1=bbd5ffb9283e5095b326e21ccf9f102756bd36ffe30a8fba22e38cd984ae7b3a';
$body      = '{"eventType":"payment.received","paymentId":"98dcf1df-2e04-4684-84cd-d47ec8c23c80","paymentReference":"98dcf1df-2e04-4684-84cd-d47ec8c23c80","merchantReference":"order-1042","amount":100,"currency":"EUR","status":"completed","channel":"internet","valutaDate":"2026-10-13T12:01:42.658852Z","occurredAt":"2026-10-13T12:01:44.0678776Z"}';
$now       = 1791892905;

check( 'signature: the documented example is valid', Mps_Payments_Gateway::is_valid_signature( $secret, $body, $timestamp, $signature, $now ) );
check( 'signature: valid when it is the second of two during a rotation', Mps_Payments_Gateway::is_valid_signature( $secret, $body, $timestamp, 'v1=' . str_repeat( '0', 64 ) . ', ' . $signature, $now ) );
check( 'signature: a re-encoded body is refused', ! Mps_Payments_Gateway::is_valid_signature( $secret, $body . "\n", $timestamp, $signature, $now ) );
check( 'signature: a wrong secret is refused', ! Mps_Payments_Gateway::is_valid_signature( 'whsec_other', $body, $timestamp, $signature, $now ) );
check( 'signature: older than five minutes is refused', ! Mps_Payments_Gateway::is_valid_signature( $secret, $body, $timestamp, $signature, $now + 301 ) );
check( 'signature: five minutes old is accepted', Mps_Payments_Gateway::is_valid_signature( $secret, $body, $timestamp, $signature, $now + 300 ) );
check( 'signature: a non-numeric timestamp is refused', ! Mps_Payments_Gateway::is_valid_signature( $secret, $body, 'abc', $signature, $now ) );
check( 'signature: a missing header is refused', ! Mps_Payments_Gateway::is_valid_signature( $secret, $body, $timestamp, '', $now ) );
check( 'signature: an unprefixed value is refused', ! Mps_Payments_Gateway::is_valid_signature( $secret, $body, $timestamp, substr( $signature, 3 ), $now ) );

// What handle_webhook does with a notification: only "settle" goes on to read the payment from MPS.
check( 'webhook verdict: no secret configured is ignored, whatever the body', 'ignore' === Mps_Payments_Gateway::webhook_verdict( '', $body, $timestamp, $signature, $now ) );
check( 'webhook verdict: no secret and no headers is ignored', 'ignore' === Mps_Payments_Gateway::webhook_verdict( '', 'not json', '', '', $now ) );
check( 'webhook verdict: a bad signature is refused', 'refuse' === Mps_Payments_Gateway::webhook_verdict( $secret, $body, $timestamp, 'v1=' . str_repeat( '0', 64 ), $now ) );
check( 'webhook verdict: a missing signature is refused', 'refuse' === Mps_Payments_Gateway::webhook_verdict( $secret, $body, '', '', $now ) );
check( 'webhook verdict: a valid signature settles', 'settle' === Mps_Payments_Gateway::webhook_verdict( $secret, $body, $timestamp, $signature, $now ) );

check( 'webhook: public https host', Mps_Payments_Gateway::can_receive_webhooks( 'https://shop.example.com/?wc-api=mps_payments_webhook' ) );
check( 'webhook: public IP', Mps_Payments_Gateway::can_receive_webhooks( 'https://8.8.8.8/wc-api/mps_payments_webhook/' ) );
check( 'webhook: http is not sent', ! Mps_Payments_Gateway::can_receive_webhooks( 'http://shop.example.com/wc-api/mps_payments_webhook/' ) );
check( 'webhook: localhost is not sent', ! Mps_Payments_Gateway::can_receive_webhooks( 'https://localhost:8443/wc-api/mps_payments_webhook/' ) );
check( 'webhook: a .localhost name is not sent', ! Mps_Payments_Gateway::can_receive_webhooks( 'https://shop.localhost/wc-api/mps_payments_webhook/' ) );
check( 'webhook: a .test name is not sent', ! Mps_Payments_Gateway::can_receive_webhooks( 'https://shop.test/wc-api/mps_payments_webhook/' ) );
check( 'webhook: a private IP is not sent', ! Mps_Payments_Gateway::can_receive_webhooks( 'https://192.168.1.20/wc-api/mps_payments_webhook/' ) );
check( 'webhook: loopback is not sent', ! Mps_Payments_Gateway::can_receive_webhooks( 'https://127.0.0.1/wc-api/mps_payments_webhook/' ) );
check( 'webhook: IPv6 loopback is not sent', ! Mps_Payments_Gateway::can_receive_webhooks( 'https://[::1]/wc-api/mps_payments_webhook/' ) );

// The credentials: the API key is sent as the bearer on every call. There is nothing else to connect with.
$api_key   = 'mps_test_abcdef123456_c2VjcmV0X3dpdGhfdW5kZXJzY29yZXM';
$requests  = array();
$answers   = array( array( 201, '{"data":{"paymentReference":"p1","checkoutUrl":"https://pay"}}' ) );
$paid      = ( new Mps_Payments_Api( 'https://gateway.example.com/', $api_key ) )->create_payment( array( 'amount' => 100, 'reference' => '1042' ), 'k' );
check( 'api key: the payment is opened with the key as the bearer', 'Bearer ' . $api_key === $requests[0]['headers']['Authorization'] && 'https://gateway.example.com/api/payments/internet/create' === $requests[0]['url'] );
check( 'api key: no token is requested', 1 === count( $requests ) && 'p1' === $paid['paymentReference'] );

$requests = array();
$answers  = array( array( 401, '' ), array( 401, '' ) );
try {
	( new Mps_Payments_Api( 'https://gateway.example.com', $api_key ) )->get_payment( 'p1' );
	check( 'api key: a 401 is an error', false );
} catch ( Exception $e ) {
	check( 'api key: a 401 is not retried and asks for no token', 1 === count( $requests ) && 401 === $e->getCode() );
}

$requests = array();
$answers  = array( array( 404, '' ) );
try {
	( new Mps_Payments_Api( 'https://gateway.example.com', $api_key ) )->verify_credentials();
	check( 'api key: verified by a read MPS answers 404, without a token request', 1 === count( $requests ) && 'GET' === $requests[0]['method'] && 'Bearer ' . $api_key === $requests[0]['headers']['Authorization'] );
} catch ( Exception $e ) {
	check( 'api key: verified by a read MPS answers 404, without a token request', false );
}

foreach ( array( 401, 403 ) as $refused ) {
	$answers = array( array( $refused, '' ) );
	try {
		( new Mps_Payments_Api( 'https://gateway.example.com', $api_key ) )->verify_credentials();
		check( "api key: a $refused on the check is a refused key", false );
	} catch ( Exception $e ) {
		check( "api key: a $refused on the check is a refused key", false !== strpos( $e->getMessage(), (string) $refused ) );
	}
}

check( 'api client: has no token request left', ! method_exists( 'Mps_Payments_Api', 'token' ) && ! defined( 'Mps_Payments_Api::SCOPES' ) );

check( 'test key: an API key starting mps_test_', Mps_Payments_Api::is_test_key( 'mps_test_abcdef123456_secret' ) );
check( 'test key: not a live API key', ! Mps_Payments_Api::is_test_key( 'mps_live_abcdef123456_secret' ) );
check( 'test key: not an older client ID starting test_', ! Mps_Payments_Api::is_test_key( 'test_BE0000000000' ) );

// A shop is configured by its API key alone. One set up with a client ID and secret only is not, and says so.
$gateway           = new Mps_Payments_Gateway();
$gateway->enabled  = 'yes';
$gateway->settings = array( 'enabled' => 'yes', 'client_id' => 'BE0000000000', 'client_secret' => 'secret' );
check( 'not configured: a client ID and secret without an API key is not offered at checkout', ! $gateway->is_available() );
$gateway->settings = array( 'enabled' => 'yes', 'api_key' => $api_key );
check( 'configured: an API key is offered at checkout', $gateway->is_available() );

check( 'settings: no client ID or secret field', ! array_intersect( array( 'client', 'client_id', 'client_secret' ), array_keys( ( new Mps_Payments_Gateway() )->form_fields ) ) );

$message = Mps_Payments_Gateway::missing_api_key_message( array( 'enabled' => 'yes', 'client_id' => 'BE0000000000', 'client_secret' => 'secret' ) );
check( 'admin message: an enabled shop without an API key is asked for one', false !== strpos( $message, 'API key' ) && false !== strpos( $message, 'not offered at checkout' ) );
check( 'admin message: it says a client ID and secret no longer connect', false !== strpos( $message, 'client ID and secret' ) );
check( 'admin message: none with an API key', '' === Mps_Payments_Gateway::missing_api_key_message( array( 'enabled' => 'yes', 'api_key' => $api_key ) ) );
check( 'admin message: none for a shop that has not enabled the plugin', '' === Mps_Payments_Gateway::missing_api_key_message( array( 'enabled' => 'no' ) ) );
check( 'admin message: none before the settings were ever saved', '' === Mps_Payments_Gateway::missing_api_key_message( false ) );

// The upgrade: a client ID, its secret and the tokens bought with them are removed from the shop, once.
$wp_options = array(
	'woocommerce_mps_payments_settings' => array(
		'enabled'       => 'yes',
		'api_key'       => '',
		'client_id'     => 'BE0000000000',
		'client_secret' => 'secret',
		'debug'         => 'yes',
	),
);
$wpdb->queries = array();
Mps_Payments_Gateway::forget_client_credentials();
check( 'upgrade: the client ID and secret are deleted from the settings, the rest kept', array( 'enabled' => 'yes', 'api_key' => '', 'debug' => 'yes' ) === $wp_options['woocommerce_mps_payments_settings'] );
check( 'upgrade: the cached tokens are deleted, with their timeouts', 1 === count( $wpdb->queries ) && false !== strpos( $wpdb->queries[0], 'DELETE FROM wp_options' ) && false !== strpos( $wpdb->queries[0], '\\_transient\\_mps\\_payments\\_token\\_' ) && false !== strpos( $wpdb->queries[0], '\\_transient\\_timeout\\_mps\\_payments\\_token\\_' ) );
Mps_Payments_Gateway::forget_client_credentials();
check( 'upgrade: on a shop already cleaned, nothing is written or queried again', 1 === count( $wpdb->queries ) );

$wp_options = array();
Mps_Payments_Gateway::forget_client_credentials();
check( 'upgrade: a shop that never saved the settings gets none', ! isset( $wp_options['woocommerce_mps_payments_settings'] ) );

// Creating a payment: one Idempotency-Key per checkout attempt, kept on the order before MPS is called.
$requests = array();
$answers  = array( array( 201, '{"data":{"paymentReference":"p1","checkoutUrl":"https://pay.example/secret-link"}}' ) );
$logged   = array();
( new Mps_Payments_Api(
	'https://gateway.example.com',
	$api_key,
	static function ( $message ) use ( &$logged ) {
		$logged[] = $message;
	}
) )->create_payment( array( 'amount' => 100, 'reference' => '1042', 'successUrl' => 's', 'failureUrl' => 'f' ), 'wc-1042-payment-100-k' );
check( 'create call: the Idempotency-Key header is sent', 'wc-1042-payment-100-k' === $requests[0]['headers']['Idempotency-Key'] );
check( 'create call: the log names the idempotency key, never checkoutUrl or the API key', 1 === count( $logged ) && false !== strpos( $logged[0], 'idempotency key wc-1042-payment-100-k' ) && false === strpos( $logged[0], 'secret-link' ) && false === strpos( $logged[0], $api_key ) );

/**
 * Runs process_payment for an order against MPS's answers.
 *
 * @param Fake_Order $order   The order.
 * @param array      $answer  What MPS answers to the create: array( status, body ), or a WP_Error for no answer.
 * @return array( result, the Idempotency-Key sent, the body sent )
 */
function mps_pay( $order, $answer ) {
	global $orders, $answers, $requests, $api_key;

	$orders   = array( $order->id => $order );
	$requests = array();
	$answers  = array( $answer );

	$gateway           = new Mps_Payments_Gateway();
	$gateway->settings = array( 'api_key' => $api_key );
	$result            = $gateway->process_payment( $order->id );

	return array( $result, $requests[0]['headers']['Idempotency-Key'], $requests[0]['body'] );
}

$opened = array( 201, '{"data":{"paymentReference":"p-1","checkoutUrl":"https://pay.example/1"}}' );
$order  = new Fake_Order();

list( $result, $first, $first_body ) = mps_pay( $order, new WP_Error( 'http_request_failed', 'timed out' ) );
check( 'pay: the create sends an Idempotency-Key naming the order and the amount', 1 === preg_match( '/^wc-1042-payment-1999-[0-9a-f-]{36}$/', $first ) );
check( 'pay: no answer is a failure, and the key stays on the order', 'failure' === $result['result'] && $first === $order->get_meta( Mps_Payments_Gateway::META_PAYMENT_KEY ) );

list( $result, $again, $again_body ) = mps_pay( $order, $opened );
check( 'pay: placing the order again after no answer resends the same key with the same body', $first === $again && $first_body === $again_body );
check( 'pay: the payment MPS answered is recorded and the shopper sent to it', 'success' === $result['result'] && 'p-1' === $order->get_meta( Mps_Payments_Gateway::META_CURRENT_REFERENCE ) );
check( 'pay: once MPS answered, the key is dropped from the order', '' === $order->get_meta( Mps_Payments_Gateway::META_PAYMENT_KEY ) );

// The shopper comes back declined and pays again for the same order.
list( $result, $retry ) = mps_pay( $order, array( 201, '{"data":{"paymentReference":"p-2","checkoutUrl":"https://pay.example/2"}}' ) );
check( 'pay: a new attempt after a failed payment sends a new key', $retry !== $first && 0 === strpos( $retry, 'wc-1042-payment-1999-' ) );
check( 'pay: and its payment is the current one, both kept', 'p-2' === $order->get_meta( Mps_Payments_Gateway::META_CURRENT_REFERENCE ) && array( 'p-1', 'p-2' ) === $order->get_meta( Mps_Payments_Gateway::META_REFERENCE, false ) );

$order = new Fake_Order();
list( , $before ) = mps_pay( $order, new WP_Error( 'http_request_failed', 'timed out' ) );
$order->total     = '25.00';
list( , $after )  = mps_pay( $order, $opened );
check( 'pay: a changed amount is a new attempt with a new key', $before !== $after && 0 === strpos( $after, 'wc-1042-payment-2500-' ) );

$order = new Fake_Order();
list( , $busy )      = mps_pay( $order, array( 409, '' ) );
list( , $after_busy ) = mps_pay( $order, $opened );
check( 'pay: after a 409 (still being opened) the same key is sent again', $busy === $after_busy );

$order = new Fake_Order();
list( $result, $refused ) = mps_pay( $order, array( 400, '{"errors":{"Idempotency-Key":["The Idempotency-Key was used with a different request."]}}' ) );
check( 'pay: a refusal is a failure and drops the key', 'failure' === $result['result'] && '' === $order->get_meta( Mps_Payments_Gateway::META_PAYMENT_KEY ) );
list( , $after_refusal ) = mps_pay( $order, $opened );
check( 'pay: so the next attempt after a refusal gets a new key', $refused !== $after_refusal );

$order = new Fake_Order();
list( , $unreachable ) = mps_pay( $order, array( 503, '' ) );
list( , $after_5xx )   = mps_pay( $order, $opened );
check( 'pay: after a 5xx the same key is sent again', $unreachable === $after_5xx );

// Refunds: WooCommerce's refund button sends one refund to MPS for the order's paid payment.
check( 'cents: 19.99 is 1999', 1999 === Mps_Payments_Gateway::to_cents( 19.99 ) );
check( 'cents: "19.99" is 1999', 1999 === Mps_Payments_Gateway::to_cents( '19.99' ) );
check( 'cents: 0.1 + 0.2 is 30', 30 === Mps_Payments_Gateway::to_cents( 0.1 + 0.2 ) );
check( 'cents: 4.35 is 435', 435 === Mps_Payments_Gateway::to_cents( '4.35' ) );
check( 'cents: 1234.5 is 123450', 123450 === Mps_Payments_Gateway::to_cents( '1234.5' ) );
check( 'cents: 0.01 is 1', 1 === Mps_Payments_Gateway::to_cents( '0.01' ) );
check( 'cents: 1,000.00 written by WooCommerce as 1000.00 is 100000', 100000 === Mps_Payments_Gateway::to_cents( '1000.00' ) );

$requests = array();
$answers  = array( array( 201, '{"data":{"refundId":"r1","status":"refunded","amount":500}}' ) );
$logged   = array();
$refund   = ( new Mps_Payments_Api(
	'https://gateway.example.com/',
	$api_key,
	static function ( $message ) use ( &$logged ) {
		$logged[] = $message;
	}
) )->refund_payment( 'p1', 500, 'wc-1042-refund-1-500' );
$sent     = json_decode( $requests[0]['body'], true );
check( 'refund call: POST /api/payments/{paymentReference}/refunds with the API key as the bearer', 'POST' === $requests[0]['method'] && 'https://gateway.example.com/api/payments/p1/refunds' === $requests[0]['url'] && 'Bearer ' . $api_key === $requests[0]['headers']['Authorization'] );
check( 'refund call: the body is the amount in cents and the reason, the payment only in the path', array( 'amount' => 500, 'reason' => 'requested_by_customer' ) === $sent );
check( 'refund call: the Idempotency-Key header is sent', 'wc-1042-refund-1-500' === $requests[0]['headers']['Idempotency-Key'] );
check( 'refund call: the data is returned', 'r1' === $refund['refundId'] );
check( 'refund call: the log names the refund and never the key', 1 === count( $logged ) && false !== strpos( $logged[0], 'refundId r1' ) && false === strpos( $logged[0], $api_key ) );

$requests = array();
$answers  = array( array( 201, '{"data":{"refundId":"r1","status":"pending"}}' ) );
( new Mps_Payments_Api( 'https://gateway.example.com', $api_key ) )->refund_payment( 'test_a/b c?d#e', null, 'k' );
check( 'refund call: the payment reference is encoded as one path segment', 'https://gateway.example.com/api/payments/test_a%2Fb%20c%3Fd%23e/refunds' === $requests[0]['url'] );
check( 'refund call: everything still refundable sends no amount', array( 'reason' => 'requested_by_customer' ) === json_decode( $requests[0]['body'], true ) );

/**
 * Runs process_refund for a paid order against one MPS answer.
 *
 * @param array|WP_Error $answer What MPS answers: array( status, body ), or a WP_Error for no answer.
 * @param string         $amount What WooCommerce asks to refund.
 * @return array( result, order, wc refund )
 */
function mps_refund( $answer, $amount = '19.99' ) {
	global $orders, $answers, $requests, $api_key;

	$order                 = new Fake_Order();
	$order->transaction_id = 'p-paid';
	$orders                = array( $order->id => $order );
	$wc_refund             = new Fake_Refund();
	$wc_refund->id         = 77;
	$requests              = array();
	$answers               = array( $answer );

	$gateway           = new Mps_Payments_Gateway();
	$gateway->settings = array( 'api_key' => $api_key );
	Mps_Payments_Gateway::remember_refund( $wc_refund );

	return array( $gateway->process_refund( $order->id, $amount, 'Customer changed mind' ), $order, $wc_refund );
}

check( 'refund: the gateway declares refunds', in_array( 'refunds', ( new Mps_Payments_Gateway() )->supports, true ) );

list( $result, $order, $wc_refund ) = mps_refund( array( 201, '{"data":{"refundId":"r-ok","paymentReference":"p-paid","status":"refunded","amount":1999,"currency":"EUR","cancelableUntil":null}}' ) );
$sent = json_decode( $requests[0]['body'], true );
check( 'refund: sent for the paid payment, in cents', 'https://gateway.mps-emi.com/api/payments/p-paid/refunds' === $requests[0]['url'] && 1999 === $sent['amount'] && ! isset( $sent['paymentReference'] ) );
check( 'refund: the idempotency key names the order, the refund number and the amount', 'wc-1042-refund-1-1999' === $requests[0]['headers']['Idempotency-Key'] );
check( 'refund: refunded is true', true === $result );
check( 'refund: refunded adds a note with the MPS refund id', 1 === count( $order->notes ) && false !== strpos( $order->notes[0], 'r-ok' ) );
check( 'refund: the MPS refund id is kept on the WooCommerce refund', 'r-ok' === $wc_refund->get_meta( Mps_Payments_Gateway::META_REFUND_ID ) && $wc_refund->saved );
check( 'refund: the MPS refund id is kept on the order', array( 'r-ok' ) === $order->get_meta( Mps_Payments_Gateway::META_REFUND_ID, false ) );
check( 'refund: the order status before the refund is kept on the WooCommerce refund', 'processing' === $wc_refund->get_meta( Mps_Payments_Gateway::META_ORDER_STATUS ) );

$order->meta[ Mps_Payments_Gateway::META_REFUND_ID ] = array( 'r-ok' );
$orders  = array( $order->id => $order );
$answers = array( array( 201, '{"data":{"refundId":"r-2","status":"refunded","amount":500}}' ) );
$gateway = new Mps_Payments_Gateway();
$gateway->settings = array( 'api_key' => $api_key );
$requests = array();
$gateway->process_refund( $order->id, '5.00', '' );
check( 'refund: a second refund of the order has its own idempotency key', 'wc-1042-refund-2-500' === $requests[0]['headers']['Idempotency-Key'] );

list( $result, $order ) = mps_refund( array( 201, '{"data":{"refundId":"r-card","status":"pending","amount":1999,"cancelableUntil":null}}' ) );
check( 'refund: pending is accepted', true === $result );
check( 'refund: pending says it is being processed', false !== strpos( $order->notes[0], 'r-card' ) && false !== strpos( $order->notes[0], 'being processed' ) );

list( $result, $order ) = mps_refund( array( 201, '{"data":{"refundId":"r-held","status":"pending","amount":1999,"cancelableUntil":"2026-10-30T14:00:00Z"}}' ) );
check( 'refund: a held refund is accepted', true === $result );
check( 'refund: a held refund says when it is sent and that it can be canceled in MPS until then', false !== strpos( $order->notes[0], 'sent after 2026-10-30 14:00 and can be canceled in MPS until then' ) );
check( 'refund: the note does not guess how it is paid back', false === stripos( $order->notes[0], 'bank transfer' ) );

list( $result, $order ) = mps_refund( array( 201, '{"data":{"refundId":"r-new","status":"something-new","amount":1999}}' ) );
check( 'refund: an unknown status is treated as pending', true === $result && false !== strpos( $order->notes[0], 'being processed' ) );

list( $result, $order, $wc_refund ) = mps_refund( array( 201, '{"data":{"refundId":"r-no","status":"failed","failureReason":"declined","amount":1999}}' ) );
check( 'refund: failed is an error naming the reason', $result instanceof WP_Error && false !== strpos( $result->get_error_message(), 'declined' ) );
check( 'refund: failed keeps no MPS refund id on the order', array() === $order->get_meta( Mps_Payments_Gateway::META_REFUND_ID, false ) );

list( $result ) = mps_refund( array( 400, '{"title":"One or more validation errors occurred.","status":400,"errors":{"amount":["The amount is more than is left to refund (EUR 4.95)."]}}' ) );
check( 'refund: 400 is an error with what MPS said', $result instanceof WP_Error && false !== strpos( $result->get_error_message(), 'more than is left to refund (EUR 4.95)' ) );

list( $result ) = mps_refund( array( 403, '' ) );
check( 'refund: 403 explains the payments:refund scope', $result instanceof WP_Error && false !== strpos( $result->get_error_message(), 'payments:refund' ) && false !== strpos( $result->get_error_message(), 'test key' ) );

list( $result ) = mps_refund( array( 404, '' ) );
check( 'refund: 404 says MPS does not know the payment for this key', $result instanceof WP_Error && false !== strpos( $result->get_error_message(), 'does not know' ) );

list( $result ) = mps_refund( array( 409, '' ) );
check( 'refund: 409 says try again', $result instanceof WP_Error && false !== strpos( $result->get_error_message(), 'try again' ) );

list( $result ) = mps_refund( new WP_Error( 'http_request_failed', 'timed out' ) );
check( 'refund: no answer warns that the refund may have been made', $result instanceof WP_Error && false !== strpos( $result->get_error_message(), 'may have been made' ) );

$orders  = array( 7 => new Fake_Order() );
$answers = array();
$requests = array();
$result  = ( new Mps_Payments_Gateway() )->process_refund( 7, '1.00', '' );
check( 'refund: an order without an MPS payment calls nothing', $result instanceof WP_Error && 0 === count( $requests ) );

$order = new Fake_Order();
$order->meta[ Mps_Payments_Gateway::META_CURRENT_REFERENCE ] = array( 'p-held' );
$orders   = array( $order->id => $order );
$answers  = array( array( 201, '{"data":{"refundId":"r-h","status":"refunded","amount":100}}' ) );
$requests = array();
$gateway  = new Mps_Payments_Gateway();
$gateway->settings = array( 'api_key' => $api_key );
$gateway->process_refund( $order->id, '1.00', '' );
check( 'refund: an order on hold without a transaction id refunds its current payment', 'https://gateway.mps-emi.com/api/payments/p-held/refunds' === $requests[0]['url'] );

// Refund notifications: refund.refunded notes the payout once; refund.failed and refund.canceled undo the
// WooCommerce refund, because the money never left.

/**
 * A paid order with one refund made through MPS, as process_refund leaves it.
 *
 * @param string $order_status The order's status after WooCommerce's refund.
 * @return array( order, wc refund )
 */
function mps_refunded_order( $order_status = 'processing' ) {
	global $orders, $actions;

	$order         = new Fake_Order();
	$order->status = $order_status;
	$order->paid   = true;
	$order->add_meta_data( Mps_Payments_Gateway::META_REFERENCE, 'p-paid' );
	$order->add_meta_data( Mps_Payments_Gateway::META_REFUND_ID, 'r-1' );

	$wc_refund     = new Fake_Refund();
	$wc_refund->id = 77;
	$wc_refund->update_meta_data( Mps_Payments_Gateway::META_REFUND_ID, 'r-1' );
	$wc_refund->update_meta_data( Mps_Payments_Gateway::META_ORDER_STATUS, 'completed' );
	$order->refunds = array( $wc_refund );

	$orders  = array( $order->id => $order );
	$actions = array();

	return array( $order, $wc_refund );
}

function mps_refund_event( $type, $refund_id = 'r-1' ) {
	return array(
		'eventType'        => 'refund.' . $type,
		'paymentReference' => 'p-paid',
		'refundId'         => $refund_id,
		'amount'           => 500,
		'status'           => $type,
	);
}

$gateway = new Mps_Payments_Gateway();
$gateway->settings = array( 'api_key' => $api_key );

list( $order, $wc_refund ) = mps_refunded_order();
$requests = array();
$gateway->handle_event( mps_refund_event( 'refunded' ) );
$gateway->handle_event( mps_refund_event( 'refunded' ) );
check( 'refund.refunded: one note that the refund was paid out, also when delivered twice', 1 === count( $order->notes ) && false !== strpos( $order->notes[0], 'MPS refund r-1 of EUR 5.00 was paid out.' ) );
check( 'refund.refunded: the outcome is kept on the WooCommerce refund', 'refunded' === $wc_refund->get_meta( Mps_Payments_Gateway::META_REFUND_STATUS ) && ! $wc_refund->deleted );
check( 'refund.refunded: reads nothing from MPS', 0 === count( $requests ) );

foreach ( array( 'failed' => 'was not paid out (failed)', 'canceled' => 'was canceled' ) as $type => $said ) {
	list( $order, $wc_refund ) = mps_refunded_order();
	$gateway->handle_event( mps_refund_event( $type ) );
	check( "refund.$type: the WooCommerce refund is deleted", true === $wc_refund->deleted );
	check( "refund.$type: WooCommerce is told, as when a refund is deleted on the order screen", array( 'woocommerce_refund_deleted', 77, 1042 ) === $actions[0] );
	check( "refund.$type: a note says so and that the amount can be refunded again", 1 === count( $order->notes ) && false !== strpos( $order->notes[0], "MPS refund r-1 of EUR 5.00 $said" ) && false !== strpos( $order->notes[0], '#77' ) );
	check( "refund.$type: the MPS refund id stays on the order, so the next refund gets a new idempotency key", array( 'r-1' ) === $order->get_meta( Mps_Payments_Gateway::META_REFUND_ID, false ) );
	check( "refund.$type: a partly refunded order keeps its status", 'processing' === $order->status );

	$order->refunds = array();
	$gateway->handle_event( mps_refund_event( $type ) );
	check( "refund.$type: delivered again, nothing more happens", 1 === count( $order->notes ) && 1 === count( $actions ) );
}

list( $order, $wc_refund ) = mps_refunded_order( 'refunded' );
$gateway->handle_event( mps_refund_event( 'failed' ) );
check( 'refund.failed: an order Refunded by this refund goes back to its status before', 'completed' === $order->status );

list( $order, $wc_refund ) = mps_refunded_order();
$gateway->handle_event( mps_refund_event( 'canceled', 'r-unknown' ) );
$gateway->handle_event( mps_refund_event( 'refunded', 'r-unknown' ) );
check( 'refund event: an unknown refund changes nothing', 0 === count( $order->notes ) && ! $wc_refund->deleted && 0 === count( $actions ) );

list( $order ) = mps_refunded_order();
$requests = array();
$answers  = array( array( 200, '{"data":{"state":"pending"}}' ) );
$order->paid = false;
$order->update_meta_data( Mps_Payments_Gateway::META_CURRENT_REFERENCE, 'p-paid' );
$gateway->handle_event( array( 'eventType' => 'payment.received', 'paymentReference' => 'p-paid', 'amount' => 1999, 'status' => 'completed' ) );
check( 'payment.received: still reads the payment from MPS', 1 === count( $requests ) && 'https://gateway.mps-emi.com/api/payments/internet/p-paid' === $requests[0]['url'] );
check( 'payment.received: touches no refund', 0 === count( $actions ) && 0 === count( $order->notes ) );

$requests = array();
$gateway->handle_event( array( 'eventType' => 'payment.received', 'paymentReference' => 'p-nobody' ) );
check( 'payment.received: a payment of no order of this shop reads nothing', 0 === count( $requests ) );

// A refund carries its order's payment reference meta: a notification must still find the order, not the refund.
list( $order, $wc_refund ) = mps_refunded_order();
$wc_refund->add_meta_data( Mps_Payments_Gateway::META_REFERENCE, 'p-paid' );
check( 'find order: an order with a refund carrying the same reference is found as the order', $order === Mps_Payments_Gateway::find_order( 'p-paid' ) );

$orders_query_ignores_type = true;
check( 'find order: a refund that still comes back leads to its order', $order === Mps_Payments_Gateway::find_order( 'p-paid' ) );
$orders_query_ignores_type = false;

$gateway->handle_event( mps_refund_event( 'canceled' ) );
check( 'refund.canceled: undoes the refund of an order whose refund carries the reference', true === $wc_refund->deleted && 1 === count( $order->notes ) && false !== strpos( $order->notes[0], '#77' ) );

list( $order, $wc_refund ) = mps_refunded_order();
$wc_refund->add_meta_data( Mps_Payments_Gateway::META_REFERENCE, 'p-paid' );
$order->paid   = false;
$order->status = 'pending';
$order->update_meta_data( Mps_Payments_Gateway::META_CURRENT_REFERENCE, 'p-paid' );
$requests = array();
$answers  = array( array( 200, '{"data":{"state":"failed"}}' ) );
$gateway->handle_event( array( 'eventType' => 'payment.received', 'paymentReference' => 'p-paid' ) );
check( 'payment.received: an order that has refunds is the one settled', 1 === count( $requests ) && 'failed' === $order->status );

echo PHP_EOL . ( 0 === $failures ? 'All passed.' : "$failures failed." ) . PHP_EOL;
exit( 0 === $failures ? 0 : 1 );
