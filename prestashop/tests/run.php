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
require __DIR__ . '/../mpspayments/classes/MpsPaymentsApi.php';
require __DIR__ . '/../mpspayments/classes/MpsPaymentsRefund.php';
require __DIR__ . '/../mpspayments/upgrade/upgrade-1.0.0.php';

// ps_configuration, as the upgrade scripts change it: name => value.
class Configuration
{
    public static $values = [];

    public static function deleteByName($key)
    {
        unset(self::$values[$key]);

        return true;
    }
}

// Db::getInstance()->delete('configuration', "name LIKE '…'") on Configuration::$values, as MySQL would.
class Db
{
    public static function getInstance()
    {
        return new self();
    }

    public function delete($table, $where)
    {
        if ('configuration' !== $table || !preg_match("/^name LIKE '(.*)'\$/", $where, $match)) {
            return false;
        }
        // LIKE: \x is x itself, % any run, _ any one character.
        $pattern = '';
        for ($i = 0; $i < strlen($match[1]); $i++) {
            $c = $match[1][$i];
            if ('\\' === $c) {
                $pattern .= preg_quote($match[1][++$i], '/');
            } else {
                $pattern .= '%' === $c ? '.*' : ('_' === $c ? '.' : preg_quote($c, '/'));
            }
        }
        $pattern = '/^' . $pattern . '$/';
        foreach (array_keys(Configuration::$values) as $name) {
            if (preg_match($pattern, $name)) {
                unset(Configuration::$values[$name]);
            }
        }

        return true;
    }
}

// The API client without curl: every request is recorded and answered from a queue of [status, body].
class RecordedMpsPaymentsApi extends MpsPaymentsApi
{
    public $requests = [];
    public $answers = [];
    /** @var string|null A transport error every request ends in, as curl would report it. */
    public $unreachable = null;

    protected function send($method, $url, array $headers, $body)
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        list($status, $responseBody) = array_shift($this->answers);

        return [$status, $responseBody, $this->unreachable];
    }
}

function recorded($apiKey, array $answers)
{
    $api = new RecordedMpsPaymentsApi('https://gateway.example.com/', $apiKey, function () {
    });
    $api->answers = $answers;

    return $api;
}

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

// The credentials: the API key is sent as the bearer on every call. There is no token request: client IDs
// and secrets are gone since 1.0.0.
$apiKey = 'mps_test_abcdef123456_c2VjcmV0X3dpdGhfdW5kZXJzY29yZXM';

$api = recorded($apiKey, [[201, '{"data":{"paymentReference":"p1","checkoutUrl":"https://pay"}}']]);
$opened = $api->createPayment(['amount' => 100, 'reference' => 'ABCDEFGHI'], 'k');
check('api key: the payment is opened with the key as the bearer', in_array('Authorization: Bearer ' . $apiKey, $api->requests[0]['headers'], true) && 'https://gateway.example.com/api/payments/internet/create' === $api->requests[0]['url']);
check('api key: no token is requested', 1 === count($api->requests) && 'p1' === $opened['paymentReference']);

$api = recorded($apiKey, [[200, '{"data":{"state":"executed"}}']]);
$api->getPayment('p1');
check('api key: a read carries the key, nothing else is asked for', 1 === count($api->requests) && in_array('Authorization: Bearer ' . $apiKey, $api->requests[0]['headers'], true));

$api = recorded($apiKey, [[401, ''], [401, '']]);
try {
    $api->getPayment('p1');
    check('api key: a 401 is an error', false);
} catch (Exception $e) {
    check('api key: a 401 is not retried and asks for no token', 1 === count($api->requests) && 401 === $e->getCode());
}

$api = recorded($apiKey, [[404, '']]);
try {
    $api->verifyCredentials();
    check('api key: verified by a read MPS answers 404, without a token request', 1 === count($api->requests) && 'GET' === $api->requests[0]['method'] && in_array('Authorization: Bearer ' . $apiKey, $api->requests[0]['headers'], true));
} catch (Exception $e) {
    check('api key: verified by a read MPS answers 404, without a token request', false);
}

foreach ([401, 403] as $refused) {
    $api = recorded($apiKey, [[$refused, '']]);
    try {
        $api->verifyCredentials();
        check("api key: a $refused on the check is a refused key", false);
    } catch (Exception $e) {
        check("api key: a $refused on the check is a refused key", false !== strpos($e->getMessage(), (string) $refused));
    }
}

foreach (['', '   '] as $none) {
    $api = recorded($none, []);
    try {
        $api->verifyCredentials();
        check('api key: without one nothing is sent, and it is refused', false);
    } catch (Exception $e) {
        check('api key: without one nothing is sent, and it is refused', 0 === count($api->requests));
    }
}

