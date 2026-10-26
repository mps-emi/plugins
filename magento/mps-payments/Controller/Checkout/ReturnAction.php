<?php
declare(strict_types=1);

namespace Mps\Payments\Controller\Checkout;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect as RedirectResult;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Mps\Payments\Model\Config;
use Mps\Payments\Model\DebugLog;
use Mps\Payments\Model\Outcome;
use Mps\Payments\Model\Settlement;

/**
 * The shopper is back from the hosted checkout (mpspayments/checkout/return — "return" is reserved in PHP,
 * so Magento routes it to ReturnAction). Which URL they came back on is a hint for what to show them, never
 * proof: the order is settled from the payment as MPS reads it.
 */
class ReturnAction implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly OrderRepositoryInterface $orders,
        private readonly Settlement $settlement,
        private readonly CheckoutSession $checkoutSession,
        private readonly RedirectFactory $redirects,
        private readonly ManagerInterface $messages,
        private readonly DebugLog $log,
    ) {
    }

    public function execute(): RedirectResult
    {
        $orderId = (int) $this->request->getParam('order_id');
        $key = (string) $this->request->getParam('key');
        $result = (string) $this->request->getParam('result');

        try {
            $order = $this->orders->get($orderId);
        } catch (NoSuchEntityException) {
            return $this->redirects->create()->setPath('checkout/cart');
        }

        // The protect code is the credential here, as it is for Magento's own guest order view.
        if (Config::METHOD_CODE !== $order->getPayment()?->getMethod() || '' === $key || !hash_equals((string) $order->getProtectCode(), $key)) {
            return $this->redirects->create()->setPath('checkout/cart');
        }

        $outcome = $this->settlement->settle($orderId);
        $this->log->log(sprintf('Shopper returned for order %s on the %s URL; outcome %s', $order->getIncrementId(), $result, $outcome), (int) $order->getStoreId());

        // The success page shows the order this session placed — which may be another session after an app hand-off.
        $this->checkoutSession->setLastQuoteId($order->getQuoteId())
            ->setLastSuccessQuoteId($order->getQuoteId())
            ->setLastOrderId($order->getId())
            ->setLastRealOrderId($order->getIncrementId())
            ->setLastOrderStatus($order->getStatus());

        if (in_array($outcome, [Outcome::PAID, Outcome::PARTIAL], true) || (Outcome::PENDING === $outcome && 'success' === $result)) {
            return $this->redirects->create()->setPath('checkout/onepage/success');
        }

        $this->checkoutSession->restoreQuote();
        $this->messages->addErrorMessage(__('Your payment was not completed. Please try again or choose another payment method.'));

        return $this->redirects->create()->setPath('checkout/cart');
    }
}
