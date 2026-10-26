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
 * Checks X-Mps-Signature: HMAC-SHA256 over "{timestamp}.{raw body}", hex, prefixed v1=. During a secret
 * rotation the header carries two comma-separated signatures; either one matching is valid.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class MpsPaymentsSignature
{
    /** How old, in seconds, a notification may be. */
    const TOLERANCE = 300;

    /** What the webhook does with a notification: see verdict(). */
    const IGNORE = 'ignore';
    const REFUSE = 'refuse';
    const READ = 'read';

    /**
     * MPS sends no notifications for a key without a signing secret, so without one configured nothing that
     * arrives is from MPS: it is ignored, and the return and the scheduled checks settle the cart.
     *
     * @param string $secret    The signing secret configured in the module, empty when none is.
     * @param string $body      The raw request body, exactly as received.
     * @param string $timestamp X-Mps-Timestamp, Unix seconds.
     * @param string $header    X-Mps-Signature.
     * @param int    $now       The current Unix time.
     *
     * @return string IGNORE (answer 200, read nothing), REFUSE (answer 401) or READ (read the payment).
     */
    public static function verdict($secret, $body, $timestamp, $header, $now)
    {
        if ('' === (string) $secret) {
            return self::IGNORE;
        }

        return self::isValid($secret, $body, $timestamp, $header, $now) ? self::READ : self::REFUSE;
    }

    /**
     * @param string $secret    The signing secret.
     * @param string $body      The raw request body, exactly as received.
     * @param string $timestamp X-Mps-Timestamp, Unix seconds.
     * @param string $header    X-Mps-Signature.
     * @param int    $now       The current Unix time.
     *
     * @return bool
     */
    public static function isValid($secret, $body, $timestamp, $header, $now)
    {
        if (!ctype_digit((string) $timestamp) || abs($now - (int) $timestamp) > self::TOLERANCE) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

        foreach (explode(',', (string) $header) as $candidate) {
            $candidate = trim($candidate);
            if (0 === strpos($candidate, 'v1=') && hash_equals($expected, substr($candidate, 3))) {
                return true;
            }
        }

        return false;
    }
}
