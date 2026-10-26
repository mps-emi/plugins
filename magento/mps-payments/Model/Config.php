<?php
declare(strict_types=1);

namespace Mps\Payments\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * The merchant's settings under Stores › Configuration › Sales › Payment Methods › MPS Payments.
 * The two secrets are declared encrypted in config.xml, so they are read here already decrypted.
 */
class Config
{
    public const METHOD_CODE = 'mps_payments';

    public const DEFAULT_GATEWAY_URL = 'https://gateway.mps-emi.com';

    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    public function clientId(?int $storeId = null): string
    {
        return trim((string) $this->value('client_id', $storeId));
    }

    public function clientSecret(?int $storeId = null): string
    {
        return trim((string) $this->value('client_secret', $storeId));
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

    public function hasCredentials(?int $storeId = null): bool
    {
        return '' !== $this->clientId($storeId) && '' !== $this->clientSecret($storeId);
    }

    /**
     * Whether the configured key is a test key: it accepts only the MPS test cards, charges no card and
     * stops working after 24 hours, which is worth telling the merchant when it is saved.
     */
    public function isTestKey(?int $storeId = null): bool
    {
        return str_starts_with($this->clientId($storeId), 'test_');
    }

    private function value(string $field, ?int $storeId): mixed
    {
        return $this->scopeConfig->getValue('payment/' . self::METHOD_CODE . '/' . $field, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
