<?php
declare(strict_types=1);

namespace Mps\Payments\Gateway\Validator;

use Magento\Payment\Gateway\Validator\AbstractValidator;
use Magento\Payment\Gateway\Validator\ResultInterface;
use Magento\Payment\Gateway\Validator\ResultInterfaceFactory;
use Mps\Payments\Model\Config;

/**
 * Without an API key there is nothing to pay with; the method is not offered. A client ID and secret are not
 * enough (the admin says so: Model\System\MissingApiKey).
 */
class AvailabilityValidator extends AbstractValidator
{
    public function __construct(ResultInterfaceFactory $resultFactory, private readonly Config $config)
    {
        parent::__construct($resultFactory);
    }

    public function validate(array $validationSubject): ResultInterface
    {
        $storeId = $validationSubject['payment']->getOrder()->getStoreId();

        return $this->createResult($this->config->hasCredentials(null === $storeId ? null : (int) $storeId));
    }
}
