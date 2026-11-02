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
require __DIR__ . '/../mps-payments/Model/PaymentInfo.php';
require __DIR__ . '/magento.php';
require __DIR__ . '/../mps-payments/Model/Config.php';
require __DIR__ . '/../mps-payments/Model/GatewayException.php';
require __DIR__ . '/../mps-payments/Model/Api.php';
require __DIR__ . '/../mps-payments/Model/Refund.php';
require __DIR__ . '/../mps-payments/Model/Refunder.php';
require __DIR__ . '/../mps-payments/Model/System/MissingApiKey.php';
require __DIR__ . '/../mps-payments/Setup/Patch/Data/RemoveClientCredentials.php';

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\CurlFactory;
use Mps\Payments\Model\Api;
use Mps\Payments\Model\CallbackUrl;
use Mps\Payments\Model\Config;
use Mps\Payments\Model\DebugLog;
use Mps\Payments\Model\GatewayException;
use Mps\Payments\Model\Notification;
use Mps\Payments\Model\Outcome;
use Mps\Payments\Model\PaymentInfo;
use Mps\Payments\Model\Refund;
use Mps\Payments\Model\Refunder;
use Mps\Payments\Model\Signature;
use Mps\Payments\Model\System\MissingApiKey;
use Mps\Payments\Setup\Patch\Data\RemoveClientCredentials;

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

// The credentials: the API key is sent as the bearer on every call. There is nothing else to connect with: a
// client ID and secret (before 1.0.0) are not read, and no token is ever requested.
function config(array $settings): Config
{
    return new Config(new class ($settings) implements ScopeConfigInterface {
        public function __construct(private readonly array $settings)
        {
        }

        public function getValue($path, $scopeType = 'default', $scopeCode = null)
        {
            return $this->settings[substr($path, strlen('payment/mps_payments/'))] ?? null;
        }
    });
}

function api(array $settings, CurlFactory $http): Api
{
    return new Api(config($settings + ['gateway_url' => 'https://gateway.example.com']), $http, new DebugLog());
}

$apiKey = 'mps_test_abcdef123456_c2VjcmV0X3dpdGhfdW5kZXJzY29yZXM';

$http = new CurlFactory();
$http->answers = [[201, '{"data":{"paymentReference":"p1","checkoutUrl":"https://pay"}}']];
$opened = api(['api_key' => $apiKey], $http)->createPayment(['amount' => 100, 'reference' => '000000042'], 'magento-000000042-k1', null);
check('api key: the payment is opened with the key as the bearer', 'Bearer ' . $apiKey === $http->requests[0]->headers['Authorization'] && 'https://gateway.example.com/api/payments/internet/create' === $http->requests[0]->url);
check('api key: one request, nothing before it', 1 === count($http->requests) && 'p1' === $opened['paymentReference']);

// Opening a payment: the order's Idempotency-Key, made when it is placed, goes with every request for it, so
// a redirect asked for twice gets the one payment; the next order (after a failed or abandoned one) has its own.
$key = PaymentInfo::idempotencyKey('000000042');
check('payment key: the order number and a random part', 1 === preg_match('/^magento-000000042-[0-9a-f]{32}$/', $key));
check('payment key: a new order after a failed payment has another, even for the same order number', PaymentInfo::idempotencyKey('000000043') !== $key && PaymentInfo::idempotencyKey('000000042') !== $key);
check('payment key: at most 255 characters for the longest order number', strlen(PaymentInfo::idempotencyKey(str_repeat('9', 32))) <= 255);

DebugLog::$lines = [];
$http = new CurlFactory();
$http->answers = [[201, '{"data":{"paymentReference":"p1","checkoutUrl":"https://pay/secret-link"}}']];
api(['api_key' => $apiKey], $http)->createPayment(['amount' => 100, 'reference' => '000000042'], $key, null);
check('payment call: the Idempotency-Key header', $key === ($http->requests[0]->headers['Idempotency-Key'] ?? null));
check('payment call: the key is logged, the checkoutUrl never', [] !== array_filter(DebugLog::$lines, static fn ($l) => str_contains($l, $key)) && [] === array_filter(DebugLog::$lines, static fn ($l) => str_contains($l, 'secret-link')));

