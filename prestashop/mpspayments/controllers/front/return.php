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
 * The shopper is back from the hosted checkout. Which URL they came back on is a hint for what to show
 * them, never proof: the cart is settled from the payment as MPS reads it.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class MpsPaymentsReturnModuleFrontController extends ModuleFrontController
{
    /** How often the "confirming your payment" page looks again before it stops refreshing. */
    const WAIT_ROUNDS = 6;

    /** @var MpsPayments */
    public $module;

    public function initContent()
    {
        parent::initContent();

        $row = MpsPaymentsRepository::byReturnToken((string) Tools::getValue('t'));
        $result = (string) Tools::getValue('result');
        $cart = null === $row ? null : new Cart((int) $row['id_cart']);

        // Only the shopper whose cart it is: anyone else is sent to the shop's front page.
        if (null === $row || (int) $cart->id_customer !== (int) $this->context->customer->id) {
            Tools::redirect($this->context->link->getPageLink('index'));
        }

        $outcome = (new MpsPaymentsSettlement($this->module))->settle($row['payment_reference']);
        $this->module->log(sprintf('Shopper returned for cart %d on the %s URL; outcome %s', $cart->id, $result, $outcome), 1, (int) $cart->id_shop);

        if (MpsPaymentsOutcome::PAID === $outcome || MpsPaymentsOutcome::PARTIAL === $outcome) {
            $customer = new Customer((int) $cart->id_customer);
            Tools::redirect($this->context->link->getPageLink('order-confirmation', true, null, [
                'id_cart' => (int) $cart->id,
                'id_module' => (int) $this->module->id,
                'id_order' => (int) Order::getIdByCartId((int) $cart->id),
                'key' => $customer->secure_key,
            ]));
        }

        if (MpsPaymentsOutcome::PENDING === $outcome && 'success' === $result) {
            // MPS has not finished; look again shortly, then leave it to the webhook and the scheduled checks.
            $round = (int) Tools::getValue('round');
            $this->context->smarty->assign([
                'mps_refresh_url' => $round < self::WAIT_ROUNDS
                    ? $this->context->link->getModuleLink($this->module->name, 'return', ['t' => $row['return_token'], 'result' => 'success', 'round' => $round + 1], true)
                    : null,
            ]);
            $this->setTemplate('module:mpspayments/views/templates/front/waiting.tpl');

            return;
        }

        $this->errors[] = $this->trans('Your payment was not completed. Please try again or choose another payment method.', [], 'Modules.Mpspayments.Shop');
        $this->redirectWithNotifications($this->context->link->getPageLink('order'));
    }
}
