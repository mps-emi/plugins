<?php
declare(strict_types=1);

namespace Mps\Payments\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Mps\Payments\Model\Api;
use Mps\Payments\Model\Config;
use Mps\Payments\Model\System\MissingApiKey;

/**
 * After the payment methods are saved, proves the MPS API key with a real call to MPS, so a wrong key is found
 * now rather than by the first shopper. Saved enabled without a key, the method is not offered: it says so.
 */
class VerifyCredentials implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Api $api,
        private readonly StoreManagerInterface $stores,
        private readonly ManagerInterface $messages,
    ) {
    }

    public function execute(Observer $observer): void
    {
        $storeId = $this->storeId((string) $observer->getEvent()->getData('store'), (string) $observer->getEvent()->getData('website'));

        if (!$this->config->hasCredentials($storeId)) {
            if ($this->config->isMissingApiKey($storeId)) {
                $this->messages->addErrorMessage(MissingApiKey::text());
            }
            return;
        }

        try {
            $this->api->verifyCredentials($storeId);
            $this->messages->addSuccessMessage(
                $this->config->isTestKey($storeId)
                    ? __('Connected to MPS with a test key. It accepts only the MPS test cards; no card is charged.')
                    : __('Connected to MPS.')
            );
        } catch (\Exception $e) {
            $this->messages->addErrorMessage(__('MPS did not accept these credentials: %1', $e->getMessage()));
        }
    }

    /**
     * The store whose settings were saved; a website's are checked through its default store.
     */
    private function storeId(string $store, string $website): ?int
    {
        if ('' !== $store) {
            return (int) $this->stores->getStore($store)->getId();
        }

        if ('' !== $website) {
            return (int) $this->stores->getWebsite($website)->getDefaultStore()->getId();
        }

        return null;
    }
}
