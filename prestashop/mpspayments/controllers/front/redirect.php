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
 * The payment option posts here: open the MPS payment for the cart and send the shopper to the hosted
 * checkout. No order exists yet; it is made once MPS says the cart was paid.
 *
 * Each checkout attempt has its own Idempotency-Key, stored before the payment is asked for. A resend of
 * the attempt (MpsPaymentsRepository::isResendable) sends the same key and body, so MPS hands back the
 * payment it already opened; a new attempt gets a new key.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class MpsPaymentsRedirectModuleFrontController extends ModuleFrontController
{
    /** @var MpsPayments */
    public $module;

    public function postProcess()
    {
        $cart = $this->context->cart;

        if (!$cart->id || !$cart->id_customer || !$cart->id_address_invoice || !$cart->nbProducts()
            || !$this->module->active || !$this->module->isUsable((int) $cart->id_shop) || !$this->module->isEuro($cart)
        ) {
            Tools::redirect($this->context->link->getPageLink('order'));
        }

        $amount = MpsPaymentsOutcome::cents($cart->getOrderTotal(true, Cart::BOTH));
        $attempt = $this->attempt($cart, $amount);

        $body = [
            'amount' => $amount,
            'reference' => 'cart-' . (int) $cart->id,
            'successUrl' => $this->context->link->getModuleLink($this->module->name, 'return', ['t' => $attempt['return_token'], 'result' => 'success'], true),
            'failureUrl' => $this->context->link->getModuleLink($this->module->name, 'return', ['t' => $attempt['return_token'], 'result' => 'failure'], true),
        ];

        $callbackUrl = $this->context->link->getModuleLink($this->module->name, 'webhook', [], true);
        if (MpsPaymentsCallbackUrl::canReceiveWebhooks($callbackUrl)) {
            $body['callbackUrl'] = $callbackUrl;
        }

        try {
            $payment = $this->module->api((int) $cart->id_shop)->createPayment($body, $attempt['idempotency_key']);
        } catch (Exception $e) {
            // A refusal is final for this key: the next try is a new attempt. No answer, a 5xx or a 409 may
            // have opened the payment, so the next try resends this one.
            if ($e->getCode() >= 400 && $e->getCode() < 500 && 409 !== $e->getCode()) {
                MpsPaymentsRepository::update($attempt['id_mps_payment'], ['outcome' => pSQL(MpsPaymentsOutcome::FAILED)]);
            }
            $payment = [];
        }

        if (empty($payment['paymentReference']) || empty($payment['checkoutUrl'])) {
            $this->errors[] = $this->trans('The payment could not be started. Please try again or choose another payment method.', [], 'Modules.Mpspayments.Shop');
            $this->redirectWithNotifications($this->context->link->getPageLink('order'));
        }

        MpsPaymentsRepository::update($attempt['id_mps_payment'], ['payment_reference' => pSQL($payment['paymentReference'])]);

        // checkoutUrl pays this payment for whoever holds it: hand it to the browser and nowhere else.
        Tools::redirect($payment['checkoutUrl']);
    }

    /**
     * The cart's last attempt when this checkout resends it, else a new one. One whose payment is open is
     * read first: a payment MPS already failed or abandoned is settled, and a new attempt made.
     *
     * @return array id_mps_payment, return_token, idempotency_key
     */
    private function attempt(Cart $cart, $amount)
    {
        // A double submit arrives twice at once; both must find the same attempt.
        $lock = 'mps_payments_attempt_' . (int) $cart->id;
        $locked = 1 === (int) Db::getInstance()->getValue("SELECT GET_LOCK('" . pSQL($lock) . "', 30)", false);

        try {
            $last = MpsPaymentsRepository::lastForCart($cart->id);
            if (MpsPaymentsRepository::isResendable($last, $amount, time())
                && (null === $last['payment_reference']
                    || MpsPaymentsOutcome::PENDING === (new MpsPaymentsSettlement($this->module))->settle($last['payment_reference']))
            ) {
                return $last;
            }

            return MpsPaymentsRepository::start($cart->id, $cart->id_shop, $amount);
        } finally {
            if ($locked) {
                Db::getInstance()->getValue("SELECT RELEASE_LOCK('" . pSQL($lock) . "')", false);
            }
        }
    }
}
