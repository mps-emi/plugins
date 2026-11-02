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
 * The rules of refunding a credit slip in MPS, with no PrestaShop dependency: how much, with which
 * idempotency key, and what MPS's answer means (POST /api/payments/{paymentReference}/refunds, see the
 * public refunds docs).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class MpsPaymentsRefund
{
    /** Accepted, not sent yet: every refund, live or test, is held 4 hours (cancelableUntil), then sent. */
    const PENDING = 'pending';
    /** Paid back (a test key's refund too, once its hold has passed). */
    const REFUNDED = 'refunded';
    /** MPS refused it (failed), or it was canceled: nothing was paid back. */
    const FAILED = 'failed';
    /** 400: MPS named what was wrong, usually the amount (more than is left). */
    const INVALID = 'invalid';
    /** 401/403: the key may not refund — a live key holds payments:refund only once MPS granted it. */
    const NOT_PERMITTED = 'not_permitted';
    /** 404: MPS knows no such payment for this key (a test key and a live payment, or the reverse). */
    const NOT_FOUND = 'not_found';
    /** 409: the payment is not executed yet, or the same refund is still running. */
    const NOT_YET = 'not_yet';
    /** Anything else: it may or may not have been refunded. */
    const UNKNOWN = 'unknown';

    const REASON = 'requested_by_customer';

    /** MPS takes an Idempotency-Key of at most 255 characters. */
    const MAX_KEY_LENGTH = 255;

    /**
     * The request body: the amount in integer cents and the reason. The payment is in the path.
     *
     * @param int $cents
     *
     * @return array
     */
    public static function body($cents)
    {
        return ['amount' => (int) $cents, 'reason' => self::REASON];
    }

    /**
     * One key per credit slip and payment: a resent request answers the first refund, never a second one.
     *
     * @param int    $idOrderSlip
     * @param string $paymentReference
     *
     * @return string
     */
    public static function idempotencyKey($idOrderSlip, $paymentReference)
    {
        return substr('prestashop-slip-' . (int) $idOrderSlip . '-' . $paymentReference, 0, self::MAX_KEY_LENGTH);
    }

    /**
     * What a credit slip pays back, in cents: the money the shopper paid is tax included.
     *
     * Shown tax included (most shops), the slip's amount and shipping_cost_amount are what PrestaShop
     * refunded — a chosen amount, or the products less a deducted voucher, included. Shown tax excluded
     * (B2B groups), amount is tax excluded: its tax-included totals are paid back, but only when amount is
     * the plain products total; a chosen amount or a deducted voucher has no tax-included figure on the slip.
     *
     * @param array $slip         The order_slip row (amount, shipping_cost_amount, total_products_tax_incl,
     *                            total_products_tax_excl, total_shipping_tax_incl).
     * @param bool  $taxIncluded  The order's customer group sees prices tax included.
     *
     * @return int|null Cents, or null when the slip does not say how much money that is.
     */
    public static function amount(array $slip, $taxIncluded)
    {
        if ($taxIncluded) {
            return self::cents((float) $slip['amount'] + (float) $slip['shipping_cost_amount']);
        }

        if (self::cents($slip['amount']) !== self::cents($slip['total_products_tax_excl'])) {
            return null;
        }

        return self::cents((float) $slip['total_products_tax_incl'] + (float) $slip['total_shipping_tax_incl']);
    }

    /**
     * What MPS's answer means for the merchant.
     *
     * @param int   $status  HTTP status.
     * @param array $answer  The decoded response body.
     *
     * @return array outcome (one of the constants), refundId, amount (cents), cancelableUntil ("Y-m-d H:i UTC"
     *               or null), detail (MPS's failure reason or error text, or null).
     */
    public static function outcome($status, array $answer)
    {
        $data = isset($answer['data']) && is_array($answer['data']) ? $answer['data'] : [];
        $result = [
            'outcome' => self::UNKNOWN,
            'refundId' => isset($data['refundId']) ? (string) $data['refundId'] : null,
            'amount' => isset($data['amount']) ? (int) $data['amount'] : null,
            'cancelableUntil' => empty($data['cancelableUntil']) ? null : self::utc($data['cancelableUntil']),
            'detail' => null,
        ];

        if ($status >= 200 && $status < 300) {
            if (null === $result['refundId']) {
                return $result;
            }
            $state = isset($data['status']) ? $data['status'] : '';
            if ('refunded' === $state) {
                $result['outcome'] = self::REFUNDED;
            } elseif ('failed' === $state || 'canceled' === $state) {
                $result['outcome'] = self::FAILED;
                $result['detail'] = empty($data['failureReason']) ? $state : (string) $data['failureReason'];
            } else {
                // MPS: treat any status you do not recognise as pending.
                $result['outcome'] = self::PENDING;
            }

            return $result;
        }

        if (400 === $status) {
            $result['outcome'] = self::INVALID;
            $result['detail'] = self::errors($answer);
        } elseif (401 === $status || 403 === $status) {
            $result['outcome'] = self::NOT_PERMITTED;
        } elseif (404 === $status) {
            $result['outcome'] = self::NOT_FOUND;
        } elseif (409 === $status) {
            $result['outcome'] = self::NOT_YET;
        } else {
            $result['detail'] = 'HTTP ' . (int) $status;
        }

        return $result;
    }

    /**
     * An amount in euros as MPS takes it: whole cents.
     *
     * @param float|string $amount
     *
     * @return int
     */
    private static function cents($amount)
    {
        return (int) round((float) $amount * 100);
    }

    /**
     * @param string $iso An ISO 8601 instant, e.g. 2026-10-30T14:05:00Z.
     *
     * @return string|null
     */
    private static function utc($iso)
    {
        $time = strtotime((string) $iso);

        return false === $time ? null : gmdate('Y-m-d H:i', $time) . ' UTC';
    }

    /**
     * Every message of a 400's errors (or its title), on one line.
     */
    private static function errors(array $answer)
    {
        $messages = [];
        if (isset($answer['errors']) && is_array($answer['errors'])) {
            foreach ($answer['errors'] as $field => $fieldMessages) {
                foreach ((array) $fieldMessages as $message) {
                    if (is_scalar($message) && '' !== trim((string) $message)) {
                        $messages[] = trim((string) $message);
                    }
                }
            }
        }
        if (empty($messages) && isset($answer['title']) && is_scalar($answer['title'])) {
            $messages[] = (string) $answer['title'];
        }

        return empty($messages) ? 'the request was refused (400)' : implode(' ', $messages);
    }
}
