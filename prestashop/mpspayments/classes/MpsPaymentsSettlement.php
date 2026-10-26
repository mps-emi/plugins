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
 * Reads a cart's MPS payment and acts on what MPS says — never on which URL the shopper came back on.
 *
 * Three things can tell this module to look again: the shopper returning, MPS's webhook, and the
 * scheduled check. All three call settle(), so none of them has to be trusted on its own, and a shop that
 * cannot receive webhooks still settles.
 *
 * Paid makes the order, in Payment accepted. A partial approval makes it too, with the approved amount as
 * the amount paid: PrestaShop itself then puts it in Payment error, and the note says what happened.
 * Failed and abandoned make nothing — the cart is still the shopper's to pay again.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class MpsPaymentsSettlement
{
    /** @var MpsPayments */
    private $module;

    public function __construct(MpsPayments $module)
    {
        $this->module = $module;
    }

    /**
     * @param string $paymentReference One of a cart's attempts.
     * @param bool   $scheduled        A scheduled check: one that finds the payment still open is counted,
     *                                 so the next waits for its own delay.
     *
     * @return string The outcome: paid, partial, failed, abandoned, or pending (also when MPS cannot be read).
     */
    public function settle($paymentReference, $scheduled = false)
    {
        $row = MpsPaymentsRepository::byReference($paymentReference);
        if (null === $row) {
            return MpsPaymentsOutcome::PENDING;
        }

        // The return and the webhook often arrive together; one of them makes the order, the other sees it made.
        $lock = 'mps_payments_cart_' . (int) $row['id_cart'];
        if (1 !== (int) Db::getInstance()->getValue("SELECT GET_LOCK('" . pSQL($lock) . "', 30)", false)) {
            return MpsPaymentsOutcome::PENDING;
        }

        try {
            // Re-read inside the lock: the copy read before it may already be stale.
            return $this->settleLocked(MpsPaymentsRepository::byReference($paymentReference), $scheduled);
        } finally {
            Db::getInstance()->getValue("SELECT RELEASE_LOCK('" . pSQL($lock) . "')", false);
        }
    }

    private function settleLocked(array $row, $scheduled)
    {
        if (null !== $row['outcome']) {
            return $row['outcome'];
        }

        $idShop = (int) $row['id_shop'];
        $reference = $row['payment_reference'];

        try {
            $read = $this->module->api($idShop)->getPayment($reference);
            $outcome = MpsPaymentsOutcome::of($read, (int) $row['amount']);
        } catch (Exception $e) {
            $read = [];
            $outcome = MpsPaymentsOutcome::PENDING;
        }

        if (MpsPaymentsOutcome::PENDING === $outcome) {
            if ($scheduled) {
                MpsPaymentsRepository::update($row['id_mps_payment'], ['checks' => (int) $row['checks'] + 1]);
            }

            return $outcome;
        }

        if (MpsPaymentsOutcome::PAID !== $outcome && MpsPaymentsOutcome::PARTIAL !== $outcome) {
            MpsPaymentsRepository::update($row['id_mps_payment'], ['outcome' => pSQL($outcome)]);
            $this->module->log(sprintf('Cart %d: payment %s %s', $row['id_cart'], $reference, $outcome), 1, $idShop);

            return $outcome;
        }

        $approved = isset($read['amount']) ? (int) $read['amount'] : 0;
        $idOrder = (int) Order::getIdByCartId((int) $row['id_cart']);

        if ($idOrder) {
            // Another attempt for this cart was paid first: this one is money the shopper paid twice.
            $this->note($idOrder, (int) $row['id_cart'], Context::getContext()->getTranslator()->trans(
                'MPS payment %ref% for this cart was paid too, after the order was made. Refund it in MPS.',
                ['%ref%' => $reference],
                'Modules.Mpspayments.Admin'
            ));
        } else {
            $idOrder = $this->makeOrder($row, $outcome, $approved);
            if (!$idOrder) {
                // Left open: the next look, once the shop is fixed, makes the order.
                if ($scheduled) {
                    MpsPaymentsRepository::update($row['id_mps_payment'], ['checks' => (int) $row['checks'] + 1]);
                }

                return MpsPaymentsOutcome::PENDING;
            }
        }

        MpsPaymentsRepository::update($row['id_mps_payment'], ['outcome' => pSQL($outcome), 'id_order' => (int) $idOrder]);
        $this->module->log(sprintf('Cart %d: payment %s %s, order %d', $row['id_cart'], $reference, $outcome, $idOrder), 1, $idShop);

        return $outcome;
    }

    /**
     * Turns the cart into an order, as the shopper's own request would have.
     *
     * @return int The order id, or 0 when MpsPaymentsOrderCheck refuses: then no order is made, and why is logged.
     */
    private function makeOrder(array $row, $outcome, $approved)
    {
        $cart = new Cart((int) $row['id_cart']);
        $customer = new Customer((int) $cart->id_customer);

        // A webhook or a scheduled check runs without the shopper's context; validateOrder needs it.
        $context = Context::getContext();
        Shop::setContext(Shop::CONTEXT_SHOP, (int) $cart->id_shop);
        $context->shop = new Shop((int) $cart->id_shop);
        $context->cart = $cart;
        $context->customer = $customer;
        $context->currency = new Currency((int) $cart->id_currency);
        $context->language = new Language((int) $cart->id_lang);

        // Module::getPaymentModules() reads the cart, customer and shop just put in the context.
        $refusal = MpsPaymentsOrderCheck::refusal(Validate::isLoadedObject($customer), (bool) $this->module->active, Module::getPaymentModules(), $this->module->name);
        if (null !== $refusal) {
            return $this->refuse($row, $refusal);
        }

        $message = MpsPaymentsOutcome::PARTIAL === $outcome
            ? Context::getContext()->getTranslator()->trans(
                'MPS approved %approved% of %expected% (payment %ref%). Check before shipping.',
                [
                    '%approved%' => sprintf('%.2f EUR', $approved / 100),
                    '%expected%' => sprintf('%.2f EUR', $row['amount'] / 100),
                    '%ref%' => $row['payment_reference'],
                ],
                'Modules.Mpspayments.Admin'
            )
            : null;

        $this->module->validateOrder(
            (int) $cart->id,
            (int) Configuration::get('PS_OS_PAYMENT'),
            $approved / 100,
            $this->module->displayName,
            $message,
            ['transaction_id' => $row['payment_reference']],
            (int) $cart->id_currency,
            false,
            $customer->secure_key
        );

        return (int) $this->module->currentOrder;
    }

    /**
     * Logs, debug log or not, that a paid cart was not made an order: the shopper's money arrived at MPS.
     *
     * @return int 0, no order
     */
    private function refuse(array $row, $reason)
    {
        PrestaShopLogger::addLog(sprintf('MPS Payments: cart %d was paid (payment %s) but no order was made: %s', $row['id_cart'], $row['payment_reference'], $reason), 3, null, 'Cart', (int) $row['id_cart'], true);

        return 0;
    }

    /**
     * A private note on the order, for the merchant only.
     */
    private function note($idOrder, $idCart, $text)
    {
        $message = new Message();
        $message->id_order = (int) $idOrder;
        $message->id_cart = (int) $idCart;
        $message->message = $text;
        $message->private = true;
        $message->add();
    }
}
