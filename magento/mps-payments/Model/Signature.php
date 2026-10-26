<?php
declare(strict_types=1);

namespace Mps\Payments\Model;

/**
 * Checks X-Mps-Signature: HMAC-SHA256 over "{timestamp}.{raw body}", hex, prefixed v1=. During a secret
 * rotation the header carries two comma-separated signatures; either one matching is valid.
 */
class Signature
{
    /** How old, in seconds, a notification may be. */
    public const TOLERANCE = 300;

    /**
     * @param string $secret    The signing secret.
     * @param string $body      The raw request body, exactly as received.
     * @param string $timestamp X-Mps-Timestamp, Unix seconds.
     * @param string $header    X-Mps-Signature.
     * @param int    $now       The current Unix time.
     */
    public static function isValid(string $secret, string $body, string $timestamp, string $header, int $now): bool
    {
        if (!ctype_digit($timestamp) || abs($now - (int) $timestamp) > self::TOLERANCE) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if (str_starts_with($candidate, 'v1=') && hash_equals($expected, substr($candidate, 3))) {
                return true;
            }
        }

        return false;
    }
}
