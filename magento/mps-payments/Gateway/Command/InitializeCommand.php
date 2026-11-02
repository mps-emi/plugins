<?php
declare(strict_types=1);

namespace Mps\Payments\Gateway\Command;

use Magento\Payment\Gateway\CommandInterface;
use Magento\Sales\Model\Order;
use Mps\Payments\Model\PaymentInfo;

/**
 * Places the order on pending payment. Nothing is sent to MPS yet: the payment is opened when the
 * shopper's browser asks for it (Controller\Checkout\Redirect), right after the order is placed.
 */
class InitializeCommand implements CommandInterface
{
    public function execute(array $commandSubject)
    {
        $stateObject = $commandSubject['stateObject'];
        $stateObject->setState(Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus(Order::STATE_PENDING_PAYMENT);
        $stateObject->setIsNotified(false);

        $payment = $commandSubject['payment']->getPayment();

        // The order confirmation waits until MPS says the order is paid (Model\Settlement).
        $payment->getOrder()->setCanSendNewEmailFlag(false);

        // Saved with the order, before any request to open its payment can be made.
        $payment->setAdditionalInformation(PaymentInfo::IDEMPOTENCY_KEY, PaymentInfo::idempotencyKey((string) $payment->getOrder()->getIncrementId()));

        return null;
    }
}
