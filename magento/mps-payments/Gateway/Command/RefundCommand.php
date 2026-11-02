<?php
declare(strict_types=1);

namespace Mps\Payments\Gateway\Command;

use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction;
use Mps\Payments\Model\PaymentInfo;
use Mps\Payments\Model\Refund;
use Mps\Payments\Model\Refunder;

/**
 * A credit memo refunded online: refunds its total on the order's MPS payment (Model\Refunder), which throws
 * when MPS does not accept it, so the credit memo is not saved. The MPS refund id becomes the refund
 * transaction's id, and its status opens the order comment Magento writes for the refund.
 */
class RefundCommand implements CommandInterface
{
    public function __construct(private readonly Refunder $refunder)
    {
    }

    public function execute(array $commandSubject)
    {
        /** @var Payment $payment */
        $payment = SubjectReader::readPayment($commandSubject)->getPayment();
        $order = $payment->getOrder();
        $creditmemo = $payment->getCreditmemo();

        // MPS was paid the order total in the order's currency (EUR); Magento hands the command the base amount.
        $amount = $creditmemo ? $creditmemo->getGrandTotal() : SubjectReader::readAmount($commandSubject);

        $refund = $this->refunder->refund(
            (string) $payment->getAdditionalInformation(PaymentInfo::REFERENCE),
            (float) $amount,
            (float) $payment->getAmountRefunded(),
            (int) $order->getStoreId()
        );

        $refundId = (string) $refund['refundId'];
        $refunds = (array) ($payment->getAdditionalInformation(PaymentInfo::REFUNDS) ?: []);
        $refunds[$refundId] = Refund::status($refund);

        $payment->setAdditionalInformation(PaymentInfo::REFUNDS, $refunds);
        $payment->setTransactionId($refundId)
            ->setIsTransactionClosed(Refund::REFUNDED === Refund::status($refund))
            ->setTransactionAdditionalInfo(Transaction::RAW_DETAILS, Refund::details($refund));
        // Prepended by Magento to its "We refunded … online. Transaction ID: …" comment on the order.
        $payment->setPreparedMessage($this->refunder->comment($refund));

        return null;
    }
}