$http = new CurlFactory();
$http->answers = [[409, ''], [201, '{"data":{"paymentReference":"p1","checkoutUrl":"https://pay"}}']];
$opened = api(['api_key' => $apiKey], $http)->createPayment(['amount' => 100, 'reference' => '000000042'], $key, null);
check('payment call: a 409 (the same key still being opened) is sent again once and answers with the payment', 2 === count($http->requests) && 'p1' === $opened['paymentReference']);
check('payment call: the resend carries the same key and the same body', $key === $http->requests[1]->headers['Idempotency-Key'] && $http->requests[0]->body === $http->requests[1]->body);

$http = new CurlFactory();
$http->answers = [[400, '{"errors":{"Idempotency-Key":["reused with another body"]}}']];
try {
    api(['api_key' => $apiKey], $http)->createPayment(['amount' => 100, 'reference' => '000000042'], $key, null);
    check('payment call: any other refusal is not sent again', false);
} catch (GatewayException $e) {
    check('payment call: any other refusal is not sent again', 400 === $e->getCode() && 1 === count($http->requests));
}

$http = new CurlFactory();
$http->answers = [[200, '{"data":{"state":"executed"}}']];
api(['api_key' => $apiKey, 'client_id' => 'BE0000000000', 'client_secret' => 'secret'], $http)->getPayment('p1', null);
check('api key: a client ID and secret left over beside it are not used', 1 === count($http->requests) && 'Bearer ' . $apiKey === $http->requests[0]->headers['Authorization']);

$http = new CurlFactory();
$http->answers = [[401, ''], [401, '']];
try {
    api(['api_key' => $apiKey], $http)->getPayment('p1', null);
    check('api key: a 401 is an error', false);
} catch (GatewayException) {
    check('api key: a 401 is not retried', 1 === count($http->requests));
}

$http = new CurlFactory();
$http->answers = [[404, '']];
try {
    api(['api_key' => $apiKey], $http)->verifyCredentials(null);
    check('api key: verified by a read MPS answers 404', 1 === count($http->requests) && 'GET' === $http->requests[0]->method && 'Bearer ' . $apiKey === $http->requests[0]->headers['Authorization']);
} catch (GatewayException) {
    check('api key: verified by a read MPS answers 404', false);
}

foreach ([401, 403] as $refused) {
    $http = new CurlFactory();
    $http->answers = [[$refused, '']];
    try {
        api(['api_key' => $apiKey], $http)->verifyCredentials(null);
        check("api key: a $refused on the check is a refused key", false);
    } catch (GatewayException $e) {
        check("api key: a $refused on the check is a refused key", str_contains($e->getMessage(), (string) $refused));
    }
}

foreach ([[], ['client_id' => 'BE0000000000', 'client_secret' => 'secret']] as $settings) {
    $http = new CurlFactory();
    $http->answers = [[200, '{"access_token":"token-1","expires_in":900}'], [200, '{"data":{"state":"pending"}}']];
    $label = [] === $settings ? 'no API key' : 'only a client ID and secret';
    try {
        api($settings, $http)->getPayment('p1', null);
        check("$label: a call is refused", false);
    } catch (GatewayException $e) {
        check("$label: a call is refused before anything is sent, no token requested", [] === $http->requests && str_contains($e->getMessage(), 'API key'));
    }
}

check('credentials: an API key', config(['api_key' => $apiKey])->hasCredentials());
check('credentials: a client ID and secret are none', !config(['client_id' => 'BE0000000000', 'client_secret' => 'secret'])->hasCredentials());
check('credentials: nothing is none', !config([])->hasCredentials());

check('test key: an API key starting mps_test_', config(['api_key' => 'mps_test_abcdef123456_secret'])->isTestKey());
check('test key: not a live API key, whatever the client ID', !config(['api_key' => 'mps_live_abcdef123456_secret', 'client_id' => 'test_BE0000000000'])->isTestKey());
check('test key: a client ID starting test_ is no key at all', !config(['client_id' => 'test_BE0000000000', 'client_secret' => 'secret'])->isTestKey());

