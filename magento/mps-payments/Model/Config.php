<?php
declare(strict_types=1);

namespace Mps\Payments\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * The merchant's settings under Stores › Configuration › Sales › Payment Methods › MPS Payments.
 * The API key and the webhook secret are declared encrypted in config.xml, so they are read here already decrypted.
 */
class Config
{
    public const METHOD_CODE = 'mps_payments';

    public const DEFAULT_GATEWAY_URL = 'https://gateway.mps-emi.com';

    /** What a test API key starts with; a live one starts mps_live_. */
    public const TEST_API_KEY_PREFIX = 'mps_test_';

    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    /**
     * The API key, sent as the bearer on every call: the only credential the module connects with.
     */
    public function apiKey(?int $storeId = null): string
    {
        return trim((string) $this->value('api_key', $storeId));
    }

    public function webhookSecret(?int $storeId = null): string
    {
        return trim((string) $this->value('webhook_secret', $storeId));
    }

    public function gatewayUrl(?int $storeId = null): string
    {
        $url = trim((string) $this->value('gateway_url', $storeId));

        return '' === $url ? self::DEFAULT_GATEWAY_URL : rtrim($url, '/');
    }

    public function isDebug(?int $storeId = null): bool
    {
        return (bool) $this->value('debug', $storeId);
    }

    /**
     * An API key. A client ID and secret (MPS's credentials before API keys) are not accepted any more.
     */
    public function hasCredentials(?int $storeId = null): bool
    {
        return '' !== $this->apiKey($storeId);
    }

    /**
     * The method is switched on but has no API key, so it is not offered at checkout — typically a shop that
     * connected with a client ID and secret before 1.0.0. The admin is told so (Model\System\MissingApiKey).
     */
    public function isMissingApiKey(?int $storeId = null): bool
    {
        return (bool) $this->value('active', $storeId) && !$this->hasCredentials($storeId);
    }

    /**
     * Whether the API key is a test key (its mps_test_ prefix): it accepts only the MPS test cards, charges no
     * card. It is worth telling the merchant so when it is saved.
     */
    public function isTestKey(?int $storeId = null): bool
    {
        return str_starts_with($this->apiKey($storeId), self::TEST_API_KEY_PREFIX);
    }

    private function value(string $field, ?int $storeId): mixed
    {
        return $this->scopeConfig->getValue('payment/' . self::METHOD_CODE . '/' . $field, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
