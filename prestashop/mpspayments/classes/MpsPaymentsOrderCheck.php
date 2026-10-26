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
 * The last checks before a paid cart becomes an order, as PrestaShop's own payment modules make them: the
 * customer still exists, the module is still enabled, and it is still offered for this cart (its country,
 * currency and customer groups may have changed since the shopper chose it).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class MpsPaymentsOrderCheck
{
    /**
     * @param bool   $customerLoaded Validate::isLoadedObject() of the cart's customer.
     * @param bool   $moduleActive   The module's own active flag.
     * @param array  $paymentModules Module::getPaymentModules() in the cart's context: rows with a 'name'.
     * @param string $moduleName     This module's name.
     *
     * @return string|null Why no order may be made, or null when it may.
     */
    public static function refusal($customerLoaded, $moduleActive, array $paymentModules, $moduleName)
    {
        if (!$customerLoaded) {
            return 'the customer no longer exists';
        }
        if (!$moduleActive) {
            return 'the module is disabled';
        }
        foreach ($paymentModules as $module) {
            if (isset($module['name']) && $moduleName === $module['name']) {
                return null;
            }
        }

        return 'the module is no longer offered for this cart';
    }
}
