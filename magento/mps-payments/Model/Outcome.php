<?php
declare(strict_types=1);

namespace Mps\Payments\Model;

/**
 * What a payment read from MPS means for the order it was opened for.
 */
class Outcome
{
    public const PAID = 'paid';
    public const PARTIAL = 'partial';
    public const FAILED = 'failed';
    public const ABANDONED = 'abandoned';
    public const PENDING = 'pending';

    /**
     * @param array $payment  The payment as GET /api/payments/internet/{reference} returns it.
     * @param int   $expected The order total, in cents.
     */
    public static function of(array $payment, int $expected): string
    {
        return match ($payment['state'] ?? '') {
            // A partial approval is only visible in the amount; the shortfall must not ship as paid.
            'executed' => (int) ($payment['amount'] ?? 0) >= $expected ? self::PAID : self::PARTIAL,
            'failed' => self::FAILED,
            'abandoned' => self::ABANDONED,
            // Anything this module does not recognise is treated as not paid yet — never as paid.
            default => self::PENDING,
        };
    }

    /**
     * Whether a payment read back is the one opened for the order.
     *
     * It is in EUR and carries the order number, as far as MPS reports either. A payment that contradicts
     * the order moves nothing.
     *
     * @param array  $payment     The payment as GET /api/payments/internet/{reference} returns it.
     * @param string $incrementId The order number, sent as reference when the payment was opened.
     * @return bool
     */
    public static function isForOrder(array $payment, string $incrementId): bool
    {
        if (isset($payment['currency']) && 'EUR' !== $payment['currency']) {
            return false;
        }

        if (!isset($payment['reference'])) {
            return true;
        }

        return is_scalar($payment['reference']) && (string) $payment['reference'] === $incrementId;
    }

    /**
     * An amount in euros as MPS takes it: whole cents.
     *
     * @param float|string $amount
     */
    public static function cents(float|string $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
