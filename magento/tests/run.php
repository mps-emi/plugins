<?php
/**
 * The module's pure rules, tested without Magento: `php tests/run.php` (build.sh runs it in a php:cli
 * container). Exits non-zero on the first failure.
 */

declare(strict_types=1);

require __DIR__ . '/../mps-payments/Model/Signature.php';
require __DIR__ . '/../mps-payments/Model/CallbackUrl.php';
require __DIR__ . '/../mps-payments/Model/Outcome.php';
require __DIR__ . '/../mps-payments/Model/Notification.php';

use Mps\Payments\Model\CallbackUrl;
use Mps\Payments\Model\Notification;
use Mps\Payments\Model\Outcome;
use Mps\Payments\Model\Signature;

$failures = 0;

function check(string $name, bool $condition): void
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

check('signature: the documented example is valid', Signature::isValid($secret, $body, $timestamp, $signature, $now));
check('signature: valid when it is the second of two during a rotation', Signature::isValid($secret, $body, $timestamp, 'v1=' . str_repeat('0', 64) . ', ' . $signature, $now));
check('signature: a re-encoded body is refused', !Signature::isValid($secret, $body . "\n", $timestamp, $signature, $now));
check('signature: a wrong secret is refused', !Signature::isValid('whsec_other', $body, $timestamp, $signature, $now));
check('signature: older than five minutes is refused', !Signature::isValid($secret, $body, $timestamp, $signature, $now + 301));
check('signature: five minutes old is accepted', Signature::isValid($secret, $body, $timestamp, $signature, $now + 300));
check('signature: a non-numeric timestamp is refused', !Signature::isValid($secret, $body, 'abc', $signature, $now));
check('signature: a missing header is refused', !Signature::isValid($secret, $body, $timestamp, '', $now));
check('signature: an unprefixed value is refused', !Signature::isValid($secret, $body, $timestamp, substr($signature, 3), $now));

check('notification: without a secret it is ignored, whatever it carries', Notification::IGNORE === Notification::decide('', $body, $timestamp, $signature, $now));
check('notification: without a secret even a garbage body is ignored, never read', Notification::IGNORE === Notification::decide('', 'not json', '', '', $now));
check('notification: with a secret and a bad signature it is refused', Notification::REFUSE === Notification::decide($secret, $body, $timestamp, 'v1=' . str_repeat('0', 64), $now));
check('notification: with a secret and no signature it is refused', Notification::REFUSE === Notification::decide($secret, $body, '', '', $now));
check('notification: with a secret and a valid signature the payment is read', Notification::READ === Notification::decide($secret, $body, $timestamp, $signature, $now));

$event = json_decode($body, true);
check('notification: the payment reference is taken from the event', '98dcf1df-2e04-4684-84cd-d47ec8c23c80' === Notification::field($event, 'paymentReference'));
check('notification: the merchant reference is taken from the event', 'order-1042' === Notification::field($event, 'merchantReference'));
check('notification: a missing field is empty', '' === Notification::field(['paymentReference' => 'abc'], 'merchantReference'));
check('notification: a non-string field is empty', '' === Notification::field(['merchantReference' => 1042], 'merchantReference'));
check('notification: a body that is not an object has no fields', '' === Notification::field(null, 'paymentReference'));

check('notification: is for the order whose stored reference matches', Notification::isFor(['98dcf1df', null], '98dcf1df'));
check('notification: is for the order when only the last transaction id matches', Notification::isFor([null, '98dcf1df'], '98dcf1df'));
check('notification: is not for an order with another payment', !Notification::isFor(['11111111', '11111111'], '98dcf1df'));
check('notification: is not for an order with no payment opened', !Notification::isFor([null, ''], '98dcf1df'));
check('notification: an empty reference matches nothing, not even an empty one', !Notification::isFor(['', ''], ''));
check('notification: matching is exact, not case-insensitive', !Notification::isFor(['98DCF1DF'], '98dcf1df'));

check('webhook: public https host', CallbackUrl::canReceiveWebhooks('https://shop.example.com/mpspayments/checkout/webhook/'));
check('webhook: public IP', CallbackUrl::canReceiveWebhooks('https://8.8.8.8/mpspayments/checkout/webhook/'));
check('webhook: http is not sent', !CallbackUrl::canReceiveWebhooks('http://shop.example.com/mpspayments/checkout/webhook/'));
check('webhook: localhost is not sent', !CallbackUrl::canReceiveWebhooks('https://localhost:8443/mpspayments/checkout/webhook/'));
check('webhook: a .localhost name is not sent', !CallbackUrl::canReceiveWebhooks('https://shop.localhost/mpspayments/checkout/webhook/'));
check('webhook: a .test name is not sent', !CallbackUrl::canReceiveWebhooks('https://shop.test/mpspayments/checkout/webhook/'));
check('webhook: a private IP is not sent', !CallbackUrl::canReceiveWebhooks('https://192.168.1.20/mpspayments/checkout/webhook/'));
check('webhook: loopback is not sent', !CallbackUrl::canReceiveWebhooks('https://127.0.0.1/mpspayments/checkout/webhook/'));
check('webhook: IPv6 loopback is not sent', !CallbackUrl::canReceiveWebhooks('https://[::1]/mpspayments/checkout/webhook/'));

check('outcome: executed for the full amount is paid', Outcome::PAID === Outcome::of(['state' => 'executed', 'amount' => 1234], 1234));
check('outcome: executed for more than asked is paid', Outcome::PAID === Outcome::of(['state' => 'executed', 'amount' => 1300], 1234));
check('outcome: executed for less is a partial approval', Outcome::PARTIAL === Outcome::of(['state' => 'executed', 'amount' => 1000], 1234));
check('outcome: executed without an amount is a partial approval, never paid', Outcome::PARTIAL === Outcome::of(['state' => 'executed'], 1234));
check('outcome: failed', Outcome::FAILED === Outcome::of(['state' => 'failed'], 1234));
check('outcome: abandoned', Outcome::ABANDONED === Outcome::of(['state' => 'abandoned'], 1234));
check('outcome: pending', Outcome::PENDING === Outcome::of(['state' => 'pending'], 1234));
check('outcome: an unknown state is pending, never paid', Outcome::PENDING === Outcome::of(['state' => 'settled'], 1234));
check('outcome: no state is pending', Outcome::PENDING === Outcome::of([], 1234));

check('order match: currency EUR and the order number', Outcome::isForOrder(['state' => 'executed', 'currency' => 'EUR', 'reference' => '000000042'], '000000042'));
check('order match: without currency or reference it is not contradicted', Outcome::isForOrder(['state' => 'executed'], '000000042'));
check('order match: another currency is refused', !Outcome::isForOrder(['currency' => 'USD', 'reference' => '000000042'], '000000042'));
check('order match: another order number is refused', !Outcome::isForOrder(['currency' => 'EUR', 'reference' => '000000043'], '000000042'));
check('order match: the order number is compared as text, leading zeros and all', !Outcome::isForOrder(['reference' => '42'], '000000042'));
check('order match: a numeric reference equal to the order number is accepted', Outcome::isForOrder(['reference' => 42], '42'));

check('cents: 12.34', 1234 === Outcome::cents(12.34));
check('cents: 0.29 does not round down to 28', 29 === Outcome::cents(0.29));
check('cents: a string total', 1999 === Outcome::cents('19.9900'));

echo PHP_EOL . (0 === $failures ? 'All passed.' : "$failures failed.") . PHP_EOL;
exit(0 === $failures ? 0 : 1);
