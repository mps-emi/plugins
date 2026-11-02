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
 * The MPS payments gateway, as this module uses it: create an internet payment, read it back, refund it.
 *
 * Everything else about the merchant — which wallet, which shop name the shopper sees — comes from the
 * API key, never from a request, so there is nothing here to configure beyond it. The key is the bearer of
 * every call; there is no token to fetch.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class MpsPaymentsApi
{
    const TIMEOUT = 20;

    /** What a test API key starts with; a live one starts mps_live_. */
    const TEST_API_KEY_PREFIX = 'mps_test_';

    /** A payment reference no payment has, read to prove an API key: MPS answers 404 to a key it accepts. */
    const CREDENTIAL_CHECK_REFERENCE = 'mps-credential-check';

    /** @var string */
    private $baseUrl;

    /** @var string The API key, sent as the bearer on every call. */
    private $apiKey;

    /** @var callable */
    private $log;

    /**
     * @param string   $baseUrl The gateway, e.g. https://gateway.mps-emi.com.
     * @param string   $apiKey  The API key: mps_live_…, or mps_test_… for a test key.
     * @param callable $log     Receives ( message, severity ): 1 worked, 2 refused, 3 no answer.
     */
    public function __construct($baseUrl, $apiKey, callable $log)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = trim((string) $apiKey);
        $this->log = $log;
    }

    /**
     * Opens a payment on the hosted checkout. Sent again with the same key and body, MPS answers with the
     * payment the first send opened, never a second one.
     *
     * @param array  $body           amount (cents), reference, successUrl, failureUrl and optionally callbackUrl.
     * @param string $idempotencyKey The checkout attempt's (MpsPaymentsRepository::newIdempotencyKey).
     *
     * @return array paymentReference and checkoutUrl.
     *
     * @throws Exception When the gateway refuses or cannot be reached.
     */
    public function createPayment(array $body, $idempotencyKey)
    {
        $headers = ['Idempotency-Key: ' . $idempotencyKey];

        try {
            return $this->call('POST', '/api/payments/internet/create', $body, $headers);
        } catch (Exception $e) {
            // 409: MPS is still opening the payment for the first send with this key; once more, a second later.
            if (409 !== $e->getCode()) {
                throw $e;
            }
            sleep(1);

            return $this->call('POST', '/api/payments/internet/create', $body, $headers);
        }
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
     * Refunds a payment, in full or in part: POST /api/payments/{paymentReference}/refunds. Unlike the
     * other calls it does not throw on a refusal: what MPS answered is the outcome (MpsPaymentsRefund::outcome).
     *
     * @param string $paymentReference The payment to refund, as returned by create.
     * @param array  $body             amount (cents) and reason (MpsPaymentsRefund::body).
     * @param string $idempotencyKey   The same for a resend of the same refund (MpsPaymentsRefund::idempotencyKey).
     *
     * @return array [HTTP status, decoded response body]
     *
     * @throws Exception When the gateway cannot be reached.
     */
    public function createRefund($paymentReference, array $body, $idempotencyKey)
    {
        list($status, $answer) = $this->exchange('POST', '/api/payments/' . rawurlencode($paymentReference) . '/refunds', $body, ['Idempotency-Key: ' . $idempotencyKey]);

        return [$status, $answer];
    }

    /**
     * Proves the API key when it is saved. There is no token to fetch, so a payment that does not exist is
     * read with it instead: 404 means MPS accepted the key.
     *
     * @throws Exception When there is no key, or MPS refuses it.
     */
    public function verifyCredentials()
    {
        if ('' === $this->apiKey) {
            throw new Exception('no API key');
        }

        try {
            $this->getPayment(self::CREDENTIAL_CHECK_REFERENCE);
        } catch (Exception $e) {
            if (404 === $e->getCode()) {
                return;
            }
            if (in_array($e->getCode(), [401, 403], true)) {
                throw new Exception(sprintf('MPS refused the API key (%d)', $e->getCode()));
            }
            throw $e;
        }
    }

    /**
     * Whether an API key is a test key: it starts mps_test_. A test key accepts only the MPS test cards,
     * charges nothing.
     *
     * @param string $apiKey
     *
     * @return bool
     */
    public static function isTestKey($apiKey)
    {
        return 0 === strpos((string) $apiKey, self::TEST_API_KEY_PREFIX);
    }

    /**
     * @param string     $method HTTP method.
     * @param string     $path   Path under the gateway.
     * @param array|null $body    JSON body, if any.
     * @param array      $headers Extra headers.
     *
     * @return array The response's data.
     *
     * @throws Exception On anything other than 2xx.
     */
    private function call($method, $path, $body = null, array $headers = [])
    {
        list($status, $decoded) = $this->exchange($method, $path, $body, $headers);

        if ($status < 200 || $status >= 300) {
            throw new Exception(sprintf('MPS gateway answered %d on %s %s', $status, $method, $path), $status);
        }

        return isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : [];
    }

    /**
     * One request, logged, whatever its status.
     *
     * @param string     $method  HTTP method.
     * @param string     $path    Path under the gateway.
     * @param array|null $body    JSON body, if any.
     * @param array      $headers Extra headers.
     *
     * @return array [HTTP status, decoded response body (an empty array when it is not JSON)]
     *
     * @throws Exception When the gateway cannot be reached.
     */
    private function exchange($method, $path, $body = null, array $headers = [])
    {
        $sent = array_merge(['Authorization: Bearer ' . $this->apiKey, 'Accept: application/json'], $headers);
        if (null !== $body) {
            $sent[] = 'Content-Type: application/json';
        }

        $started = microtime(true);
        list($status, $responseBody, $error) = $this->send($method, $this->baseUrl . $path, $sent, null === $body ? null : json_encode($body, JSON_UNESCAPED_SLASHES));
        $elapsed = (int) round((microtime(true) - $started) * 1000);

        if (null !== $error) {
            $this->log(sprintf('%s %s failed after %d ms%s: %s', $method, $path, $elapsed, self::idempotencyKey($headers), $error), 3);
            throw new Exception('MPS gateway unreachable: ' . $error);
        }

        $decoded = json_decode($responseBody, true);
        $decoded = is_array($decoded) ? $decoded : [];

        if ($status < 200 || $status >= 300) {
            $this->log(sprintf('%s %s answered %d in %d ms%s: %s', $method, $path, $status, $elapsed, self::idempotencyKey($headers), $responseBody), 2);
        } else {
            $data = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : [];
            $this->log(sprintf('%s %s answered %d in %d ms%s%s', $method, $path, $status, $elapsed, self::idempotencyKey($headers), self::describe($body, $data)), 1);
        }

        return [$status, $decoded];
    }

    /**
     * @return array [status, body, error or null]
     */
    protected function send($method, $url, array $headers, $body)
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
     * What a successful call was about, for the log. Deliberately a whitelist: the API key, the secrets, checkoutUrl (which pays the payment) and the return URLs never appear.
     */
    private static function describe($request, array $response)
    {
        $parts = [];

        if (is_array($request) && isset($request['reference'])) {
            $parts[] = sprintf('amount %d, reference %s, webhook %s', $request['amount'], $request['reference'], isset($request['callbackUrl']) ? 'yes' : 'no');
        } elseif (is_array($request) && isset($request['reason'])) {
            $parts[] = sprintf('refund of %d', $request['amount']);
        }

        foreach (['paymentReference', 'state', 'refundId', 'status', 'amount', 'currency', 'cancelableUntil'] as $field) {
            if (isset($response[$field]) && is_scalar($response[$field])) {
                $parts[] = $field . ' ' . $response[$field];
            }
        }

        return empty($parts) ? '' : ': ' . implode(', ', $parts);
    }

    /**
     * The Idempotency-Key a call carried, for the log: not a secret, and what MPS names in a 400 for a key
     * reused with another body.
     */
    private static function idempotencyKey(array $headers)
    {
        foreach ($headers as $header) {
            if (0 === strpos($header, 'Idempotency-Key: ')) {
                return ', key ' . substr($header, strlen('Idempotency-Key: '));
            }
        }

        return '';
    }

    private function log($message, $severity)
    {
        call_user_func($this->log, $message, $severity);
    }
}
