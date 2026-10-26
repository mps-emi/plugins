<?php
declare(strict_types=1);

namespace Mps\Payments\Controller\Checkout;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect as RedirectResult;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Mps\Payments\Model\Config;
use Mps\Payments\Model\DebugLog;
use Mps\Payments\Model\PaymentInfo;
use Mps\Payments\Model\PaymentStarter;

/**
 * The checkout sends the shopper here right after placing the order: open the MPS payment and send them to
 * the hosted checkout. Only for the order this session just placed, and only once.
 */
class Redirect implements HttpGetActionInterface
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly PaymentStarter $starter,
        private readonly OrderRepositoryInterface $orders,
        private readonly RedirectFactory $redirects,
        private readonly ManagerInterface $messages,
        private readonly DebugLog $log,
    ) {
    }

    public function execute(): RedirectResult
    {
        $order = $this->checkoutSession->getLastRealOrder();

        if (!$order->getId()
            || Config::METHOD_CODE !== $order->getPayment()?->getMethod()
            || Order::STATE_PENDING_PAYMENT !== $order->getState()
            || '' !== (string) $order->getPayment()->getAdditionalInformation(PaymentInfo::REFERENCE)
        ) {
            return $this->redirects->create()->setPath('checkout/cart');
        }

        try {
            return $this->redirects->create()->setUrl($this->starter->start($order));
        } catch (LocalizedException $e) {
            $this->log->log(sprintf('No payment opened for order %s: %s', $order->getIncrementId(), $e->getMessage()), (int) $order->getStoreId(), 'warning');

            // Nothing was opened, so nothing can be paid on this order: cancel it and give the shopper their cart back.
            $order->cancel();
            $order->addCommentToStatusHistory($e->getMessage());
            $this->orders->save($order);
            $this->checkoutSession->restoreQuote();

            $this->messages->addErrorMessage(__('The payment could not be started. Please try again or choose another payment method.'));
            return $this->redirects->create()->setPath('checkout/cart');
        }
    }
}
