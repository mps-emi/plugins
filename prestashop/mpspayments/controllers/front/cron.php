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
 * The scheduled checks: re-reads the MPS payments still open, at 10 min, 30 min, 1 h and 6 h after each
 * was opened. This is what settles a cart on a shop that cannot receive webhooks — localhost, or a private
 * network — when the shopper closes the tab. Call it every five minutes, with the token from the module's
 * settings page.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class MpsPaymentsCronModuleFrontController extends ModuleFrontController
{
    /** Seconds after opening at which a check runs. */
    const DELAYS = [600, 1800, 3600, 21600];

    /** @var MpsPayments */
    public $module;

    public function postProcess()
    {
        $token = (string) Configuration::getGlobalValue(MpsPayments::CRON_TOKEN);
        if ('' === $token || !hash_equals($token, (string) Tools::getValue('token'))) {
            http_response_code(403);
            exit;
        }

        $settlement = new MpsPaymentsSettlement($this->module);
        $checked = 0;

        foreach (MpsPaymentsRepository::open() as $row) {
            $checks = (int) $row['checks'];
            if ($checks >= count(self::DELAYS) || time() < (int) $row['opened_at'] + self::DELAYS[$checks]) {
                continue;
            }

            $settlement->settle($row['payment_reference'], true);
            ++$checked;
        }

        header('Content-Type: text/plain');
        echo 'checked ' . $checked . PHP_EOL;
        exit;
    }

    /**
     * The checks run in maintenance mode too: carts paid before the shop closed still need their orders.
     */
    protected function displayMaintenancePage()
    {
    }
}
