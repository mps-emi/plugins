<?php
declare(strict_types=1);

namespace Mps\Payments\Gateway\Validator;

use Magento\Payment\Gateway\Validator\AbstractValidator;
use Magento\Payment\Gateway\Validator\ResultInterface;

/**
 * MPS takes payments in EUR only; in any other currency the method is not offered at checkout.
 */
class CurrencyValidator extends AbstractValidator
{
    public function validate(array $validationSubject): ResultInterface
    {
        return $this->createResult('EUR' === ($validationSubject['currency'] ?? null));
    }
}