// A store left with the method enabled and no API key (a client ID and secret were removed by the 1.0.0 data
// patch) is not offered at checkout; the admin says so on every page until an API key is pasted.
check('api key missing: enabled without an API key', config(['active' => '1', 'client_id' => 'BE0000000000', 'client_secret' => 'secret'])->isMissingApiKey());
check('api key missing: not when an API key is set', !config(['active' => '1', 'api_key' => $apiKey])->isMissingApiKey());
check('api key missing: not when the method is off', !config(['active' => '0'])->isMissingApiKey() && !config([])->isMissingApiKey());

function stores(array $perStore): \Magento\Store\Model\StoreManagerInterface
{
    return new \Magento\Store\Model\StoreManagerInterface(array_keys($perStore));
}

function perStoreConfig(array $perStore): Config
{
    return new Config(new class ($perStore) implements ScopeConfigInterface {
        public function __construct(private readonly array $perStore)
        {
        }

        public function getValue($path, $scopeType = 'default', $scopeCode = null)
        {
            return $this->perStore[$scopeCode][substr($path, strlen('payment/mps_payments/'))] ?? null;
        }
    });
}

$perStore = [1 => ['active' => '1', 'api_key' => $apiKey], 2 => ['active' => '1']];
$message = new MissingApiKey(perStoreConfig($perStore), stores($perStore));
check('admin message: shown when one store is enabled without an API key', $message->isDisplayed());
check('admin message: asks for an API key and says a client ID and secret are no longer accepted', str_contains((string) $message->getText(), 'API key') && str_contains((string) $message->getText(), 'client ID and secret') && str_contains((string) $message->getText(), 'Online › Get started'));
check('admin message: major, with a stable identity', 2 === $message->getSeverity() && $message->getIdentity() === (new MissingApiKey(perStoreConfig($perStore), stores($perStore)))->getIdentity());
$perStore = [1 => ['active' => '1', 'api_key' => $apiKey], 2 => ['active' => '0']];
check('admin message: not shown when every enabled store has an API key', !(new MissingApiKey(perStoreConfig($perStore), stores($perStore)))->isDisplayed());

// Upgrading to 1.0.0 removes the client ID and secret from every scope, and the tokens they bought from the
// cache, so no secret of the old setup lingers in the shop.
$setup = new \Magento\Framework\Setup\ModuleDataSetupInterface([
    ['path' => 'payment/mps_payments/client_id', 'value' => ' BE0000000000 '],
    ['path' => 'payment/mps_payments/client_secret', 'value' => 'encrypted:secret'],
    ['path' => 'payment/mps_payments/gateway_url', 'value' => 'https://gateway.mps-emi.dev/'],
]);
$cache = new CacheInterface();
$oldToken = static fn (string $gateway): string => 'mps_payments_token_' . hash('sha256', $gateway . '|BE0000000000|secret');
$cache->save('token-1', $oldToken('https://gateway.mps-emi.dev'));
$cache->save('token-2', $oldToken('https://gateway.mps-emi.com'));
$cache->save('3', 'mps_payments_refund_attempts_x');
$patch = new RemoveClientCredentials($setup, $cache, new \Magento\Framework\Encryption\EncryptorInterface());
check('upgrade: the patch returns itself and depends on nothing', $patch === $patch->apply() && [] === RemoveClientCredentials::getDependencies() && [] === $patch->getAliases());
check('upgrade: the client ID and secret are deleted in every scope', [['core_config_data', ['path IN (?)' => ['payment/mps_payments/client_id', 'payment/mps_payments/client_secret']]]] === $setup->connection->deleted);
check('upgrade: the tokens they bought are removed from the cache, for the configured gateway and the default one', !isset($cache->entries[$oldToken('https://gateway.mps-emi.dev')]) && !isset($cache->entries[$oldToken('https://gateway.mps-emi.com')]));
check('upgrade: nothing else is removed from the cache', '3' === ($cache->entries['mps_payments_refund_attempts_x'] ?? null));
$setup = new \Magento\Framework\Setup\ModuleDataSetupInterface([]);
(new RemoveClientCredentials($setup, new CacheInterface(), new \Magento\Framework\Encryption\EncryptorInterface()))->apply();
check('upgrade: a shop that never had a client ID still has the delete run, harmlessly', 1 === count($setup->connection->deleted));

