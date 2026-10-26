<?php
declare(strict_types=1);

namespace Mps\Payments\Block;

use Magento\Payment\Block\ConfigurableInfo;
use Mps\Payments\Model\PaymentInfo;

/**
 * The payment on the order: the MPS payment reference, from the keys listed in config.xml
 * (paymentInfoKeys), under a translated label.
 */
class Info extends ConfigurableInfo
{
    /**
     * @inheritdoc
     */
    protected function getLabel($field)
    {
        return PaymentInfo::REFERENCE === $field ? __('MPS payment reference') : parent::getLabel($field);
    }
}
