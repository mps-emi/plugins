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
 * Whether MPS could call a URL back. MPS refuses a callback that is not HTTPS on a publicly routable
 * host — and refuses the whole payment with it — so a shop on localhost or a private address sends none
 * and is settled by the return and the scheduled checks instead.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class MpsPaymentsCallbackUrl
{
    /**
     * @param string $url
     *
     * @return bool
     */
    public static function canReceiveWebhooks($url)
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme']) || 'https' !== $parts['scheme'] || empty($parts['host'])) {
            return false;
        }

        $host = strtolower(trim($parts['host'], '[]'));

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return false !== filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        if (false === strpos($host, '.')) {
            return false;
        }

        foreach (['.localhost', '.local', '.test', '.internal', '.lan'] as $suffix) {
            if (substr($host, -strlen($suffix)) === $suffix) {
                return false;
            }
        }

        return true;
    }
}
