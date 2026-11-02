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
 * Refunds a credit slip of an MPS order in MPS — the one place a PrestaShop refund becomes money back.
 *
 * PrestaShop makes a credit slip for a partial refund, a standard refund and a product return alike, and
 * calls actionOrderSlipAdd once it is saved: the slip's amount is refunded on the order's MPS payment. The
 * slip is PrestaShop's and stays whatever MPS answers; what MPS answered goes in a private order message,
 * in the log, and in front of the employee who made the slip.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class MpsPaymentsRefunder
{
    /** @var MpsPayments */
    private $module;

    public function __construct(MpsPayments $module)
    {
        $this->module = $module;
    }

    /**
     * @param Order          $order
     * @param OrderSlip|null $slip  The slip just made (PrestaShop 9 passes it); null reads the order's newest.
     *
     * @return array|null [severity: success, warning or error; the message] for the employee, or null when
     *                    the order is not an MPS order or the slip is for nothing.
     */
    public function refund(Order $order, $slip)
    {
        if ($order->module !== $this->module->name) {
            return null;
        }

        $row = $this->slipRow((int) $order->id, $slip instanceof OrderSlip ? (int) $slip->id : 0);
        if (null === $row) {
            return null;
        }

        // "Generate a voucher" on the refund form: the voucher is the refund, the slip its record.
        $form = Tools::getValue('cancel_product');
        if (is_array($form) && !empty($form['voucher'])) {
            return $this->report($order, 'warning', $this->translator()->trans('No MPS refund for this credit slip: a voucher was generated instead.', [], 'Modules.Mpspayments.Admin'));
        }

        $paymentReference = MpsPaymentsRepository::paidReferenceForOrder((int) $order->id);
        if (null === $paymentReference) {
            return $this->report($order, 'error', $this->translator()->trans('No MPS refund for this credit slip: no paid MPS payment was found for this order. Refund the customer in MPS.', [], 'Modules.Mpspayments.Admin'));
        }

        $customer = new Customer((int) $order->id_customer);
        $taxIncluded = PS_TAX_INC === (int) Group::getPriceDisplayMethod((int) $customer->id_default_group);
        $cents = MpsPaymentsRefund::amount($row, $taxIncluded);
        if (null === $cents) {
            return $this->report($order, 'error', $this->translator()->trans('No MPS refund for this credit slip: its tax-included amount is not on it (prices shown tax excluded, with a chosen amount or a voucher deducted). Refund the customer in MPS.', [], 'Modules.Mpspayments.Admin'));
        }
        if ($cents <= 0) {
            return null;
        }

        try {
            list($status, $answer) = $this->module->api((int) $order->id_shop)->createRefund(
                $paymentReference,
                MpsPaymentsRefund::body($cents),
                MpsPaymentsRefund::idempotencyKey((int) $row['id_order_slip'], $paymentReference)
            );
            $result = MpsPaymentsRefund::outcome($status, $answer);
        } catch (Exception $e) {
            $result = MpsPaymentsRefund::outcome(0, []);
            $result['detail'] = $e->getMessage();
        }

        $amount = sprintf('%.2f EUR', (null !== $result['amount'] ? $result['amount'] : $cents) / 100);
        list($severity, $text) = $this->describe($result, $amount, $paymentReference);

        $this->module->log(sprintf('Order %d, credit slip %d: refund of %d on payment %s %s%s', $order->id, $row['id_order_slip'], $cents, $paymentReference, $result['outcome'], null !== $result['refundId'] ? ', refund ' . $result['refundId'] : ''), 'success' === $severity ? 1 : 2, (int) $order->id_shop);

        return $this->report($order, $severity, $text);
    }

    /**
     * @return array [severity, text]
     */
    private function describe(array $result, $amount, $paymentReference)
    {
        $reason = (string) $result['detail'];

        switch ($result['outcome']) {
            case MpsPaymentsRefund::REFUNDED:
                return ['success', $this->translator()->trans('MPS refunded %amount% (refund %refund%).', ['%amount%' => $amount, '%refund%' => $result['refundId']], 'Modules.Mpspayments.Admin')];
            case MpsPaymentsRefund::PENDING:
                return ['success', null !== $result['cancelableUntil']
                    ? $this->translator()->trans('MPS refund %refund% of %amount% is being processed. It is sent after %until%; until then you can cancel it in MPS.', ['%refund%' => $result['refundId'], '%amount%' => $amount, '%until%' => $result['cancelableUntil']], 'Modules.Mpspayments.Admin')
                    : $this->translator()->trans('MPS refund %refund% of %amount% is being processed; its outcome is in MPS.', ['%refund%' => $result['refundId'], '%amount%' => $amount], 'Modules.Mpspayments.Admin'), ];
            case MpsPaymentsRefund::FAILED:
                return ['error', $this->translator()->trans('MPS refused refund %refund% of %amount% (%reason%). Nothing was paid back to the customer.', ['%refund%' => $result['refundId'], '%amount%' => $amount, '%reason%' => $reason], 'Modules.Mpspayments.Admin')];
            case MpsPaymentsRefund::INVALID:
                return ['error', $this->translator()->trans('MPS did not refund %amount% of payment %payment% (%reason%). Nothing was paid back to the customer.', ['%amount%' => $amount, '%payment%' => $paymentReference, '%reason%' => $reason], 'Modules.Mpspayments.Admin')];
            case MpsPaymentsRefund::NOT_PERMITTED:
                return ['error', $this->translator()->trans('MPS did not refund %amount%: the API key may not refund (payments:refund). A live key may only once MPS granted it: ask MPS. A test key always may. Nothing was paid back to the customer.', ['%amount%' => $amount], 'Modules.Mpspayments.Admin')];
            case MpsPaymentsRefund::NOT_FOUND:
                return ['error', $this->translator()->trans('MPS did not refund %amount%: it knows no payment %payment% for this API key (a test key cannot refund a live payment, nor a live key a test one). Nothing was paid back to the customer.', ['%amount%' => $amount, '%payment%' => $paymentReference], 'Modules.Mpspayments.Admin')];
            case MpsPaymentsRefund::NOT_YET:
                return ['error', $this->translator()->trans('MPS did not refund %amount% of payment %payment%: the payment is not executed yet, or MPS was busy with it. Nothing was paid back to the customer; refund it in MPS.', ['%amount%' => $amount, '%payment%' => $paymentReference], 'Modules.Mpspayments.Admin')];
            default:
                return ['warning', $this->translator()->trans('The refund of %amount% of payment %payment% has no answer from MPS (%reason%). Check in MPS whether it was refunded before refunding it again.', ['%amount%' => $amount, '%payment%' => $paymentReference, '%reason%' => $reason], 'Modules.Mpspayments.Admin')];
        }
    }

    /**
     * The order_slip row: the given one, or the order's newest.
     *
     * @return array|null
     */
    private function slipRow($idOrder, $idOrderSlip)
    {
        $row = Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'order_slip` WHERE id_order = ' . (int) $idOrder
            . ($idOrderSlip ? ' AND id_order_slip = ' . (int) $idOrderSlip : '') . ' ORDER BY id_order_slip DESC', false);

        return is_array($row) ? $row : null;
    }

    /**
     * A private message on the order, for the merchant only, where PrestaShop 8 and 9 show them (the
     * order's customer thread); a refusal is logged whatever the debug setting.
     *
     * @return array [severity, text]
     */
    private function report(Order $order, $severity, $text)
    {
        if ('success' !== $severity) {
            PrestaShopLogger::addLog('MPS Payments: ' . $text, 'error' === $severity ? 3 : 2, null, 'Order', (int) $order->id, true);
        }

        $customer = new Customer((int) $order->id_customer);
        if (Validate::isLoadedObject($customer)) {
            $idThread = (int) CustomerThread::getIdCustomerThreadByEmailAndIdOrder($customer->email, (int) $order->id);
            if (!$idThread) {
                $thread = new CustomerThread();
                $thread->id_contact = 0;
                $thread->id_customer = (int) $order->id_customer;
                $thread->id_shop = (int) $order->id_shop;
                $thread->id_order = (int) $order->id;
                $thread->id_lang = (int) $order->id_lang;
                $thread->email = $customer->email;
                $thread->status = 'open';
                $thread->token = Tools::passwdGen(12);
                $thread->add();
                $idThread = (int) $thread->id;
            }

            $message = new CustomerMessage();
            $message->id_customer_thread = $idThread;
            $employee = Context::getContext()->employee;
            $message->id_employee = $employee ? (int) $employee->id : 0;
            $message->message = $text;
            $message->private = true;
            $message->add();
        }

        return [$severity, $text];
    }

    private function translator()
    {
        return Context::getContext()->getTranslator();
    }
}
