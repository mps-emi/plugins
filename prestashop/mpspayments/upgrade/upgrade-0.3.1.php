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
 * 0.3.1 stores each checkout attempt, with its Idempotency-Key, before its payment is opened: the payment
 * reference may be empty until MPS answered. Attempts stored before have no key and are never resent.
 *
 * @param MpsPayments $module
 *
 * @return bool
 */
function upgrade_module_0_3_1($module)
{
    return Db::getInstance()->execute('ALTER TABLE `' . _DB_PREFIX_ . MpsPaymentsRepository::TABLE . '`
        MODIFY `payment_reference` VARCHAR(64) NULL,
        ADD `idempotency_key` VARCHAR(64) NULL AFTER `return_token`');
}
