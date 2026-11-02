<?php
declare(strict_types=1);

namespace Mps\Payments\Model\System;

use Magento\Framework\Notification\MessageInterface;
use Magento\Framework\Phrase;
use Magento\Store\Model\StoreManagerInterface;
use Mps\Payments\Model\Config;

/**
 * The admin's system message while MPS is switched on for a store without an API key: the method is not offered
 * there. Since 1.0.0 that is every shop that connected with a client ID and secret — the upgrade removed them
 * (Setup\Patch\Data\RemoveClientCredentials) — until an API key is pasted. Registered in etc/adminhtml/di.xml.
 */
class MissingApiKey implements MessageInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $stores,
    ) {
    }

    /**
     * Also what Observer\VerifyCredentials says when such settings are saved.
     */
    public static function text(): Phrase
    {
        return __('MPS Payments is enabled but has no API key, so it is not offered at checkout. MPS no longer accepts a client ID and secret. Paste an API key and its webhook signing secret from the MPS platform (Online › Get started) under Stores › Configuration › Sales › Payment Methods › MPS Payments.');
    }

    public function getIdentity()
    {
        return hash('sha256', 'mps_payments_missing_api_key');
    }

    public function isDisplayed()
    {
        foreach ($this->stores->getStores() as $store) {
            if ($this->config->isMissingApiKey((int) $store->getId())) {
                return true;
            }
        }

        return false;
    }

    public function getText()
    {
        return self::text();
    }

    public function getSeverity()
    {
        return self::SEVERITY_MAJOR;
    }
}