// Refunds: a credit memo refunded online is a POST /api/payments/{paymentReference}/refunds for its total in
// cents, with an Idempotency-Key derived from it, and what MPS answers decides whether the credit memo is saved.
function refunder(array $settings, CurlFactory $http, ?CacheInterface $cache = null): Refunder
{
    $config = config($settings + ['gateway_url' => 'https://gateway.example.com']);

    return new Refunder(new Api($config, $http, new DebugLog()), $config, $cache ?? new CacheInterface(), new DebugLog());
}

/** Refunds and returns the exception's message, or null when it was accepted. */
function refusal(Refunder $refunder, string $reference = 'p1', float|string $amount = '19.99', float|string $before = 0): ?string
{
    try {
        $refunder->refund($reference, $amount, $before, null);
        return null;
    } catch (LocalizedException $e) {
        return $e->getMessage();
    }
}

function refunded(string $status, array $extra = []): array
{
    return [201, json_encode(['data' => $extra + ['refundId' => 'r1', 'paymentReference' => 'p1', 'status' => $status, 'amount' => 1999, 'currency' => 'EUR', 'reason' => null, 'failureReason' => null, 'cancelableUntil' => null, 'createdAt' => '2026-10-22T09:14:03Z']])];
}

check('refund cents: 19.99 is 1999', 1999 === Outcome::cents(19.99));
check('refund cents: 0.29 is 29', 29 === Outcome::cents('0.29'));
check('refund cents: 1.005 rounds to 101', 101 === Outcome::cents('1.0050'));
check('refund request: the amount in cents and the reason, no payment reference (it is in the path)', ['amount' => 1999, 'reason' => 'requested_by_customer'] === Refund::request(1999));

check('refund key: from the payment, what was refunded before and the amount', 'magento-p1-refund-0-1999' === Refund::idempotencyKey('p1', 0, 1999, 0));
check('refund key: a retry of the same credit memo has the same key', Refund::idempotencyKey('p1', 1001, 500, 0) === Refund::idempotencyKey('p1', 1001, 500, 0));
check('refund key: a second credit memo for the same amount has another', Refund::idempotencyKey('p1', 0, 500, 0) !== Refund::idempotencyKey('p1', 500, 500, 0));
check('refund key: a new attempt after a refused refund has another', 'magento-p1-refund-0-1999-2' === Refund::idempotencyKey('p1', 0, 1999, 1));
check('refund key: at most 255 characters', strlen(Refund::idempotencyKey(str_repeat('x', 300), 0, 1, 0)) <= 255);

check('refund status: pending', Refund::PENDING === Refund::status(['status' => 'pending']));
check('refund status: refunded', Refund::REFUNDED === Refund::status(['status' => 'refunded']));
check('refund status: failed', Refund::FAILED === Refund::status(['status' => 'failed']));
check('refund status: canceled', Refund::CANCELED === Refund::status(['status' => 'canceled']));
check('refund status: an unknown status is pending', Refund::PENDING === Refund::status(['status' => 'settling']));
check('refund status: none is pending', Refund::PENDING === Refund::status([]));

check('refund time: cancelableUntil shown in UTC to the minute', '2026-10-22 13:14 UTC' === Refund::time('2026-10-22T13:14:03Z'));
check('refund time: an offset is converted to UTC', '2026-10-22 13:14 UTC' === Refund::time('2026-10-22T15:14:03+02:00'));
check('refund time: none is empty', '' === Refund::time(null) && '' === Refund::time('not a time'));

check('refund errors: the messages MPS names', 'Amount exceeds the 14.95 still refundable.' === Refund::errors('{"errors":{"amount":["Amount exceeds the 14.95 still refundable."]}}'));
check('refund errors: several joined', 'a b' === Refund::errors('{"errors":{"amount":["a"],"reason":["b"]}}'));
check('refund errors: a problem detail', 'Nothing left' === Refund::errors('{"title":"Bad request","detail":"Nothing left"}'));
check('refund errors: not JSON is empty', '' === Refund::errors('<html>'));

