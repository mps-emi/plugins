<?php
/**
 * The module's pure rules, tested without PrestaShop: `php tests/run.php` (build.sh runs it in a php:cli
 * container). Exits non-zero on the first failure.
 */

// The guard every PrestaShop file starts with.
define('_PS_VERSION_', 'test');

require __DIR__ . '/../mpspayments/classes/MpsPaymentsSignature.php';
require __DIR__ . '/../mpspayments/classes/MpsPaymentsCallbackUrl.php';
require __DIR__ . '/../mpspayments/classes/MpsPaymentsOutcome.php';
require __DIR__ . '/../mpspayments/classes/MpsPaymentsGatewayUrl.php';
require __DIR__ . '/../mpspayments/classes/MpsPaymentsOrderCheck.php';
require __DIR__ . '/../mpspayments/classes/MpsPaymentsRepository.php';

$failures = 0;

function check($name, $condition)
{
    global $failures;
    echo ($condition ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
}

// The worked example from the public docs (internet-payments.mdx, "A notification you can test against").
$secret    = 'whsec_EXAMPLE_not_a_real_secret_000000000000';
$timestamp = '1791892905';
$signature = 'v1=bbd5ffb9283e5095b326e21ccf9f102756bd36ffe30a8fba22e38cd984ae7b3a';
$body      = '{"eventType":"payment.received","paymentId":"98dcf1df-2e04-4684-84cd-d47ec8c23c80","paymentReference":"98dcf1df-2e04-4684-84cd-d47ec8c23c80","merchantReference":"order-1042","amount":100,"currency":"EUR","status":"completed","channel":"internet","valutaDate":"2026-10-13T12:01:42.658852Z","occurredAt":"2026-10-13T12:01:44.0678776Z"}';
$now       = 1791892905;

check('signature: the documented example is valid', MpsPaymentsSignature::isValid($secret, $body, $timestamp, $signature, $now));
check('signature: valid when it is the second of two during a rotation', MpsPaymentsSignature::isValid($secret, $body, $timestamp, 'v1=' . str_repeat('0', 64) . ', ' . $signature, $now));
check('signature: a re-encoded body is refused', !MpsPaymentsSignature::isValid($secret, $body . "\n", $timestamp, $signature, $now));
check('signature: a wrong secret is refused', !MpsPaymentsSignature::isValid('whsec_other', $body, $timestamp, $signature, $now));
check('signature: older than five minutes is refused', !MpsPaymentsSignature::isValid($secret, $body, $timestamp, $signature, $now + 301));
check('signature: five minutes old is accepted', MpsPaymentsSignature::isValid($secret, $body, $timestamp, $signature, $now + 300));
check('signature: a non-numeric timestamp is refused', !MpsPaymentsSignature::isValid($secret, $body, 'abc', $signature, $now));
check('signature: a missing header is refused', !MpsPaymentsSignature::isValid($secret, $body, $timestamp, '', $now));
check('signature: an unprefixed value is refused', !MpsPaymentsSignature::isValid($secret, $body, $timestamp, substr($signature, 3), $now));

// What the webhook controller does with a notification: ignore it (200, no read), refuse it (401), or read the payment.
check('notification: no secret configured is ignored, whatever the body', MpsPaymentsSignature::IGNORE === MpsPaymentsSignature::verdict('', $body, $timestamp, $signature, $now));
check('notification: no secret configured is ignored, even unsigned', MpsPaymentsSignature::IGNORE === MpsPaymentsSignature::verdict('', 'not json', '', '', $now));
check('notification: a secret and a bad signature is refused', MpsPaymentsSignature::REFUSE === MpsPaymentsSignature::verdict($secret, $body, $timestamp, 'v1=' . str_repeat('0', 64), $now));
check('notification: a secret and no signature is refused', MpsPaymentsSignature::REFUSE === MpsPaymentsSignature::verdict($secret, $body, '', '', $now));
check('notification: a secret and a stale timestamp is refused', MpsPaymentsSignature::REFUSE === MpsPaymentsSignature::verdict($secret, $body, $timestamp, $signature, $now + 301));
check('notification: a secret and a valid signature reads the payment', MpsPaymentsSignature::READ === MpsPaymentsSignature::verdict($secret, $body, $timestamp, $signature, $now));

check('gateway URL: the default is accepted', MpsPaymentsGatewayUrl::isValid('https://gateway.mps-emi.com'));
check('gateway URL: a path and a port are accepted', MpsPaymentsGatewayUrl::isValid('https://gateway.example.com:8443/mps'));
check('gateway URL: HTTPS in capitals is accepted', MpsPaymentsGatewayUrl::isValid('HTTPS://gateway.example.com'));
check('gateway URL: http is refused', !MpsPaymentsGatewayUrl::isValid('http://gateway.mps-emi.com'));
check('gateway URL: no scheme is refused', !MpsPaymentsGatewayUrl::isValid('gateway.mps-emi.com'));
check('gateway URL: a relative path is refused', !MpsPaymentsGatewayUrl::isValid('/gateway'));
check('gateway URL: no host is refused', !MpsPaymentsGatewayUrl::isValid('https://'));
check('gateway URL: another scheme is refused', !MpsPaymentsGatewayUrl::isValid('javascript:alert(1)'));
check('gateway URL: empty is refused', !MpsPaymentsGatewayUrl::isValid(''));
check('gateway URL: a space inside is refused', !MpsPaymentsGatewayUrl::isValid('https://gateway mps-emi.com'));

check('webhook: public https host', MpsPaymentsCallbackUrl::canReceiveWebhooks('https://shop.example.com/module/mpspayments/webhook'));
check('webhook: public IP', MpsPaymentsCallbackUrl::canReceiveWebhooks('https://8.8.8.8/module/mpspayments/webhook'));
check('webhook: http is not sent', !MpsPaymentsCallbackUrl::canReceiveWebhooks('http://shop.example.com/module/mpspayments/webhook'));
check('webhook: localhost is not sent', !MpsPaymentsCallbackUrl::canReceiveWebhooks('https://localhost:8443/module/mpspayments/webhook'));
check('webhook: a .localhost name is not sent', !MpsPaymentsCallbackUrl::canReceiveWebhooks('https://shop.localhost/module/mpspayments/webhook'));
check('webhook: a .test name is not sent', !MpsPaymentsCallbackUrl::canReceiveWebhooks('https://shop.test/module/mpspayments/webhook'));
check('webhook: a private IP is not sent', !MpsPaymentsCallbackUrl::canReceiveWebhooks('https://192.168.1.20/module/mpspayments/webhook'));
check('webhook: loopback is not sent', !MpsPaymentsCallbackUrl::canReceiveWebhooks('https://127.0.0.1/module/mpspayments/webhook'));
check('webhook: IPv6 loopback is not sent', !MpsPaymentsCallbackUrl::canReceiveWebhooks('https://[::1]/module/mpspayments/webhook'));

check('outcome: executed for the full amount is paid', MpsPaymentsOutcome::PAID === MpsPaymentsOutcome::of(['state' => 'executed', 'amount' => 1234], 1234));
check('outcome: executed for more than asked is paid', MpsPaymentsOutcome::PAID === MpsPaymentsOutcome::of(['state' => 'executed', 'amount' => 1300], 1234));
check('outcome: executed for less is a partial approval', MpsPaymentsOutcome::PARTIAL === MpsPaymentsOutcome::of(['state' => 'executed', 'amount' => 1000], 1234));
check('outcome: executed without an amount is a partial approval, never paid', MpsPaymentsOutcome::PARTIAL === MpsPaymentsOutcome::of(['state' => 'executed'], 1234));
check('outcome: failed', MpsPaymentsOutcome::FAILED === MpsPaymentsOutcome::of(['state' => 'failed'], 1234));
check('outcome: abandoned', MpsPaymentsOutcome::ABANDONED === MpsPaymentsOutcome::of(['state' => 'abandoned'], 1234));
check('outcome: pending', MpsPaymentsOutcome::PENDING === MpsPaymentsOutcome::of(['state' => 'pending'], 1234));
check('outcome: an unknown state is pending, never paid', MpsPaymentsOutcome::PENDING === MpsPaymentsOutcome::of(['state' => 'settled'], 1234));
check('outcome: no state is pending', MpsPaymentsOutcome::PENDING === MpsPaymentsOutcome::of([], 1234));

// Before a paid cart becomes an order: the customer, the module, and whether the module may still take this cart.
check('order check: all fine makes the order', null === MpsPaymentsOrderCheck::refusal(true, true, [['name' => 'ps_wirepayment'], ['name' => 'mpspayments']], 'mpspayments'));
check('order check: a deleted customer refuses', null !== MpsPaymentsOrderCheck::refusal(false, true, [['name' => 'mpspayments']], 'mpspayments'));
check('order check: a disabled module refuses', null !== MpsPaymentsOrderCheck::refusal(true, false, [['name' => 'mpspayments']], 'mpspayments'));
check('order check: a module no longer offered for the cart refuses', null !== MpsPaymentsOrderCheck::refusal(true, true, [['name' => 'ps_wirepayment']], 'mpspayments'));
check('order check: no payment module at all refuses', null !== MpsPaymentsOrderCheck::refusal(true, true, [], 'mpspayments'));

check('table: prefixed with the module name', 'mpspayments_payment' === MpsPaymentsRepository::TABLE);

check('cents: 12.34', 1234 === MpsPaymentsOutcome::cents(12.34));
check('cents: 0.29 does not round down to 28', 29 === MpsPaymentsOutcome::cents(0.29));
check('cents: a string total', 1999 === MpsPaymentsOutcome::cents('19.9900'));

echo PHP_EOL . (0 === $failures ? 'All passed.' : "$failures failed.") . PHP_EOL;
exit(0 === $failures ? 0 : 1);
