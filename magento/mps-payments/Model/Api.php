<?php
declare(strict_types=1);

namespace Mps\Payments\Model;

use Magento\Framework\HTTP\Client\CurlFactory;

/**
 * The MPS payments gateway, as this module uses it: create an internet payment, read it back, refund it.
 *
 * Every call carries the API key as the bearer; there is no other way in and no token to fetch. Everything
 * else about the merchant — which wallet, which shop name the shopper sees — comes from the key, never from a
 * request, so there is nothing here to configure beyond it.
 */
class Api
{
    private const TIMEOUT = 20;

    /** A payment reference no payment has, read to prove an API key: MPS answers 404 to a key it accepts. */
    private const CREDENTIAL_CHECK_REFERENCE = 'mps-credential-check';

    public function __construct(
        private readonly Config $config,
        private readonly CurlFactory $curlFactory,
        private readonly DebugLog $log,
    ) {
    }

    /**
     * Opens a payment on the hosted checkout. The key makes a resent request for the same order answer with
     * the payment the first one opened instead of opening another.
     *
     * @param array $body amount (cents), reference, successUrl, failureUrl and optionally callbackUrl.
     * @return array paymentReference and checkoutUrl.
     * @throws GatewayException When the gateway refuses or cannot be reached.
     */
    public function createPayment(array $body, string $idempotencyKey, ?int $storeId): array
    {
        $headers = ['Idempotency-Key' => $idempotencyKey];

        try {
            return $this->call('POST', '/api/payments/internet/create', $body, $storeId, $headers);
        } catch (GatewayException $e) {
            if (409 !== $e->getCode()) {
                throw $e;
            }

            // MPS is still opening the payment for this key (the redirect asked for twice): once it has, the
            // same request answers with that payment.
            sleep(1);
            return $this->call('POST', '/api/payments/internet/create', $body, $storeId, $headers);
        }
    }

    /**
     * Reads a payment back: the only authority on whether it was paid.
     *
     * @return array paymentReference, state, amount, currency and reference.
     * @throws GatewayException When the gateway refuses or cannot be reached.
     */
    public function getPayment(string $paymentReference, ?int $storeId): array
    {
        return $this->call('GET', '/api/payments/internet/' . rawurlencode($paymentReference), null, $storeId);
    }

    /**
     * Refunds (part of) a payment: POST /api/payments/{paymentReference}/refunds. The key makes a resent request
     * answer with the first refund instead of refunding again.
     *
     * @param array $body amount (cents) and reason (Model\Refund::request).
     * @return array refundId, paymentReference, status, amount, currency, failureReason, cancelableUntil …
     * @throws GatewayException When the gateway refuses (with its answer) or cannot be reached.
     */
    public function createRefund(string $paymentReference, array $body, string $idempotencyKey, ?int $storeId): array
    {
        return $this->call('POST', '/api/payments/' . rawurlencode($paymentReference) . '/refunds', $body, $storeId, ['Idempotency-Key' => $idempotencyKey]);
    }

    /**
     * Proves the API key when it is saved: a payment that does not exist is read with it, and 404 means MPS
     * accepted the key.
     *
     * @throws GatewayException When the key is refused.
     */
    public function verifyCredentials(?int $storeId): void
    {
        try {
            $this->getPayment(self::CREDENTIAL_CHECK_REFERENCE, $storeId);
        } catch (GatewayException $e) {
            match ($e->getCode()) {
                404 => null,
                401, 403 => throw new GatewayException(sprintf('MPS refused the API key (%d)', $e->getCode()), 0, $e),
                default => throw $e,
            };
        }
    }

    /**
     * @param array $headers Further request headers.
     * @return array The response's data.
     * @throws GatewayException On anything other than 2xx, and without an API key before anything is sent.
     */
    private function call(string $method, string $path, ?array $body, ?int $storeId, array $headers = []): array
    {
        $apiKey = $this->config->apiKey($storeId);
        if ('' === $apiKey) {
            throw new GatewayException('No MPS API key is configured');
        }

        $curl = $this->curlFactory->create();
        $curl->setTimeout(self::TIMEOUT);
        $curl->addHeader('Authorization', 'Bearer ' . $apiKey);
        $curl->addHeader('Accept', 'application/json');
        foreach ($headers as $name => $value) {
            $curl->addHeader($name, $value);
        }

        $url = $this->config->gatewayUrl($storeId) . $path;
        $started = microtime(true);

        try {
            if (null === $body) {
                $curl->get($url);
            } else {
                $curl->addHeader('Content-Type', 'application/json');
                $curl->post($url, json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            }
        } catch (\Exception $e) {
            $this->log->log(sprintf('%s %s failed after %d ms: %s', $method, $path, self::since($started), $e->getMessage()), $storeId, 'error');
            throw new GatewayException('MPS gateway unreachable: ' . $e->getMessage(), 0, $e);
        }

        $status = $curl->getStatus();

        // A 401 for an API key is final: it does not expire, so there is nothing to renew and retry.
        if ($status < 200 || $status >= 300) {
            $this->log->log(sprintf('%s %s answered %d in %d ms: %s', $method, $path, $status, self::since($started), $curl->getBody()), $storeId, 'warning');
            throw new GatewayException(sprintf('MPS gateway answered %d on %s %s', $status, $method, $path), $status, null, $curl->getBody());
        }

        $decoded = json_decode($curl->getBody(), true);
        $data = is_array($decoded) && is_array($decoded['data'] ?? null) ? $decoded['data'] : [];

        $this->log->log(sprintf('%s %s answered %d in %d ms%s', $method, $path, $status, self::since($started), self::describe($body, $data, $headers['Idempotency-Key'] ?? null)), $storeId);

        return $data;
    }

    private static function since(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    /**
     * What a successful call was about, for the log. Deliberately a whitelist: the API key, the secrets,
     * checkoutUrl (which pays the payment) and the return URLs (which carry the order's protect code) never
     * appear. An Idempotency-Key is not secret: a payment's is logged here, a refund's by Model\Refunder.
     */
    private static function describe(?array $request, array $response, ?string $idempotencyKey): string
    {
        $parts = [];

        // A payment opened (a refund's request is all in its answer).
        if (isset($request['reference'])) {
            $parts[] = sprintf('amount %d, reference %s, webhook %s, key %s', $request['amount'], $request['reference'], isset($request['callbackUrl']) ? 'yes' : 'no', $idempotencyKey ?? 'none');
        }

        foreach (['paymentReference', 'refundId', 'state', 'status', 'amount', 'currency', 'failureReason', 'cancelableUntil'] as $field) {
            if (isset($response[$field]) && is_scalar($response[$field])) {
                $parts[] = $field . ' ' . $response[$field];
            }
        }

        return [] === $parts ? '' : ': ' . implode(', ', $parts);
    }
}
