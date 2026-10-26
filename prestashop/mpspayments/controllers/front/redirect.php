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

        $returnToken = bin2hex(random_bytes(16));
        $amount = MpsPaymentsOutcome::cents($cart->getOrderTotal(true, Cart::BOTH));

        $body = [
            'amount' => $amount,
            'reference' => 'cart-' . (int) $cart->id,
            'successUrl' => $this->context->link->getModuleLink($this->module->name, 'return', ['t' => $returnToken, 'result' => 'success'], true),
            'failureUrl' => $this->context->link->getModuleLink($this->module->name, 'return', ['t' => $returnToken, 'result' => 'failure'], true),
        ];

        $callbackUrl = $this->context->link->getModuleLink($this->module->name, 'webhook', [], true);
        if (MpsPaymentsCallbackUrl::canReceiveWebhooks($callbackUrl)) {
            $body['callbackUrl'] = $callbackUrl;
        }

        try {
            $payment = $this->module->api((int) $cart->id_shop)->createPayment($body);
        } catch (Exception $e) {
            $payment = [];
        }

        if (empty($payment['paymentReference']) || empty($payment['checkoutUrl'])) {
            $this->errors[] = $this->trans('The payment could not be started. Please try again or choose another payment method.', [], 'Modules.Mpspayments.Shop');
            $this->redirectWithNotifications($this->context->link->getPageLink('order'));
        }

        MpsPaymentsRepository::add($cart->id, $cart->id_shop, $payment['paymentReference'], $returnToken, $amount);

        // checkoutUrl pays this payment for whoever holds it: hand it to the browser and nowhere else.
        Tools::redirect($payment['checkoutUrl']);
    }
}
