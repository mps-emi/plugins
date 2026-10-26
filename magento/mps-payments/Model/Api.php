<?php
declare(strict_types=1);

namespace Mps\Payments\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\HTTP\Client\CurlFactory;

/**
 * The MPS payments gateway, as this module uses it: a token, create an internet payment, read it back.
 *
 * Everything else about the merchant — which wallet, which shop name the shopper sees — comes from the
 * credentials, never from a request, so there is nothing here to configure beyond them.
 */
class Api
{
    private const SCOPES = 'internet:create internet:read';

    private const TIMEOUT = 20;

    public function __construct(
        private readonly Config $config,
        private readonly CurlFactory $curlFactory,
        private readonly CacheInterface $cache,
        private readonly DebugLog $log,
    ) {
    }

    /**
     * Opens a payment on the hosted checkout.
     *
     * @param array $body amount (cents), reference, successUrl, failureUrl and optionally callbackUrl.
     * @return array paymentReference and checkoutUrl.
     * @throws GatewayException When the gateway refuses or cannot be reached.
     */
    public function createPayment(array $body, ?int $storeId): array
    {
        return $this->call('POST', '/api/payments/internet/create', $body, $storeId);
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
     * Fetches a fresh token, bypassing the cache — proves the credentials when they are saved.
     *
     * @throws GatewayException When the credentials are refused.
     */
    public function verifyCredentials(?int $storeId): void
    {
        $this->cache->remove($this->tokenKey($storeId));
        $this->token($storeId);
    }

    /**
     * @param bool $retry Whether a 401 may fetch a new token and try once more.
     * @return array The response's data.
     * @throws GatewayException On anything other than 2xx.
     */
    private function call(string $method, string $path, ?array $body, ?int $storeId, bool $retry = true): array
    {
        $curl = $this->curlFactory->create();
        $curl->setTimeout(self::TIMEOUT);
        $curl->addHeader('Authorization', 'Bearer ' . $this->token($storeId));
        $curl->addHeader('Accept', 'application/json');

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

        // A token can expire between being cached and being used; one new token, one retry.
        if (401 === $status && $retry) {
            $this->cache->remove($this->tokenKey($storeId));
            return $this->call($method, $path, $body, $storeId, false);
        }

        if ($status < 200 || $status >= 300) {
            $this->log->log(sprintf('%s %s answered %d in %d ms: %s', $method, $path, $status, self::since($started), $curl->getBody()), $storeId, 'warning');
            throw new GatewayException(sprintf('MPS gateway answered %d on %s %s', $status, $method, $path));
        }

        $decoded = json_decode($curl->getBody(), true);
        $data = is_array($decoded) && is_array($decoded['data'] ?? null) ? $decoded['data'] : [];

        $this->log->log(sprintf('%s %s answered %d in %d ms%s', $method, $path, $status, self::since($started), self::describe($body, $data)), $storeId);

        return $data;
    }

    /**
     * A token for these credentials, from the cache while it lasts. The token endpoint is rate limited
     * far more tightly than the payment endpoints; one token serves every payment for 15 minutes.
     *
     * @throws GatewayException When the credentials are refused.
     */
    private function token(?int $storeId): string
    {
        $cached = $this->cache->load($this->tokenKey($storeId));
        if (is_string($cached) && '' !== $cached) {
            return $cached;
        }

        $clientId = $this->config->clientId($storeId);
        $curl = $this->curlFactory->create();
        $curl->setTimeout(self::TIMEOUT);

        try {
            $curl->post($this->config->gatewayUrl($storeId) . '/connect/token', [
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $this->config->clientSecret($storeId),
                'scope' => self::SCOPES,
            ]);
        } catch (\Exception $e) {
            $this->log->log('Token request failed: ' . $e->getMessage(), $storeId, 'error');
            throw new GatewayException('MPS gateway unreachable: ' . $e->getMessage(), 0, $e);
        }

        $status = $curl->getStatus();
        $decoded = json_decode($curl->getBody(), true);

        if (200 !== $status || empty($decoded['access_token'])) {
            $this->log->log(sprintf('Token request for %s answered %d: %s', $clientId, $status, $curl->getBody()), $storeId, 'warning');
            throw new GatewayException(sprintf('MPS refused the credentials (%d)', $status));
        }

        $lifetime = (int) ($decoded['expires_in'] ?? 900);
        $this->log->log(sprintf('New token for %s, valid %d s', $clientId, $lifetime), $storeId);

        // Expire a minute early so a token is never handed out in its last seconds.
        $this->cache->save($decoded['access_token'], $this->tokenKey($storeId), [], max(60, $lifetime - 60));

        return $decoded['access_token'];
    }

    /**
     * Keyed by gateway, client and secret, so changing any of them never reuses a token for the old ones.
     */
    private function tokenKey(?int $storeId): string
    {
        return 'mps_payments_token_' . hash('sha256', $this->config->gatewayUrl($storeId) . '|' . $this->config->clientId($storeId) . '|' . $this->config->clientSecret($storeId));
    }

    private static function since(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    /**
     * What a successful call was about, for the log. Deliberately a whitelist: the token, the secrets,
     * checkoutUrl (which pays the payment) and the return URLs (which carry the order's protect code)
     * never appear.
     */
    private static function describe(?array $request, array $response): string
    {
        $parts = [];

        if (null !== $request) {
            $parts[] = sprintf('amount %d, reference %s, webhook %s', $request['amount'], $request['reference'], isset($request['callbackUrl']) ? 'yes' : 'no');
        }

        foreach (['paymentReference', 'state', 'amount', 'currency'] as $field) {
            if (isset($response[$field]) && is_scalar($response[$field])) {
                $parts[] = $field . ' ' . $response[$field];
            }
        }

        return [] === $parts ? '' : ': ' . implode(', ', $parts);
    }
}
