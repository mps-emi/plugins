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
}
