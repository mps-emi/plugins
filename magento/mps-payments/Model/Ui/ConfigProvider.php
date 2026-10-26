<?php
declare(strict_types=1);

namespace Mps\Payments\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Mps\Payments\Model\Config;

/**
 * What the checkout's payment renderer needs: where to go once the order is placed, and the description.
 */
class ConfigProvider implements ConfigProviderInterface
{
    public function __construct(
        private readonly UrlInterface $url,
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    public function getConfig(): array
    {
        return [
            'payment' => [
                Config::METHOD_CODE => [
                    'redirectUrl' => $this->url->getUrl('mpspayments/checkout/redirect'),
                    // The default description is translated; a merchant's own text has no translation and shows as typed.
                    'instructions' => (string) __((string) $this->scopeConfig->getValue('payment/' . Config::METHOD_CODE . '/instructions', ScopeInterface::SCOPE_STORE)),
                ],
            ],
        ];
    }
}