check('refund details: what the transaction keeps', ['refundId' => 'r1', 'status' => 'pending', 'amount' => 1999, 'currency' => 'EUR', 'cancelableUntil' => '2026-10-22T13:14:03Z'] === Refund::details(['refundId' => 'r1', 'status' => 'pending', 'amount' => 1999, 'currency' => 'EUR', 'cancelableUntil' => '2026-10-22T13:14:03Z', 'failureReason' => null, 'checkoutUrl' => 'https://x']));

DebugLog::$lines = [];
$http = new CurlFactory();
$http->answers = [refunded('pending')];
$refund = refunder(['api_key' => $apiKey], $http)->refund('p1', 19.99, 0, null);
$sent = $http->requests[0];
check('refund call: POST /api/payments/{paymentReference}/refunds', 'POST' === $sent->method && 'https://gateway.example.com/api/payments/p1/refunds' === $sent->url);
check('refund call: the API key is the bearer', 'Bearer ' . $apiKey === $sent->headers['Authorization']);
check('refund call: JSON body with the amount in cents and the reason only', ['amount' => 1999, 'reason' => 'requested_by_customer'] === json_decode($sent->body, true) && 'application/json' === $sent->headers['Content-Type']);
check('refund call: the Idempotency-Key header', 'magento-p1-refund-0-1999' === ($sent->headers['Idempotency-Key'] ?? null));
check('refund call: the refund is returned', 'r1' === $refund['refundId'] && 'pending' === $refund['status']);
check('refund call: the API key is never logged', [] !== DebugLog::$lines && [] === array_filter(DebugLog::$lines, static fn ($l) => str_contains($l, $apiKey)));

$http = new CurlFactory();
$http->answers = [refunded('pending')];
refunder(['api_key' => $apiKey], $http)->refund('p1', '5.00', '10.01', null);
check('refund call: a second refund of the order has its own key', 'magento-p1-refund-1001-500' === $http->requests[0]->headers['Idempotency-Key']);

$http = new CurlFactory();
$http->answers = [refunded('pending')];
refunder(['api_key' => $apiKey], $http)->refund('test_a/b c?d', 19.99, 0, null);
check('refund call: the payment reference is encoded as one path segment', 'https://gateway.example.com/api/payments/test_a%2Fb%20c%3Fd/refunds' === $http->requests[0]->url && !array_key_exists('paymentReference', json_decode($http->requests[0]->body, true)));

$http = new CurlFactory();
$http->answers = [[200, '{"access_token":"token-1","expires_in":900}'], refunded('pending')];
$message = refusal(refunder(['client_id' => 'BE0000000000', 'client_secret' => 'secret'], $http));
check('refund call: without an API key (a client ID and secret are not used) it is refused without a call, asking for an API key', null !== $message && [] === $http->requests && str_contains($message, 'API key') && str_contains($message, 'Online › Get started'));

$r = refunder([], new CurlFactory());
check('refund comment: a pending refund without cancelableUntil is being processed', 'MPS refund r1 is being processed.' === (string) $r->comment(['refundId' => 'r1', 'status' => 'pending']));
check('refund comment: a pending refund is sent after cancelableUntil and can be canceled in MPS until then', 'MPS refund r1 is being processed. It is sent after 2026-10-22 13:14 UTC and can be canceled in MPS until then.' === (string) $r->comment(['refundId' => 'r1', 'status' => 'pending', 'cancelableUntil' => '2026-10-22T13:14:03Z']));
check('refund comment: refunded', 'MPS refund r1 refunded.' === (string) $r->comment(['refundId' => 'r1', 'status' => 'refunded']));

$http = new CurlFactory();
$http->answers = [refunded('refunded')];
check('refund outcome: refunded is accepted', null === refusal(refunder(['api_key' => $apiKey], $http)));

$http = new CurlFactory();
$http->answers = [refunded('settling')];
check('refund outcome: an unknown status is accepted as pending', null === refusal(refunder(['api_key' => $apiKey], $http)));

