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
 * Whether a value may be saved as the gateway URL: an absolute HTTPS URL. The client secret is sent there,
 * so anything else is refused on the settings page and the stored value kept.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class MpsPaymentsGatewayUrl
{
    /**
     * @param string $url
     *
     * @return bool
     */
    public static function isValid($url)
    {
        $parts = parse_url((string) $url);

        return false !== filter_var((string) $url, FILTER_VALIDATE_URL)
            && is_array($parts)
            && isset($parts['scheme'])
            && 'https' === strtolower($parts['scheme'])
            && !empty($parts['host']);
    }
}
