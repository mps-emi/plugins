<?php
declare(strict_types=1);

namespace Mps\Payments\Model;

use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;

/**
 * Reads an order's MPS payment and moves the order on what MPS says — never on which URL the shopper came
 * back on.
 *
 * Three things can tell this module to look again: the shopper returning, MPS's webhook, and the
 * scheduled check. All three call settle(), so none of them has to be trusted on its own, and a shop that
 * cannot receive webhooks still settles.
 */
class Settlement
{
    public function __construct(
        private readonly Api $api,
        private readonly OrderRepositoryInterface $orders,
        private readonly OrderFactory $orderFactory,
        private readonly OrderResource $orderResource,
        private readonly OrderSender $orderSender,
        private readonly LockManagerInterface $locks,
        private readonly DebugLog $log,
    ) {
    }

    /**
     * @param bool $scheduled A scheduled check (Cron\CheckPendingPayments): one that finds the payment still
     *                        open is counted, so the next waits for its own delay.
     * @return string The Outcome: paid, partial, failed, abandoned, or pending (also when MPS cannot be read).
     */
    public function settle(int $orderId, bool $scheduled = false): string
    {
        // The return and the webhook often arrive together; one of them invoices, the other sees it done.
        $lock = 'mps_payments_order_' . $orderId;
        if (!$this->locks->lock($lock, 30)) {
            return Outcome::PENDING;
        }

        try {
            // Read inside the lock, past the repository's cache: the copy loaded before it may already be stale.
            $order = $this->orderFactory->create();
            $this->orderResource->load($order, $orderId);

            return $order->getId() ? $this->settleLocked($order, $scheduled) : Outcome::PENDING;
        } finally {
            $this->locks->unlock($lock);
        }
    }

    private function settleLocked(Order $order, bool $scheduled): string
    {
        $payment = $order->getPayment();
        $reference = (string) $payment->getAdditionalInformation(PaymentInfo::REFERENCE);

        // Settled before, or moved on by the merchant: not this module's to change any more.
        if (Order::STATE_PENDING_PAYMENT !== $order->getState()) {
            return (string) ($payment->getAdditionalInformation(PaymentInfo::OUTCOME) ?: Outcome::PENDING);
        }

        if ('' === $reference) {
            return Outcome::PENDING;
        }

        $expected = Outcome::cents($order->getGrandTotal());

        try {
            $read = $this->api->getPayment($reference, (int) $order->getStoreId());
            $outcome = Outcome::of($read, $expected);
        } catch (\Exception) {
            $read = [];
            $outcome = Outcome::PENDING;
        }

        // A payment in another currency, or opened for another order number, is not this order's to move.
        if (Outcome::PENDING !== $outcome && !Outcome::isForOrder($read, (string) $order->getIncrementId())) {
            $this->log->log(sprintf(
                'Order %s left as it is: payment %s reads %s %s for reference %s',
                $order->getIncrementId(),
                $reference,
                is_scalar($read['currency'] ?? null) ? $read['currency'] : '-',
                $outcome,
                is_scalar($read['reference'] ?? null) ? $read['reference'] : '-'
            ), (int) $order->getStoreId(), 'warning');
            $outcome = Outcome::PENDING;
        }

        if (Outcome::PENDING === $outcome) {
            if ($scheduled) {
                $payment->setAdditionalInformation(PaymentInfo::CHECKS, (int) $payment->getAdditionalInformation(PaymentInfo::CHECKS) + 1);
                $this->orders->save($order);
            }

            return $outcome;
        }

        $payment->setAdditionalInformation(PaymentInfo::OUTCOME, $outcome);

        switch ($outcome) {
            case Outcome::PAID:
                $payment->setTransactionId($reference)
                    ->setIsTransactionClosed(true)
                    ->registerCaptureNotification($order->getBaseGrandTotal(), true);
                $order->addCommentToStatusHistory(__('MPS payment %1 paid.', $reference));
                break;

            case Outcome::PARTIAL:
                $approved = (int) ($read['amount'] ?? 0);
                $order->hold();
                $order->addCommentToStatusHistory(__(
                    'MPS approved %1 of %2 (payment %3). Check before shipping.',
                    $order->formatPriceTxt($approved / 100),
                    $order->formatPriceTxt($expected / 100),
                    $reference
                ));
                break;

            case Outcome::FAILED:
                $order->cancel();
                $order->addCommentToStatusHistory(__('MPS payment %1 failed.', $reference));
                break;

            case Outcome::ABANDONED:
                $order->cancel();
                $order->addCommentToStatusHistory(__('The shopper left MPS payment %1 without paying.', $reference));
                break;
        }

        $this->orders->save($order);
        $this->log->log(sprintf('Order %s moved on payment %s: %s', $order->getIncrementId(), $reference, $outcome), (int) $order->getStoreId());

        // The confirmation was held back when the order was placed; it goes out once there is something to confirm.
        if (Outcome::PAID === $outcome && !$order->getEmailSent()) {
            try {
                $this->orderSender->send($order);
            } catch (\Exception $e) {
                $this->log->log('Order confirmation not sent: ' . $e->getMessage(), (int) $order->getStoreId(), 'warning');
            }
        }

        return $outcome;
    }
}