// Upgrading to 1.0.0 removes what a shop on a client ID and secret had stored: no secret or token lingers.
Configuration::$values = [
    'MPS_PAYMENTS_API_KEY' => $apiKey,
    'MPS_PAYMENTS_CLIENT_ID' => 'test_BE0000000000',
    'MPS_PAYMENTS_CLIENT_SECRET' => 'secret',
    'MPS_PAYMENTS_TOKEN_0123456789ABCDEF' => '{"token":"t","expires":1}',
    'MPS_PAYMENTS_TOKEN_FEDCBA9876543210' => '{"token":"r","expires":1}',
    'MPS_PAYMENTS_WEBHOOK_SECRET' => 'whsec_x',
    'MPS_PAYMENTSXTOKEN_OTHER' => 'not ours',
];
check('upgrade 1.0.0: succeeds', true === upgrade_module_1_0_0(null));
check('upgrade 1.0.0: the client ID and secret are deleted', !isset(Configuration::$values['MPS_PAYMENTS_CLIENT_ID']) && !isset(Configuration::$values['MPS_PAYMENTS_CLIENT_SECRET']));
check('upgrade 1.0.0: every cached token is deleted', [] === preg_grep('/^MPS_PAYMENTS_TOKEN_/', array_keys(Configuration::$values)));
check('upgrade 1.0.0: the API key, the webhook secret and other settings stay', $apiKey === Configuration::$values['MPS_PAYMENTS_API_KEY'] && 'whsec_x' === Configuration::$values['MPS_PAYMENTS_WEBHOOK_SECRET'] && isset(Configuration::$values['MPS_PAYMENTSXTOKEN_OTHER']));

// The payment create carries an Idempotency-Key: one per checkout attempt, the same on every resend of it.
$api = recorded($apiKey, [[201, '{"data":{"paymentReference":"p1","checkoutUrl":"https://pay"}}']]);
$api->createPayment(['amount' => 100, 'reference' => 'cart-7'], 'prestashop-cart-7-k1');
check('create: the Idempotency-Key header is sent', in_array('Idempotency-Key: prestashop-cart-7-k1', $api->requests[0]['headers'], true));

$api = recorded($apiKey, [[409, ''], [201, '{"data":{"paymentReference":"p1","checkoutUrl":"https://pay"}}']]);
$opened = $api->createPayment(['amount' => 100, 'reference' => 'cart-7'], 'prestashop-cart-7-k1');
check('create: a 409 (the first send still running) is sent once more, with the same key and body', 2 === count($api->requests) && $api->requests[0]['headers'] === $api->requests[1]['headers'] && $api->requests[0]['body'] === $api->requests[1]['body'] && 'p1' === $opened['paymentReference']);

$api = recorded($apiKey, [[400, '{"errors":{"Idempotency-Key":["Used for another request."]}}']]);
try {
    $api->createPayment(['amount' => 100, 'reference' => 'cart-7'], 'prestashop-cart-7-k1');
    check('create: a 400 is an error, not resent', false);
} catch (Exception $e) {
    check('create: a 400 is an error, not resent', 400 === $e->getCode() && 1 === count($api->requests));
}

$attempt = ['idempotency_key' => 'prestashop-cart-7-k1', 'amount' => 1234, 'outcome' => null, 'opened_at' => $now - 60, 'payment_reference' => 'p1'];
check('attempt: an open attempt for the same amount is resent, with its key', MpsPaymentsRepository::isResendable($attempt, 1234, $now));
check('attempt: one whose create has not answered yet is resent too', MpsPaymentsRepository::isResendable(['payment_reference' => null] + $attempt, 1234, $now));
check('attempt: after a failed payment a new one is opened', !MpsPaymentsRepository::isResendable(['outcome' => 'failed'] + $attempt, 1234, $now));
check('attempt: after an abandoned payment a new one is opened', !MpsPaymentsRepository::isResendable(['outcome' => 'abandoned'] + $attempt, 1234, $now));
check('attempt: a changed amount opens a new one', !MpsPaymentsRepository::isResendable($attempt, 1300, $now));
check('attempt: older than the 24 hours MPS keeps a key opens a new one', !MpsPaymentsRepository::isResendable(['opened_at' => $now - 86401] + $attempt, 1234, $now));
check('attempt: one opened before 0.3.1, without a key, opens a new one', !MpsPaymentsRepository::isResendable(['idempotency_key' => null] + $attempt, 1234, $now));
check('attempt: no attempt yet opens a new one', !MpsPaymentsRepository::isResendable(null, 1234, $now));

