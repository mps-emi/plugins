<?php
declare(strict_types=1);

namespace Mps\Payments\Model;

/**
 * The pure rules of a refund (POST /api/payments/{paymentReference}/refunds), with no Magento dependency: what
 * is sent, the key that keeps a retry from refunding twice, and what MPS's answer means.
 */
class Refund
{
    public const REFUNDED = 'refunded';
    public const PENDING = 'pending';
    public const FAILED = 'failed';
    public const CANCELED = 'canceled';

    /** Why a credit memo refunds: the shop pays its customer back. */
    public const REASON = 'requested_by_customer';

    /** MPS takes an Idempotency-Key of at most this many characters. */
    private const MAX_KEY_LENGTH = 255;

    /**
     * The request body: the amount and the reason. The payment is named by the path, never in the body.
     *
     * @param int $amount In cents.
     */
    public static function request(int $amount): array
    {
        return ['amount' => $amount, 'reason' => self::REASON];
    }

    /**
     * The Idempotency-Key for a credit memo, which has no number yet when it is refunded.
     *
     * It is made of the payment, what was refunded of it before (in cents) and the amount: a credit memo
     * submitted again after a lost answer gets the same key, so MPS answers with the first refund instead of
     * making a second; the next credit memo of the order starts from a higher refunded total and gets its own.
     * After MPS refused one (failed or canceled), the same key would only fetch that refusal again, so each
     * new attempt is numbered.
     *
     * @param int $refundedBefore What was refunded of the payment before this credit memo, in cents.
     * @param int $amount         This credit memo's total, in cents.
     * @param int $attempt        How many attempts MPS refused before this one.
     */
    public static function idempotencyKey(string $paymentReference, int $refundedBefore, int $amount, int $attempt): string
    {
        $suffix = sprintf('-refund-%d-%d%s', $refundedBefore, $amount, $attempt > 0 ? '-' . ($attempt + 1) : '');

        return 'magento-' . substr($paymentReference, 0, self::MAX_KEY_LENGTH - strlen('magento-') - strlen($suffix)) . $suffix;
    }

    /**
     * The refund's status; one this module does not recognise is pending, as the API asks.
     */
    public static function status(array $refund): string
    {
        $status = $refund['status'] ?? '';

        return in_array($status, [self::REFUNDED, self::FAILED, self::CANCELED], true) ? $status : self::PENDING;
    }

    /**
     * A refund's cancelableUntil for a merchant to read: UTC, to the minute. Empty when there is none.
     */
    public static function time(?string $iso): string
    {
        if (null === $iso || '' === $iso) {
            return '';
        }

        try {
            return (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i') . ' UTC';
        } catch (\Exception) {
            return '';
        }
    }

    /**
     * What a 400 says was wrong: the messages per field ({"errors": {"amount": [...]}}), or a problem detail.
     */
    public static function errors(string $body): string
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return '';
        }

        $messages = [];
        foreach (is_array($decoded['errors'] ?? null) ? $decoded['errors'] : [] as $fieldMessages) {
            foreach ((array) $fieldMessages as $message) {
                if (is_string($message) && '' !== $message) {
                    $messages[] = $message;
                }
            }
        }

        if ([] === $messages && is_string($decoded['detail'] ?? null)) {
            $messages[] = $decoded['detail'];
        }

        return implode(' ', $messages);
    }

    /**
     * What the refund transaction keeps of the refund (shown on the transaction in the admin).
     */
    public static function details(array $refund): array
    {
        $details = [];
        foreach (['refundId', 'status', 'amount', 'currency', 'failureReason', 'cancelableUntil'] as $field) {
            if (isset($refund[$field]) && is_scalar($refund[$field])) {
                $details[$field] = $refund[$field];
            }
        }

        return $details;
    }
}
