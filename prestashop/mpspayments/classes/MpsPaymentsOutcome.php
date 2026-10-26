<?php
/**
 * Copyright since 2026 Mobile Payment Solutions
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 *
 * @author    Mobile Payment Solutions
 * @copyright Since 2026 Mobile Payment Solutions
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
/**
 * What a payment read from MPS means for the cart it was opened for.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class MpsPaymentsOutcome
{
    const PAID = 'paid';
    const PARTIAL = 'partial';
    const FAILED = 'failed';
    const ABANDONED = 'abandoned';
    const PENDING = 'pending';

    /**
     * @param array $payment  The payment as GET /api/payments/internet/{reference} returns it.
     * @param int   $expected The amount asked for, in cents.
     *
     * @return string
     */
    public static function of(array $payment, $expected)
    {
        $state = isset($payment['state']) ? $payment['state'] : '';

        if ('executed' === $state) {
            // A partial approval is only visible in the amount; the shortfall must not ship as paid.
            return (isset($payment['amount']) ? (int) $payment['amount'] : 0) >= $expected ? self::PAID : self::PARTIAL;
        }

        if ('failed' === $state || 'abandoned' === $state) {
            return $state;
        }

        // Anything this module does not recognise is treated as not paid yet — never as paid.
        return self::PENDING;
    }

    /**
     * An amount in euros as MPS takes it: whole cents.
     *
     * @param float|string $amount
     *
     * @return int
     */
    public static function cents($amount)
    {
        return (int) round((float) $amount * 100);
    }
}
