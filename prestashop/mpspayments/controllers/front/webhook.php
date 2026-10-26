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
 * MPS's notification that a payment arrived. Answered 2xx whenever it was understood, including for a
 * payment this shop does not know, so MPS does not keep retrying something we will never act on. Without a
 * signing secret configured it is answered 200 and ignored: MPS signs everything it sends.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class MpsPaymentsWebhookModuleFrontController extends ModuleFrontController
{
    /** @var MpsPayments */
    public $module;

    public function postProcess()
    {
        $idShop = (int) $this->context->shop->id;
        $body = (string) file_get_contents('php://input');

        $secret = $this->module->webhookSecret($idShop);
        $timestamp = isset($_SERVER['HTTP_X_MPS_TIMESTAMP']) ? (string) $_SERVER['HTTP_X_MPS_TIMESTAMP'] : '';
        $signature = isset($_SERVER['HTTP_X_MPS_SIGNATURE']) ? (string) $_SERVER['HTTP_X_MPS_SIGNATURE'] : '';

        $verdict = MpsPaymentsSignature::verdict($secret, $body, $timestamp, $signature, time());
        if (MpsPaymentsSignature::IGNORE === $verdict) {
            // MPS signs every notification it sends; the cart is settled by the return and the scheduled checks.
            $this->module->log('Webhook ignored: no signing secret configured', 1, $idShop);
            $this->answer(200);
        }
        if (MpsPaymentsSignature::REFUSE === $verdict) {
            $this->module->log('Webhook refused: signature or timestamp does not match', 2, $idShop);
            $this->answer(401);
        }

        $event = json_decode($body, true);
        $reference = is_array($event) && isset($event['paymentReference']) && is_string($event['paymentReference']) ? $event['paymentReference'] : '';
        $row = '' === $reference ? null : MpsPaymentsRepository::byReference($reference);

        $this->module->log(sprintf(
            'Webhook %s for payment %s: %s',
            isset($_SERVER['HTTP_X_MPS_DELIVERY_ID']) ? (string) $_SERVER['HTTP_X_MPS_DELIVERY_ID'] : '-',
            '' === $reference ? '-' : $reference,
            null === $row ? 'no cart of this shop' : 'cart ' . $row['id_cart']
        ), 1, $idShop);

        if (null !== $row) {
            // The notification only says "look"; what the payment is comes from reading it.
            (new MpsPaymentsSettlement($this->module))->settle($reference);
        }

        $this->answer(200);
    }

    /**
     * MPS's notifications are answered in maintenance mode too: the shop being closed to visitors does not
     * stop it being paid.
     */
    protected function displayMaintenancePage()
    {
    }

    private function answer($status)
    {
        http_response_code($status);
        exit;
    }
}
