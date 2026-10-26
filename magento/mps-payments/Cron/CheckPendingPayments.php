<?php
declare(strict_types=1);

namespace Mps\Payments\Cron;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Mps\Payments\Model\Config;
use Mps\Payments\Model\PaymentInfo;
use Mps\Payments\Model\Settlement;

/**
 * Re-reads the MPS payments still open, at 10 min, 30 min, 1 h and 6 h after each was opened. This is what
 * settles an order on a shop that cannot receive webhooks — localhost, or a private network — when the
 * shopper closes the tab. Runs every five minutes; it stops for an order at its first final outcome.
 *
 * Magento cancels orders left in pending payment after Sales › Checkout › Pending Payment Order Lifetime
 * (8 hours by default), after the last of these checks.
 */
class CheckPendingPayments
{
    /** Seconds after opening at which a check runs. */
    public const DELAYS = [600, 1800, 3600, 21600];

    public function __construct(
        private readonly OrderCollectionFactory $orderCollections,
        private readonly OrderRepositoryInterface $orders,
        private readonly Settlement $settlement,
    ) {
    }

    public function execute(): void
    {
        $collection = $this->orderCollections->create()
            ->addFieldToFilter('state', Order::STATE_PENDING_PAYMENT)
            ->addFieldToFilter('created_at', ['gteq' => gmdate('Y-m-d H:i:s', time() - 86400)]);
        $collection->getSelect()
            ->join(['mps_payment' => $collection->getTable('sales_order_payment')], 'mps_payment.parent_id = main_table.entity_id', [])
            ->where('mps_payment.method = ?', Config::METHOD_CODE)
            ->where('mps_payment.last_trans_id IS NOT NULL');

        foreach ($collection->getAllIds() as $orderId) {
            $this->check((int) $orderId);
        }
    }

    private function check(int $orderId): void
    {
        $payment = $this->orders->get($orderId)->getPayment();
        $checks = (int) $payment->getAdditionalInformation(PaymentInfo::CHECKS);
        $openedAt = (int) $payment->getAdditionalInformation(PaymentInfo::OPENED_AT);

        if ($checks >= count(self::DELAYS) || time() < $openedAt + self::DELAYS[$checks]) {
            return;
        }

        $this->settlement->settle($orderId, true);
    }
}
