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
 *
 * An attempt is stored, with its Idempotency-Key, before MPS is asked to open its payment; the payment
 * reference follows once MPS answered. Until then payment_reference is NULL.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class MpsPaymentsRepository
{
    const TABLE = 'mpspayments_payment';

    /** How long MPS keeps an Idempotency-Key: an attempt older than this is never resent. */
    const KEY_LIFETIME = 86400;

    public static function createTable()
    {
        return Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE . '` (
            `id_mps_payment` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_cart` INT UNSIGNED NOT NULL,
            `id_shop` INT UNSIGNED NOT NULL,
            `payment_reference` VARCHAR(64) NULL,
            `return_token` CHAR(32) NOT NULL,
            `idempotency_key` VARCHAR(64) NULL,
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

    /**
     * Stores a new attempt, before its payment is opened.
     *
     * @return array The attempt: id_mps_payment, return_token, idempotency_key, amount.
     */
    public static function start($idCart, $idShop, $amount)
    {
        $attempt = [
            'id_cart' => (int) $idCart,
            'id_shop' => (int) $idShop,
            'return_token' => bin2hex(random_bytes(16)),
            'idempotency_key' => self::newIdempotencyKey($idCart),
            'amount' => (int) $amount,
            'opened_at' => time(),
        ];
        Db::getInstance()->insert(self::TABLE, $attempt);
        $attempt['id_mps_payment'] = (int) Db::getInstance()->Insert_ID();
        $attempt['payment_reference'] = null;

        return $attempt;
    }

    /**
     * A key no other attempt has: prestashop-cart-<id_cart>-<random UUID>.
     *
     * @return string
     */
    public static function newIdempotencyKey($idCart)
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);

        return 'prestashop-cart-' . (int) $idCart . '-' . vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * Whether a cart's last attempt is the one a checkout now is a resend of — a double submit, a reload, a
     * shopper back from a checkout they did not finish — so its payment is asked for again with the same
     * key and the same body, and MPS hands back that payment instead of opening a second. Not when it has
     * an outcome (failed, abandoned, paid), the amount changed, MPS no longer keeps its key, or it was
     * stored before attempts had keys.
     *
     * @param array|null $attempt The cart's last attempt (lastForCart).
     * @param int        $amount  The cart total now, in cents.
     * @param int        $now
     *
     * @return bool
     */
    public static function isResendable($attempt, $amount, $now)
    {
        return null !== $attempt
            && null === $attempt['outcome']
            && !empty($attempt['idempotency_key'])
            && (int) $attempt['amount'] === (int) $amount
            && (int) $attempt['opened_at'] >= $now - self::KEY_LIFETIME;
    }

    /**
     * @return array|null
     */
    public static function lastForCart($idCart)
    {
        $row = Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE id_cart = ' . (int) $idCart . ' ORDER BY id_mps_payment DESC', false);

        return is_array($row) ? $row : null;
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
     * The attempts still open, opened within the last day and with a payment, for the scheduled checks.
     *
     * @return array
     */
    public static function open()
    {
        return Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE outcome IS NULL AND payment_reference IS NOT NULL AND opened_at >= ' . (time() - 86400)) ?: [];
    }

    /**
     * The MPS payment an order was made from: the attempt whose reference is the order's payment transaction
     * id. A cart paid twice has two attempts on its order; the second is the one to refund by hand.
     *
     * @return string|null
     */
    public static function paidReferenceForOrder($idOrder)
    {
        $reference = Db::getInstance()->getValue('SELECT p.payment_reference FROM `' . _DB_PREFIX_ . self::TABLE . '` p'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'orders` o ON o.id_order = p.id_order'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'order_payment` op ON op.order_reference = o.reference AND op.transaction_id = p.payment_reference'
            . ' WHERE p.id_order = ' . (int) $idOrder . " AND p.outcome IN ('paid', 'partial')"
            . ' ORDER BY p.id_mps_payment', false);

        return false === $reference || null === $reference || '' === $reference ? null : (string) $reference;
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
