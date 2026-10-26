<?php
declare(strict_types=1);

namespace Mps\Payments\Model;

/**
 * Whether MPS could call a URL back. MPS refuses a callback that is not HTTPS on a publicly routable
 * host — and refuses the whole payment with it — so a shop on localhost or a private address sends none
 * and is settled by the return and the scheduled checks instead.
 */
class CallbackUrl
{
    private const PRIVATE_SUFFIXES = ['.localhost', '.local', '.test', '.internal', '.lan'];

    public static function canReceiveWebhooks(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return false;
        }

        $host = strtolower(trim($parts['host'], '[]'));

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return false !== filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        if (!str_contains($host, '.')) {
            return false;
        }

        foreach (self::PRIVATE_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }

        return true;
    }
}