$cache = new CacheInterface();
$http = new CurlFactory();
$http->answers = [refunded('failed', ['failureReason' => 'declined']), refunded('pending')];
$message = refusal(refunder(['api_key' => $apiKey], $http, $cache));
check('refund outcome: failed is refused, nothing refunded', null !== $message && str_contains($message, 'MPS refused the refund (declined)') && str_contains($message, 'Nothing was refunded'));
refusal(refunder(['api_key' => $apiKey], $http, $cache));
check('refund outcome: after a failed refund the next try is a new refund, not the failed one again', 'magento-p1-refund-0-1999-2' === $http->requests[1]->headers['Idempotency-Key']);

$http = new CurlFactory();
$http->answers = [refunded('canceled')];
$message = refusal(refunder(['api_key' => $apiKey], $http));
check('refund outcome: canceled is refused', null !== $message && str_contains($message, 'canceled') && str_contains($message, 'Nothing was refunded'));

$http = new CurlFactory();
$http->answers = [[400, '{"errors":{"amount":["Amount exceeds the 14.95 still refundable."]}}']];
$message = refusal(refunder(['api_key' => $apiKey], $http));
check('refund outcome: 400 is refused with what MPS says', null !== $message && str_contains($message, 'MPS refused the refund: Amount exceeds the 14.95 still refundable.'));

$http = new CurlFactory();
$http->answers = [[403, '']];
$message = refusal(refunder(['api_key' => $apiKey], $http));
check('refund outcome: 403 names the payments:refund permission, that MPS grants it to a live key, and that a test key has it', null !== $message && str_contains($message, 'payments:refund') && str_contains($message, 'ask MPS') && str_contains($message, 'test key always'));

$http = new CurlFactory();
$http->answers = [[401, '']];
$message = refusal(refunder(['api_key' => $apiKey], $http));
check('refund outcome: 401 is a refused key', null !== $message && str_contains($message, 'did not accept the API key'));

$http = new CurlFactory();
$http->answers = [[404, '']];
$message = refusal(refunder(['api_key' => $apiKey], $http));
check('refund outcome: 404 is an unknown payment for this key, test and live apart', null !== $message && str_contains($message, 'p1') && str_contains($message, 'test key'));

$http = new CurlFactory();
$http->answers = [[409, '']];
$message = refusal(refunder(['api_key' => $apiKey], $http));
check('refund outcome: 409 is not paid yet or busy: try again', null !== $message && str_contains($message, 'Try again'));

$cache = new CacheInterface();
$http = new CurlFactory();
$http->answers = [new \RuntimeException('timed out'), refunded('pending')];
$message = refusal(refunder(['api_key' => $apiKey], $http, $cache));
check('refund outcome: no answer may have refunded: try again, never twice', null !== $message && str_contains($message, 'may still have been made') && str_contains($message, 'never refunded twice'));
refusal(refunder(['api_key' => $apiKey], $http, $cache));
check('refund outcome: the retry after no answer carries the same key', $http->requests[0]->headers['Idempotency-Key'] === $http->requests[1]->headers['Idempotency-Key']);

$http = new CurlFactory();
$http->answers = [[502, '']];
check('refund outcome: a 5xx may have refunded: try again', str_contains((string) refusal(refunder(['api_key' => $apiKey], $http)), 'may still have been made'));

$http = new CurlFactory();
$http->answers = [[201, '{"data":{}}']];
check('refund outcome: an answer without a refund id is refused', null !== refusal(refunder(['api_key' => $apiKey], $http)));

$http = new CurlFactory();
$message = refusal(refunder(['api_key' => $apiKey], $http), '');
check('refund outcome: an order without an MPS payment is refused without a call', null !== $message && [] === $http->requests);

$http = new CurlFactory();
$message = refusal(refunder(['api_key' => $apiKey], $http), 'p1', '0.00');
check('refund outcome: nothing to refund is refused without a call', null !== $message && [] === $http->requests);

echo PHP_EOL . (0 === $failures ? 'All passed.' : "$failures failed.") . PHP_EOL;
exit(0 === $failures ? 0 : 1);
