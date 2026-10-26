<?php
/**
 * The plugin's pure rules, tested without WordPress: `php tests/run.php` (build.sh runs it in a
 * php:cli container). Exits non-zero on the first failure.
 *
 * @package MpsPayments
 */

define( 'ABSPATH', __DIR__ );

// The few WordPress pieces the code under test touches.
class WC_Payment_Gateway {}

function wp_parse_url( $url ) {
	return parse_url( $url );
}

require __DIR__ . '/../mps-payments/includes/class-mps-payments-gateway.php';

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

echo PHP_EOL . ( 0 === $failures ? 'All passed.' : "$failures failed." ) . PHP_EOL;
exit( 0 === $failures ? 0 : 1 );