$key = MpsPaymentsRepository::newIdempotencyKey(7);
check('attempt key: prestashop-cart-<id>-<random UUID>', 1 === preg_match('/^prestashop-cart-7-[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $key));
check('attempt key: a new attempt gets a different key', $key !== MpsPaymentsRepository::newIdempotencyKey(7));
check('attempt key: fits the column and MPS\'s 255 characters', strlen(MpsPaymentsRepository::newIdempotencyKey(4294967295)) <= 64);

// Refunds: a credit slip on an MPS order refunds its amount in MPS.
$api = recorded($apiKey, [[201, '{"data":{"refundId":"r1","paymentReference":"p1","status":"pending","amount":1234,"currency":"EUR"}}']]);
list($status, $answer) = $api->createRefund('p1', MpsPaymentsRefund::body(1234), 'prestashop-slip-7-p1');
$sent = json_decode((string) $api->requests[0]['body'], true);
check('refund: POST to /api/payments/{paymentReference}/refunds', 'POST' === $api->requests[0]['method'] && 'https://gateway.example.com/api/payments/p1/refunds' === $api->requests[0]['url']);
check('refund: the API key is the bearer', in_array('Authorization: Bearer ' . $apiKey, $api->requests[0]['headers'], true));
check('refund: the Idempotency-Key header is sent', in_array('Idempotency-Key: prestashop-slip-7-p1', $api->requests[0]['headers'], true));
check('refund: the body holds only the amount in integer cents and the reason, the payment is in the path', ['amount' => 1234, 'reason' => 'requested_by_customer'] === $sent && false !== strpos((string) $api->requests[0]['body'], '"amount":1234,'));
check('refund: the status and the answer come back', 201 === $status && 'r1' === $answer['data']['refundId']);

$api = recorded($apiKey, [[201, '{"data":{"refundId":"r1","status":"pending","amount":100}}']]);
$api->createRefund('a b/c?d#e%f', MpsPaymentsRefund::body(100), 'k');
check('refund: the payment reference is encoded as one path segment', 'https://gateway.example.com/api/payments/a%20b%2Fc%3Fd%23e%25f/refunds' === $api->requests[0]['url']);

$api = recorded($apiKey, [[403, '']]);
list($status) = $api->createRefund('p1', MpsPaymentsRefund::body(100), 'k');
check('refund: a refusal is returned, not thrown', 403 === $status && 1 === count($api->requests));

$api = recorded($apiKey, [[0, '']]);
$api->unreachable = 'timeout';
try {
    $api->createRefund('p1', MpsPaymentsRefund::body(100), 'k');
    check('refund: an unreachable gateway throws', false);
} catch (Exception $e) {
    check('refund: an unreachable gateway throws', true);
}

check('refund key: one per credit slip and payment', 'prestashop-slip-42-p1' === MpsPaymentsRefund::idempotencyKey(42, 'p1'));
check('refund key: at most 255 characters', strlen(MpsPaymentsRefund::idempotencyKey(42, str_repeat('x', 300))) <= 255);

// The amount of a credit slip, in cents: what the shopper paid back, tax included.
check('refund amount: products and shipping, tax included', 2585 === MpsPaymentsRefund::amount(['amount' => '19.950000', 'shipping_cost_amount' => '5.900000', 'total_products_tax_incl' => '19.950000', 'total_products_tax_excl' => '16.487603', 'total_shipping_tax_incl' => '5.900000'], true));
check('refund amount: 0.29 does not round down to 28', 29 === MpsPaymentsRefund::amount(['amount' => '0.290000', 'shipping_cost_amount' => '0.000000', 'total_products_tax_incl' => '0.29', 'total_products_tax_excl' => '0.24', 'total_shipping_tax_incl' => '0'], true));
check('refund amount: a chosen amount, tax included', 1000 === MpsPaymentsRefund::amount(['amount' => '10.000000', 'shipping_cost_amount' => '0.000000', 'total_products_tax_incl' => '19.95', 'total_products_tax_excl' => '16.49', 'total_shipping_tax_incl' => '0'], true));
check('refund amount: tax excluded shown, the tax-included totals are paid back', 2585 === MpsPaymentsRefund::amount(['amount' => '16.487603', 'shipping_cost_amount' => '5.900000', 'total_products_tax_incl' => '19.950000', 'total_products_tax_excl' => '16.487603', 'total_shipping_tax_incl' => '5.900000'], false));
check('refund amount: tax excluded with a chosen amount or a voucher deducted cannot be told: none', null === MpsPaymentsRefund::amount(['amount' => '10.000000', 'shipping_cost_amount' => '0', 'total_products_tax_incl' => '19.95', 'total_products_tax_excl' => '16.49', 'total_shipping_tax_incl' => '0'], false));
check('refund amount: nothing', 0 === MpsPaymentsRefund::amount(['amount' => '0', 'shipping_cost_amount' => '0', 'total_products_tax_incl' => '0', 'total_products_tax_excl' => '0', 'total_shipping_tax_incl' => '0'], true));

// What MPS answered, as the merchant is told it.
$result = MpsPaymentsRefund::outcome(201, ['data' => ['refundId' => 'r1', 'status' => 'pending', 'amount' => 1234, 'cancelableUntil' => null]]);
check('refund outcome: 201 pending is accepted', MpsPaymentsRefund::PENDING === $result['outcome'] && 'r1' === $result['refundId'] && 1234 === $result['amount']);
$result = MpsPaymentsRefund::outcome(201, ['data' => ['refundId' => 'r1', 'status' => 'pending', 'amount' => 1234, 'cancelableUntil' => '2026-10-30T14:05:00Z']]);
check('refund outcome: pending carries until when it can be canceled', MpsPaymentsRefund::PENDING === $result['outcome'] && '2026-10-30 14:05 UTC' === $result['cancelableUntil']);
check('refund outcome: refunded', MpsPaymentsRefund::REFUNDED === MpsPaymentsRefund::outcome(201, ['data' => ['refundId' => 'r1', 'status' => 'refunded', 'amount' => 100]])['outcome']);
$result = MpsPaymentsRefund::outcome(201, ['data' => ['refundId' => 'r1', 'status' => 'failed', 'failureReason' => 'declined', 'amount' => 100]]);
check('refund outcome: failed is refused, with its reason', MpsPaymentsRefund::FAILED === $result['outcome'] && 'declined' === $result['detail']);
check('refund outcome: canceled is not refunded', MpsPaymentsRefund::FAILED === MpsPaymentsRefund::outcome(201, ['data' => ['refundId' => 'r1', 'status' => 'canceled', 'amount' => 100]])['outcome']);
check('refund outcome: an unknown status is pending', MpsPaymentsRefund::PENDING === MpsPaymentsRefund::outcome(201, ['data' => ['refundId' => 'r1', 'status' => 'queued', 'amount' => 100]])['outcome']);
$result = MpsPaymentsRefund::outcome(400, ['errors' => ['amount' => ['At most 14.95 EUR can still be refunded.']]]);
check('refund outcome: 400 is invalid, with what MPS said', MpsPaymentsRefund::INVALID === $result['outcome'] && 'At most 14.95 EUR can still be refunded.' === $result['detail']);
check('refund outcome: 400 without errors still says something', MpsPaymentsRefund::INVALID === MpsPaymentsRefund::outcome(400, [])['outcome'] && '' !== MpsPaymentsRefund::outcome(400, [])['detail']);
check('refund outcome: 401 is not permitted', MpsPaymentsRefund::NOT_PERMITTED === MpsPaymentsRefund::outcome(401, [])['outcome']);
check('refund outcome: 403 is not permitted', MpsPaymentsRefund::NOT_PERMITTED === MpsPaymentsRefund::outcome(403, [])['outcome']);
check('refund outcome: 404 is not found', MpsPaymentsRefund::NOT_FOUND === MpsPaymentsRefund::outcome(404, [])['outcome']);
check('refund outcome: 409 is not yet', MpsPaymentsRefund::NOT_YET === MpsPaymentsRefund::outcome(409, [])['outcome']);
check('refund outcome: a 500 is unknown', MpsPaymentsRefund::UNKNOWN === MpsPaymentsRefund::outcome(500, [])['outcome']);
check('refund outcome: a 201 without data is unknown, never refunded', MpsPaymentsRefund::UNKNOWN === MpsPaymentsRefund::outcome(201, [])['outcome']);

check('test key: an API key starting mps_test_', MpsPaymentsApi::isTestKey('mps_test_abcdef123456_secret'));
check('test key: not a live API key', !MpsPaymentsApi::isTestKey('mps_live_abcdef123456_secret'));
check('test key: not a client ID starting test_, which is no longer a credential', !MpsPaymentsApi::isTestKey('test_BE0000000000'));
check('test key: not an empty key', !MpsPaymentsApi::isTestKey(''));

echo PHP_EOL . (0 === $failures ? 'All passed.' : "$failures failed.") . PHP_EOL;
exit(0 === $failures ? 0 : 1);
