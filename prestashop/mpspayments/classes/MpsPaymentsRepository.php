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
 * The MPS payments opened for carts, one row per checkout attempt: the table `{prefix}mpspayments_payment`.
 *
 * A cart becomes an order only once one of its payments is paid, so the attempt — not an order — is
 * what the return, the webhook and the scheduled checks look up.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class MpsPaymentsRepository
{
    const TABLE = 'mpspayments_payment';

    public static function createTable()
    {
        return Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE . '` (
            `id_mps_payment` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_cart` INT UNSIGNED NOT NULL,
            `id_shop` INT UNSIGNED NOT NULL,
            `payment_reference` VARCHAR(64) NOT NULL,
            `return_token` CHAR(32) NOT NULL,
            `amount` INT NOT NULL COMMENT \'cents, as asked of MPS\',
            `opened_at` INT UNSIGNED NOT NULL,
            `checks` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `outcome` VARCHAR(16) NULL,
            `id_order` INT UNSIGNED NULL,
            PRIMARY KEY (`id_mps_payment`),
            UNIQUE KEY `payment_reference` (`payment_reference`),
            UNIQUE KEY `return_token` (`return_token`),
            KEY `id_cart` (`id_cart`),
            KEY `open` (`outcome`, `opened_at`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');
    }

    public static function dropTable()
    {
        return Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::TABLE . '`');
    }

    public static function add($idCart, $idShop, $paymentReference, $returnToken, $amount)
    {
        return Db::getInstance()->insert(self::TABLE, [
            'id_cart' => (int) $idCart,
            'id_shop' => (int) $idShop,
            'payment_reference' => pSQL($paymentReference),
            'return_token' => pSQL($returnToken),
            'amount' => (int) $amount,
            'opened_at' => time(),
        ]);
    }

    /**
     * @return array|null
     */
    public static function byReference($paymentReference)
    {
        return self::one('payment_reference = \'' . pSQL($paymentReference) . '\'');
    }

    /**
     * @return array|null
     */
    public static function byReturnToken($returnToken)
    {
        return self::one('return_token = \'' . pSQL($returnToken) . '\'');
    }

    /**
     * The attempts still open, opened within the last day, for the scheduled checks.
     *
     * @return array
     */
    public static function open()
    {
        return Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE outcome IS NULL AND opened_at >= ' . (time() - 86400)) ?: [];
    }

    public static function update($idMpsPayment, array $fields)
    {
        return Db::getInstance()->update(self::TABLE, $fields, 'id_mps_payment = ' . (int) $idMpsPayment);
    }

    private static function one($where)
    {
        $row = Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE ' . $where, false);

        return is_array($row) ? $row : null;
    }
}
