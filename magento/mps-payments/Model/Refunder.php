<?php
declare(strict_types=1);

namespace Mps\Payments\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Refunds an order's MPS payment for a credit memo refunded online (Gateway\Command\RefundCommand).
 *
 * MPS accepting the refund is what lets the credit memo be saved: anything else throws a LocalizedException
 * with a message for the merchant, and Magento saves nothing. A refund MPS accepted is pending at first —
 * every refund, live or test, is held 4 hours (cancelableUntil), cancelable in MPS, before it is sent — and
 * is accepted as it is: the amount is reserved for it in MPS.
 */
class Refunder
{
    /** How long a count of refused attempts is kept: a merchant tries again the same day or not at all. */
    private const ATTEMPTS_LIFETIME = 86400;

    public function __construct(
        private readonly Api $api,
        private readonly Config $config,
        private readonly CacheInterface $cache,
        private readonly DebugLog $log,
    ) {
    }

    /**
     * @param float|string $amount         The credit memo's total, in euros.
     * @param float|string $refundedBefore What was refunded of the payment before it, in euros.
     * @return array The refund as MPS answered it: refundId, status, amount, cancelableUntil …
     * @throws LocalizedException When MPS did not accept the refund; nothing may be recorded as refunded.
     */
    public function refund(string $paymentReference, float|string $amount, float|string $refundedBefore, ?int $storeId): array
    {
        if ('' === $paymentReference) {
            throw new LocalizedException(__('This order has no MPS payment to refund online. Refund it offline.'));
        }

        if (!$this->config->hasCredentials($storeId)) {
            throw new LocalizedException(__('Refunds go through MPS with an API key, and this shop has none. Paste an API key from the MPS platform (Online › Get started) under Stores › Configuration › Sales › Payment Methods › MPS Payments, or refund offline.'));
        }

        $cents = Outcome::cents($amount);
        if ($cents <= 0) {
            throw new LocalizedException(__('There is nothing to refund: the credit memo total is zero.'));
        }

        $before = Outcome::cents($refundedBefore);
        $attemptsKey = 'mps_payments_refund_attempts_' . hash('sha256', $paymentReference . '|' . $before . '|' . $cents);
        $attempts = (int) $this->cache->load($attemptsKey);
        $idempotencyKey = Refund::idempotencyKey($paymentReference, $before, $cents, $attempts);

        try {
            $refund = $this->api->createRefund($paymentReference, Refund::request($cents), $idempotencyKey, $storeId);
        } catch (GatewayException $e) {
            throw new LocalizedException($this->refusal($e, $paymentReference, $storeId), $e);
        }

        $refundId = is_string($refund['refundId'] ?? null) ? $refund['refundId'] : '';
        if ('' === $refundId) {
            throw new LocalizedException(__('MPS did not confirm the refund. Check the payment in MPS before trying again.'));
        }

        $status = Refund::status($refund);
        $this->log->log(sprintf('Refund %s of payment %s (key %s): %s', $refundId, $paymentReference, $idempotencyKey, $status), $storeId);

        if (Refund::FAILED === $status || Refund::CANCELED === $status) {
            // MPS frees a refused amount, but would answer this key with this refusal again: the next try is new.
            $this->cache->save((string) ($attempts + 1), $attemptsKey, [], self::ATTEMPTS_LIFETIME);

            throw new LocalizedException(Refund::FAILED === $status
                ? __('MPS refused the refund (%1). Nothing was refunded.', is_string($refund['failureReason'] ?? null) ? $refund['failureReason'] : 'unknown')
                : __('MPS refund %1 was canceled. Nothing was refunded.', $refundId));
        }

        return $refund;
    }

    /**
     * The order comment for a refund MPS accepted.
     */
    public function comment(array $refund): Phrase
    {
        $refundId = (string) ($refund['refundId'] ?? '');

        if (Refund::REFUNDED === Refund::status($refund)) {
            return __('MPS refund %1 refunded.', $refundId);
        }

        $until = Refund::time(is_string($refund['cancelableUntil'] ?? null) ? $refund['cancelableUntil'] : null);

        return '' === $until
            ? __('MPS refund %1 is being processed.', $refundId)
            : __('MPS refund %1 is being processed. It is sent after %2 and can be canceled in MPS until then.', $refundId, $until);
    }

    /**
     * Why MPS refused, for the merchant creating the credit memo.
     */
    private function refusal(GatewayException $e, string $paymentReference, ?int $storeId): Phrase
    {
        return match ($e->getCode()) {
            400 => '' !== ($errors = Refund::errors($e->responseBody))
                ? __('MPS refused the refund: %1', $errors)
                : __('MPS refused the refund. Check the amount against what is left to refund of the payment.'),
            401 => __('MPS did not accept the API key. Check it under Stores › Configuration › Sales › Payment Methods › MPS Payments.'),
            403 => __('Your MPS API key may not refund: it lacks the payments:refund permission. A live key has it only when MPS granted it: ask MPS to grant it to your key. A test key always has it. Nothing was refunded.'),
            404 => __('MPS does not know payment %1 for this API key. A test key cannot refund a live payment, nor a live key a test payment.', $paymentReference),
            409, 429 => __('MPS cannot refund payment %1 right now: it is not paid yet, or another refund of it is being made. Try again in a moment.', $paymentReference),
            default => __('MPS did not answer. The refund may still have been made: try again with the same credit memo, it is never refunded twice. Or check the payment in MPS.'),
        };
    }
}
