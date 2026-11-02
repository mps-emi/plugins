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
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 1.0.0 connects with the API key only: the client ID and secret a shop set up before API keys kept, and
 * the tokens they bought (MPS_PAYMENTS_TOKEN_<hash>), are deleted, in every shop, so no secret is left
 * behind. A shop without an API key is then not configured: MPS is not offered until one is saved.
 *
 * @param MpsPayments $module
 *
 * @return bool
 */
function upgrade_module_1_0_0($module)
{
    return Configuration::deleteByName('MPS_PAYMENTS_CLIENT_ID')
        && Configuration::deleteByName('MPS_PAYMENTS_CLIENT_SECRET')
        && Db::getInstance()->delete('configuration', "name LIKE 'MPS\\_PAYMENTS\\_TOKEN\\_%'");
}
