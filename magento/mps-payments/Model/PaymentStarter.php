<?php
declare(strict_types=1);

namespace Mps\Payments\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

/**
 * Opens the MPS payment for a placed order and says where to send the shopper.
 */
class PaymentStarter
{
    public function __construct(
        private readonly Api $api,
        private readonly UrlInterface $url,
        private readonly OrderRepositoryInterface $orders,
    ) {
    }

    /**
     * @return string The checkoutUrl. It pays this payment for whoever holds it: hand it to the browser and nowhere else.
     * @throws LocalizedException When the order cannot be paid with MPS or MPS opens no payment.
     */
    public function start(Order $order): string
    {
        if ('EUR' !== $order->getOrderCurrencyCode()) {
            throw new LocalizedException(__('MPS takes payments in EUR only.'));
        }

        $body = [
            'amount' => Outcome::cents($order->getGrandTotal()),
            'reference' => (string) $order->getIncrementId(),
            'successUrl' => $this->returnUrl($order, 'success'),
            'failureUrl' => $this->returnUrl($order, 'failure'),
        ];

        $callbackUrl = $this->url->getUrl('mpspayments/checkout/webhook', ['_nosid' => true]);
        if (CallbackUrl::canReceiveWebhooks($callbackUrl)) {
            $body['callbackUrl'] = $callbackUrl;
        }

        try {
            $created = $this->api->createPayment($body, (int) $order->getStoreId());
        } catch (\Exception $e) {
            throw new LocalizedException(__('MPS could not open a payment. Enable the debug log for details.'), $e);
        }

        if (empty($created['paymentReference']) || empty($created['checkoutUrl'])) {
            throw new LocalizedException(__('MPS could not open a payment. Enable the debug log for details.'));
        }

        $reference = (string) $created['paymentReference'];
        $payment = $order->getPayment();
        $payment->setLastTransId($reference);
        $payment->setAdditionalInformation(PaymentInfo::REFERENCE, $reference);
        $payment->setAdditionalInformation(PaymentInfo::OPENED_AT, time());
        $payment->setAdditionalInformation(PaymentInfo::CHECKS, 0);
        $order->addCommentToStatusHistory(__('MPS payment %1 opened; shopper sent to the MPS checkout.', $reference));
        $this->orders->save($order);

        return (string) $created['checkoutUrl'];
    }

    /**
     * Both outcomes come back to the same handler; which one only decides what the shopper sees. The
     * order's protect code is what lets a return act on the order, as Magento's own guest order view does.
     */
    private function returnUrl(Order $order, string $result): string
    {
        return $this->url->getUrl('mpspayments/checkout/return', [
            '_nosid' => true,
            '_query' => ['order_id' => $order->getId(), 'key' => $order->getProtectCode(), 'result' => $result],
        ]);
    }
}
