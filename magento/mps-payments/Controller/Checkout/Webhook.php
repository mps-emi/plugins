<?php
declare(strict_types=1);

namespace Mps\Payments\Controller\Checkout;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Payment\CollectionFactory as PaymentCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Mps\Payments\Model\Config;
use Mps\Payments\Model\DebugLog;
use Mps\Payments\Model\Notification;
use Mps\Payments\Model\PaymentInfo;
use Mps\Payments\Model\Settlement;

/**
 * MPS's notification that a payment arrived. Answered 2xx whenever it was understood, including for a
 * payment this shop does not know, so MPS does not keep retrying something we will never act on. Without
 * a signing secret configured it is ignored: MPS sends none for such a key, so the return and the
 * scheduled checks settle the order.
 */
class Webhook implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly HttpRequest $request,
        private readonly RawFactory $raws,
        private readonly Config $config,
        private readonly StoreManagerInterface $stores,
        private readonly OrderCollectionFactory $orders,
        private readonly PaymentCollectionFactory $payments,
        private readonly Settlement $settlement,
        private readonly DebugLog $log,
    ) {
    }

    /**
     * Acts on a signed notification by reading the payment; ignores it without a secret, refuses it unsigned.
     *
     * @return Raw
     */
    public function execute(): Raw
    {
        $storeId = (int) $this->stores->getStore()->getId();
        $body = (string) $this->request->getContent();
        $timestamp = (string) $this->request->getHeader('X-Mps-Timestamp');
        $signature = (string) $this->request->getHeader('X-Mps-Signature');

        switch (Notification::decide($this->config->webhookSecret($storeId), $body, $timestamp, $signature, time())) {
            case Notification::IGNORE:
                $this->log->log('Webhook ignored: no signing secret configured', $storeId);
                return $this->raws->create()->setHttpResponseCode(200);

            case Notification::REFUSE:
                $this->log->log('Webhook refused: signature or timestamp does not match', $storeId, 'warning');
                return $this->raws->create()->setHttpResponseCode(401);
        }

        $event = json_decode($body, true);
        $reference = Notification::field($event, 'paymentReference');
        $orderNumber = Notification::field($event, 'merchantReference');
        $orderId = '' === $reference ? null : $this->findOrderId($reference, $orderNumber);

        $this->log->log(sprintf(
            'Webhook %s for payment %s: %s',
            (string) $this->request->getHeader('X-Mps-Delivery-Id') ?: '-',
            '' === $reference ? '-' : $reference,
            null === $orderId ? 'no order of this shop' : 'order ' . $orderId
        ), $storeId);

        if (null !== $orderId) {
            // The notification only says "look"; what the payment is comes from reading it.
            $this->settlement->settle($orderId);
        }

        return $this->raws->create()->setHttpResponseCode(200);
    }

    /**
     * MPS signs its notifications instead of carrying a form key.
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * The order a notification is for: the one with the order number MPS echoes back as merchantReference,
     * and only when the payment opened for it is this one. Only a notification without an order number is
     * looked up by the payment reference itself.
     *
     * @param string $reference   The notification's paymentReference.
     * @param string $orderNumber The notification's merchantReference, empty when absent.
     * @return int|null
     */
    private function findOrderId(string $reference, string $orderNumber): ?int
    {
        if ('' === $orderNumber) {
            $payment = $this->payments->create()
                ->addFieldToFilter('method', Config::METHOD_CODE)
                ->addFieldToFilter('last_trans_id', $reference)
                ->setPageSize(1)
                ->getFirstItem();

            return $payment->getParentId() ? (int) $payment->getParentId() : null;
        }

        // An order number is unique per store, so a shop with several can have it more than once.
        foreach ($this->orders->create()->addFieldToFilter('increment_id', $orderNumber) as $order) {
            $payment = $order->getPayment();
            $stored = [$payment?->getAdditionalInformation(PaymentInfo::REFERENCE), $payment?->getLastTransId()];

            if (Config::METHOD_CODE === $payment?->getMethod() && Notification::isFor($stored, $reference)) {
                return (int) $order->getId();
            }
        }

        return null;
    }
}
