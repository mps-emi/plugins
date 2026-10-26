<?php
declare(strict_types=1);

namespace Mps\Payments\Gateway\Command;

use Magento\Payment\Gateway\CommandInterface;
use Magento\Sales\Model\Order;

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

        // The order confirmation waits until MPS says the order is paid (Model\Settlement).
        $commandSubject['payment']->getPayment()->getOrder()->setCanSendNewEmailFlag(false);

        return null;
    }
}
