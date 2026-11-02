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
 * 0.3.0 refunds credit slips in MPS: a shop upgrading from 0.2.0 registers the hook install() registers.
 *
 * @param MpsPayments $module
 *
 * @return bool
 */
function upgrade_module_0_3_0($module)
{
    return $module->registerHook('actionOrderSlipAdd');
}
