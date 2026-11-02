<?php
declare(strict_types=1);

namespace Mps\Payments\Model;

/**
 * Where this module keeps what it knows about an order's MPS payment: in the order payment's additional
 * information, and the reference also as its last transaction id, which is a column the scheduled checks
 * (and a webhook without an order number) can find the order by.
 */
class PaymentInfo
{
    /** The MPS payment opened for the order. */
    public const REFERENCE = 'mps_payment_reference';

    /** Unix time the payment was opened; the scheduled checks count from it. */
    public const OPENED_AT = 'mps_opened_at';

    /** How many of Cron\CheckPendingPayments::DELAYS have run. */
    public const CHECKS = 'mps_checks';

    /** The last Outcome acted on, so a repeat read changes nothing and adds no second comment. */
    public const OUTCOME = 'mps_outcome';

    /** The MPS refunds made from credit memos: refund id => status as MPS answered it. */
    public const REFUNDS = 'mps_refunds';

    /** The Idempotency-Key the payment is opened with, made when the order is placed. */
    public const IDEMPOTENCY_KEY = 'mps_idempotency_key';

    /**
     * A new Idempotency-Key for opening an order's payment: the order number, to find it by, and a random part.
     *
     * An order is one attempt to pay — one that fails or is abandoned is canceled, and the shopper places a new
     * order from the restored cart — so the key is made once, when the order is placed, and every request to
     * open its payment sends it: a redirect asked for twice gets the one payment. A new order gets a new key.
     * The random part keeps it apart from the same order number in another shop on the same MPS key.
     */
    public static function idempotencyKey(string $orderNumber): string
    {
        return 'magento-' . $orderNumber . '-' . bin2hex(random_bytes(16));
    }
}
