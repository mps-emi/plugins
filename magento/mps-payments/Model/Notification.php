<?php
declare(strict_types=1);

namespace Mps\Payments\Model;

/**
 * What the webhook does with a notification from MPS, and which order it is for.
 */
class Notification
{
    /** No signing secret configured: answered 200 and not acted on. */
    public const IGNORE = 'ignore';

    /** The signature or timestamp does not match: answered 401. */
    public const REFUSE = 'refuse';

    /** Signed and current: the payment is read from MPS. */
    public const READ = 'read';

    /**
     * What to do with a notification: ignore it, refuse it, or read the payment.
     *
     * MPS sends no notifications for a key without a signing secret, so one arriving at a shop without a
     * secret is not acted on; the return and the scheduled checks settle such a shop.
     *
     * @param string $secret    The signing secret, empty when none is configured.
     * @param string $body      The raw request body, exactly as received.
     * @param string $timestamp X-Mps-Timestamp.
     * @param string $signature X-Mps-Signature.
     * @param int    $now       The current Unix time.
     * @return string IGNORE, REFUSE or READ.
     */
    public static function decide(string $secret, string $body, string $timestamp, string $signature, int $now): string
    {
        if ('' === $secret) {
            return self::IGNORE;
        }

        return Signature::isValid($secret, $body, $timestamp, $signature, $now) ? self::READ : self::REFUSE;
    }

    /**
     * A text field of the decoded notification.
     *
     * paymentReference, or merchantReference: the order number this module sent as reference.
     *
     * @param mixed  $event The decoded body.
     * @param string $name  The field.
     * @return string The value, or empty when absent or not text.
     */
    public static function field(mixed $event, string $name): string
    {
        return is_array($event) && is_string($event[$name] ?? null) ? $event[$name] : '';
    }

    /**
     * Whether a notification is for an order.
     *
     * It is when one of the MPS payment references stored on the order is exactly the notification's.
     *
     * @param array  $stored           The order's references (additional information, last transaction id).
     * @param string $paymentReference The notification's paymentReference.
     * @return bool
     */
    public static function isFor(array $stored, string $paymentReference): bool
    {
        if ('' === $paymentReference) {
            return false;
        }

        foreach ($stored as $reference) {
            if (is_scalar($reference) && (string) $reference === $paymentReference) {
                return true;
            }
        }

        return false;
    }
}
