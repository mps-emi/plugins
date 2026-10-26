<?php
/**
 * Copyright since 2026 Mobile Payment Solutions
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 *
 * @author    Mobile Payment Solutions
 * @copyright Since 2026 Mobile Payment Solutions
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
/**
 * The MPS payments gateway, as this module uses it: a token, create an internet payment, read it back.
 *
 * Everything else about the merchant — which wallet, which shop name the shopper sees — comes from the
 * credentials, never from a request, so there is nothing here to configure beyond them.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class MpsPaymentsApi
{
    const SCOPES = 'internet:create internet:read';

    const TIMEOUT = 20;

    /** @var string */
    private $baseUrl;

    /** @var string */
    private $clientId;

    /** @var string */
    private $clientSecret;

    /** @var callable */
    private $log;

    /**
     * @param string   $baseUrl      The gateway, e.g. https://gateway.mps-emi.com.
     * @param string   $clientId     The API client id (a test key's starts with test_).
     * @param string   $clientSecret Its secret.
     * @param callable $log          Receives ( message, severity ): 1 worked, 2 refused, 3 no answer.
     */
    public function __construct($baseUrl, $clientId, $clientSecret, callable $log)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->log = $log;
    }

    /**
     * Opens a payment on the hosted checkout.
     *
     * @param array $body amount (cents), reference, successUrl, failureUrl and optionally callbackUrl.
     *
     * @return array paymentReference and checkoutUrl.
     *
     * @throws Exception When the gateway refuses or cannot be reached.
     */
    public function createPayment(array $body)
    {
        return $this->call('POST', '/api/payments/internet/create', $body);
    }

    /**
     * Reads a payment back: the only authority on whether it was paid.
     *
     * @param string $paymentReference As returned by create.
     *
     * @return array paymentReference, state, amount, currency and reference.
     *
     * @throws Exception When the gateway refuses or cannot be reached.
     */
    public function getPayment($paymentReference)
    {
        return $this->call('GET', '/api/payments/internet/' . rawurlencode($paymentReference));
    }

    /**
     * Fetches a fresh token, bypassing the cache — proves the credentials when they are saved.
     *
     * @throws Exception When the credentials are refused.
     */
    public function verifyCredentials()
    {
        Configuration::deleteByName($this->tokenKey());
        $this->token();
    }

    /**
     * @param string     $method HTTP method.
     * @param string     $path   Path under the gateway.
     * @param array|null $body   JSON body, if any.
     * @param bool       $retry  Whether a 401 may fetch a new token and try once more.
     *
     * @return array The response's data.
     *
     * @throws Exception On anything other than 2xx.
     */
    private function call($method, $path, $body = null, $retry = true)
    {
        $headers = ['Authorization: Bearer ' . $this->token(), 'Accept: application/json'];
        if (null !== $body) {
            $headers[] = 'Content-Type: application/json';
        }

        $started = microtime(true);
        list($status, $responseBody, $error) = $this->send($method, $this->baseUrl . $path, $headers, null === $body ? null : json_encode($body, JSON_UNESCAPED_SLASHES));
        $elapsed = (int) round((microtime(true) - $started) * 1000);

        if (null !== $error) {
            $this->log(sprintf('%s %s failed after %d ms: %s', $method, $path, $elapsed, $error), 3);
            throw new Exception('MPS gateway unreachable: ' . $error);
        }

        // A token can expire between being cached and being used; one new token, one retry.
        if (401 === $status && $retry) {
            Configuration::deleteByName($this->tokenKey());

            return $this->call($method, $path, $body, false);
        }

        if ($status < 200 || $status >= 300) {
            $this->log(sprintf('%s %s answered %d in %d ms: %s', $method, $path, $status, $elapsed, $responseBody), 2);
            throw new Exception(sprintf('MPS gateway answered %d on %s %s', $status, $method, $path));
        }

        $decoded = json_decode($responseBody, true);
        $data = is_array($decoded) && isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : [];

        $this->log(sprintf('%s %s answered %d in %d ms%s', $method, $path, $status, $elapsed, self::describe($body, $data)), 1);

        return $data;
    }

    /**
     * A token for these credentials, from the cache while it lasts. The token endpoint is rate limited
     * far more tightly than the payment endpoints; one token serves every payment for 15 minutes.
     *
     * @return string
     *
     * @throws Exception When the credentials are refused.
     */
    private function token()
    {
        $cached = json_decode((string) Configuration::getGlobalValue($this->tokenKey()), true);
        if (is_array($cached) && isset($cached['token'], $cached['expires']) && $cached['expires'] > time()) {
            return $cached['token'];
        }

        list($status, $responseBody, $error) = $this->send('POST', $this->baseUrl . '/connect/token', ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope' => self::SCOPES,
        ]));

        if (null !== $error) {
            $this->log('Token request failed: ' . $error, 3);
            throw new Exception('MPS gateway unreachable: ' . $error);
        }

        $decoded = json_decode($responseBody, true);

        if (200 !== $status || empty($decoded['access_token'])) {
            $this->log(sprintf('Token request for %s answered %d: %s', $this->clientId, $status, $responseBody), 2);
            throw new Exception(sprintf('MPS refused the credentials (%d)', $status));
        }

        $lifetime = isset($decoded['expires_in']) ? (int) $decoded['expires_in'] : 900;
        $this->log(sprintf('New token for %s, valid %d s', $this->clientId, $lifetime), 1);

        // Expire a minute early so a token is never handed out in its last seconds.
        Configuration::updateGlobalValue($this->tokenKey(), json_encode(['token' => $decoded['access_token'], 'expires' => time() + max(60, $lifetime - 60)]));

        return $decoded['access_token'];
    }

    /**
     * Keyed by gateway, client and secret, so changing any of them never reuses a token for the old ones.
     */
    private function tokenKey()
    {
        return 'MPS_PAYMENTS_TOKEN_' . strtoupper(substr(hash('sha256', $this->baseUrl . '|' . $this->clientId . '|' . $this->clientSecret), 0, 16));
    }

    /**
     * @return array [status, body, error or null]
     */
    private function send($method, $url, array $headers, $body)
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        if (null !== $body) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($curl);
        $error = false === $responseBody ? curl_error($curl) : null;
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        // A no-op since PHP 8.0, where the handle is freed with the object, and deprecated in 8.5.
        if (PHP_VERSION_ID < 80000) {
            curl_close($curl);
        }

        return [$status, (string) $responseBody, $error];
    }

    /**
     * What a successful call was about, for the log. Deliberately a whitelist: the token, the secrets,
     * checkoutUrl (which pays the payment) and the return URLs never appear.
     */
    private static function describe($request, array $response)
    {
        $parts = [];

        if (is_array($request)) {
            $parts[] = sprintf('amount %d, reference %s, webhook %s', $request['amount'], $request['reference'], isset($request['callbackUrl']) ? 'yes' : 'no');
        }

        foreach (['paymentReference', 'state', 'amount', 'currency'] as $field) {
            if (isset($response[$field]) && is_scalar($response[$field])) {
                $parts[] = $field . ' ' . $response[$field];
            }
        }

        return empty($parts) ? '' : ': ' . implode(', ', $parts);
    }

    private function log($message, $severity)
    {
        call_user_func($this->log, $message, $severity);
    }
}
