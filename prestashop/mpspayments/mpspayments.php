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
 * MPS Payments for PrestaShop: a payment option that sends the shopper to the MPS-hosted checkout.
 *
 * The order is created only once MPS says the cart was paid (MpsPaymentsSettlement), so an unpaid
 * attempt leaves no order, no confirmation e-mail and no reserved stock behind — the cart simply stays.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/MpsPaymentsSignature.php';
require_once __DIR__ . '/classes/MpsPaymentsCallbackUrl.php';
require_once __DIR__ . '/classes/MpsPaymentsOutcome.php';
require_once __DIR__ . '/classes/MpsPaymentsApi.php';
require_once __DIR__ . '/classes/MpsPaymentsRepository.php';
require_once __DIR__ . '/classes/MpsPaymentsSettlement.php';
require_once __DIR__ . '/classes/MpsPaymentsGatewayUrl.php';
require_once __DIR__ . '/classes/MpsPaymentsOrderCheck.php';

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

class MpsPayments extends PaymentModule
{
    const DEFAULT_GATEWAY_URL = 'https://gateway.mps-emi.com';

    /** Settings, per shop. The secrets are never shown again once saved. */
    const CLIENT_ID = 'MPS_PAYMENTS_CLIENT_ID';
    const CLIENT_SECRET = 'MPS_PAYMENTS_CLIENT_SECRET';
    const WEBHOOK_SECRET = 'MPS_PAYMENTS_WEBHOOK_SECRET';
    const GATEWAY_URL = 'MPS_PAYMENTS_GATEWAY_URL';
    const DEBUG = 'MPS_PAYMENTS_DEBUG';
    const TITLE = 'MPS_PAYMENTS_TITLE';
    const DESCRIPTION = 'MPS_PAYMENTS_DESCRIPTION';

    /** The secret in the scheduled-check URL, generated on install. */
    const CRON_TOKEN = 'MPS_PAYMENTS_CRON_TOKEN';

    public function __construct()
    {
        $this->name = 'mpspayments';
        $this->tab = 'payments_gateways';
        $this->version = '0.1.0';
        $this->author = 'Mobile Payment Solutions';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => '9.99.99'];
        $this->bootstrap = true;
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';

        parent::__construct();

        $this->displayName = $this->trans('MPS Payments', [], 'Modules.Mpspayments.Admin');
        $this->description = $this->trans('Take payments in your PrestaShop shop on the MPS-hosted checkout page.', [], 'Modules.Mpspayments.Admin');
    }

    public function isUsingNewTranslationSystem()
    {
        return true;
    }

    public function install()
    {
        // MpsPaymentsApi talks to MPS with curl.
        if (!extension_loaded('curl')) {
            $this->_errors[] = $this->trans('MPS Payments needs the PHP curl extension. Ask your host to enable it, then install the module again.', [], 'Modules.Mpspayments.Admin');

            return false;
        }

        return parent::install()
            && $this->registerHook(['paymentOptions', 'displayPaymentReturn'])
            && MpsPaymentsRepository::createTable()
            && Configuration::updateGlobalValue(self::GATEWAY_URL, self::DEFAULT_GATEWAY_URL)
            && Configuration::updateGlobalValue(self::CRON_TOKEN, bin2hex(random_bytes(16)));
    }

    /**
     * Removes the settings, cached tokens and the payment attempts. Orders stay: they are the shop's
     * records, not the module's.
     */
    public function uninstall()
    {
        foreach ([self::CLIENT_ID, self::CLIENT_SECRET, self::WEBHOOK_SECRET, self::GATEWAY_URL, self::DEBUG, self::TITLE, self::DESCRIPTION, self::CRON_TOKEN] as $key) {
            Configuration::deleteByName($key);
        }
        Db::getInstance()->delete('configuration', "name LIKE 'MPS\\_PAYMENTS\\_TOKEN\\_%'");

        return MpsPaymentsRepository::dropTable() && parent::uninstall();
    }

    /**
     * MPS at checkout: one option that hands the browser to the module's redirect controller.
     */
    public function hookPaymentOptions($params)
    {
        $cart = $params['cart'];

        if (!$this->active || !$this->isUsable((int) $cart->id_shop) || !$this->isEuro($cart)) {
            return [];
        }

        $option = new PaymentOption();
        $option->setModuleName($this->name)
            ->setCallToActionText($this->title((int) $cart->id_shop))
            ->setAction($this->context->link->getModuleLink($this->name, 'redirect', [], true))
            ->setAdditionalInformation($this->render('hook/payment_option.tpl', ['mps_description' => $this->paymentDescription((int) $cart->id_shop)]));

        return [$option];
    }

    /**
     * The order confirmation page, once MPS said the cart was paid: the order reference and a line saying so.
     */
    public function hookDisplayPaymentReturn($params)
    {
        if (!$this->active || !isset($params['order']) || !Validate::isLoadedObject($params['order'])) {
            return '';
        }

        // A partial approval makes the order in Payment error: confirm receipt only when it counts as paid.
        return $this->render('hook/payment_return.tpl', [
            'mps_order_reference' => $params['order']->reference,
            'mps_paid' => (bool) $params['order']->hasBeenPaid(),
        ]);
    }

    /**
     * @param string $template Under views/templates/, e.g. hook/payment_return.tpl.
     */
    private function render($template, array $variables)
    {
        $this->context->smarty->assign($variables);

        return $this->context->smarty->fetch($this->local_path . 'views/templates/' . $template);
    }

    /**
     * The settings page: save, prove the credentials with a real token request, show the form.
     */
    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitMpsPayments')) {
            Configuration::updateValue(self::CLIENT_ID, trim((string) Tools::getValue(self::CLIENT_ID)));
            // A password field comes back empty when it was not touched: keep what is stored.
            foreach ([self::CLIENT_SECRET, self::WEBHOOK_SECRET] as $secret) {
                $value = trim((string) Tools::getValue($secret));
                if ('' !== $value) {
                    Configuration::updateValue($secret, $value);
                }
            }
            if (Tools::getValue('clear_webhook_secret')) {
                Configuration::updateValue(self::WEBHOOK_SECRET, '');
            }
            if (!$this->gatewayUrlIsFixed()) {
                $gatewayUrl = rtrim(trim((string) Tools::getValue(self::GATEWAY_URL)), '/');
                // Empty means the default gateway (gatewayUrl() falls back to it).
                if ('' === $gatewayUrl || MpsPaymentsGatewayUrl::isValid($gatewayUrl)) {
                    Configuration::updateValue(self::GATEWAY_URL, $gatewayUrl);
                } else {
                    $output .= $this->displayError($this->trans('The gateway URL must be an absolute address starting with https://. The previous one was kept.', [], 'Modules.Mpspayments.Admin'));
                }
            }
            Configuration::updateValue(self::DEBUG, (int) Tools::getValue(self::DEBUG));
            Configuration::updateValue(self::TITLE, trim((string) Tools::getValue(self::TITLE)));
            Configuration::updateValue(self::DESCRIPTION, trim((string) Tools::getValue(self::DESCRIPTION)));

            $output .= $this->verifyCredentials();
        }

        return $output . $this->renderForm();
    }

    /**
     * @return string A confirmation or an error to show above the form, or nothing without credentials.
     */
    private function verifyCredentials()
    {
        if (!$this->isUsable(null)) {
            return '';
        }

        try {
            $this->api(null)->verifyCredentials();
        } catch (Exception $e) {
            return $this->displayError($this->trans('MPS did not accept these credentials: %reason%', ['%reason%' => $e->getMessage()], 'Modules.Mpspayments.Admin'));
        }

        return $this->displayConfirmation(
            $this->isTestKey(null)
                ? $this->trans('Connected to MPS with a test key. It works for 24 hours and accepts only the MPS test cards; no card is charged.', [], 'Modules.Mpspayments.Admin')
                : $this->trans('Connected to MPS.', [], 'Modules.Mpspayments.Admin')
        );
    }

    private function renderForm()
    {
        $cronUrl = $this->context->link->getModuleLink($this->name, 'cron', ['token' => Configuration::getGlobalValue(self::CRON_TOKEN)], true);
        $hasWebhookSecret = '' !== (string) Configuration::get(self::WEBHOOK_SECRET);

        $gatewayField = [
            'type' => 'text',
            'label' => $this->trans('Gateway URL', [], 'Modules.Mpspayments.Admin'),
            'name' => self::GATEWAY_URL,
            'desc' => $this->gatewayUrlIsFixed()
                ? $this->trans('Set by _PS_MPS_PAYMENTS_GATEWAY_URL_ in config/defines_custom.inc.php.', [], 'Modules.Mpspayments.Admin')
                : $this->trans('Leave as is unless MPS asks you to change it.', [], 'Modules.Mpspayments.Admin'),
        ];
        if ($this->gatewayUrlIsFixed()) {
            $gatewayField['readonly'] = true;
        }

        $form = [
            'form' => [
                'legend' => ['title' => $this->displayName, 'icon' => 'icon-credit-card'],
                'description' => $this->trans('Payments on the checkout page MPS hosts. Card details never reach your shop.', [], 'Modules.Mpspayments.Admin')
                    . ' ' . $this->trans('This payment method sends the order amount, order number and your shop\'s return addresses to MPS (Mobile Payment Solutions) to open each payment.', [], 'Modules.Mpspayments.Admin'),
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->trans('Client ID', [], 'Modules.Mpspayments.Admin'),
                        'name' => self::CLIENT_ID,
                        'desc' => $this->trans('Paste the client ID, secret and webhook signing secret from the MPS platform (Online › Get started). A test key, whose client ID starts with test_, works for 24 hours and accepts only the MPS test cards: no card is charged and nothing is paid out.', [], 'Modules.Mpspayments.Admin'),
                    ],
                    [
                        'type' => 'password',
                        'label' => $this->trans('Client secret', [], 'Modules.Mpspayments.Admin'),
                        'name' => self::CLIENT_SECRET,
                        'desc' => '' !== (string) Configuration::get(self::CLIENT_SECRET) ? $this->trans('Saved. Leave empty to keep it.', [], 'Modules.Mpspayments.Admin') : '',
                    ],
                    [
                        'type' => 'password',
                        'label' => $this->trans('Webhook signing secret', [], 'Modules.Mpspayments.Admin'),
                        'name' => self::WEBHOOK_SECRET,
                        'desc' => ($hasWebhookSecret ? $this->trans('Saved. Leave empty to keep it.', [], 'Modules.Mpspayments.Admin') . ' ' : '')
                            . $this->trans('Shown with your key in the MPS platform. Payment notifications are only acted on when their signature matches; without a secret they are ignored and orders are settled by the module\'s own checks.', [], 'Modules.Mpspayments.Admin'),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->trans('Remove the webhook signing secret', [], 'Modules.Mpspayments.Admin'),
                        'name' => 'clear_webhook_secret',
                        'is_bool' => true,
                        'values' => $this->switchValues('clear_webhook_secret'),
                    ],
                    $gatewayField,
                    [
                        'type' => 'switch',
                        'label' => $this->trans('Debug log', [], 'Modules.Mpspayments.Admin'),
                        'name' => self::DEBUG,
                        'is_bool' => true,
                        'desc' => $this->trans('Log every call to MPS in Advanced Parameters › Logs (never secrets or payment links).', [], 'Modules.Mpspayments.Admin'),
                        'values' => $this->switchValues(self::DEBUG),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Title', [], 'Modules.Mpspayments.Admin'),
                        'name' => self::TITLE,
                        'desc' => $this->trans('The name of this payment method at checkout. Empty: "%default%", in the shopper\'s language.', ['%default%' => $this->defaultTitle()], 'Modules.Mpspayments.Admin'),
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->trans('Description', [], 'Modules.Mpspayments.Admin'),
                        'name' => self::DESCRIPTION,
                        'desc' => $this->trans('Shown under the title at checkout. Empty: a standard text in the shopper\'s language.', [], 'Modules.Mpspayments.Admin'),
                    ],
                    [
                        'type' => 'html',
                        'label' => $this->trans('Scheduled checks', [], 'Modules.Mpspayments.Admin'),
                        'name' => 'cron_url',
                        'html_content' => $this->render('admin/cron_url.tpl', ['mps_cron_url' => $cronUrl]),
                    ],
                ],
                'submit' => ['title' => $this->trans('Save', [], 'Admin.Actions')],
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitMpsPayments';
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->fields_value = [
            self::CLIENT_ID => Configuration::get(self::CLIENT_ID),
            self::CLIENT_SECRET => '',
            self::WEBHOOK_SECRET => '',
            'clear_webhook_secret' => 0,
            self::GATEWAY_URL => $this->gatewayUrl(null),
            self::DEBUG => (int) Configuration::get(self::DEBUG),
            self::TITLE => Configuration::get(self::TITLE),
            self::DESCRIPTION => Configuration::get(self::DESCRIPTION),
        ];

        return $helper->generateForm([$form]);
    }

    private function switchValues($name)
    {
        return [
            ['id' => $name . '_on', 'value' => 1, 'label' => $this->trans('Yes', [], 'Admin.Global')],
            ['id' => $name . '_off', 'value' => 0, 'label' => $this->trans('No', [], 'Admin.Global')],
        ];
    }

    /**
     * A client for the gateway with the given shop's credentials.
     *
     * @param int|null $idShop
     *
     * @return MpsPaymentsApi
     */
    public function api($idShop)
    {
        $module = $this;

        return new MpsPaymentsApi(
            $this->gatewayUrl($idShop),
            (string) Configuration::get(self::CLIENT_ID, null, null, $idShop),
            (string) Configuration::get(self::CLIENT_SECRET, null, null, $idShop),
            static function ($message, $severity) use ($module, $idShop) {
                $module->log($message, $severity, $idShop);
            }
        );
    }

    /**
     * Writes to Advanced Parameters › Logs while Debug log is on.
     *
     * @param string   $message
     * @param int      $severity 1 for a call that worked, 2 for a refusal, 3 for no answer.
     * @param int|null $idShop
     */
    public function log($message, $severity, $idShop)
    {
        if (Configuration::get(self::DEBUG, null, null, $idShop)) {
            PrestaShopLogger::addLog('MPS Payments: ' . $message, (int) $severity, null, 'MpsPayments', 0, true);
        }
    }

    /**
     * The gateway: _PS_MPS_PAYMENTS_GATEWAY_URL_ in config/defines_custom.inc.php wins over the setting.
     */
    public function gatewayUrl($idShop)
    {
        $url = $this->gatewayUrlIsFixed() ? _PS_MPS_PAYMENTS_GATEWAY_URL_ : (string) Configuration::get(self::GATEWAY_URL, null, null, $idShop);

        return '' === trim($url) ? self::DEFAULT_GATEWAY_URL : rtrim(trim($url), '/');
    }

    public function webhookSecret($idShop)
    {
        return trim((string) Configuration::get(self::WEBHOOK_SECRET, null, null, $idShop));
    }

    /**
     * Without a client ID and secret there is nothing to pay with; the option is not offered.
     */
    public function isUsable($idShop)
    {
        return '' !== trim((string) Configuration::get(self::CLIENT_ID, null, null, $idShop))
            && '' !== trim((string) Configuration::get(self::CLIENT_SECRET, null, null, $idShop));
    }

    /**
     * A test key is simulated: it accepts only the MPS test cards, charges nothing, and stops working after 24 hours.
     */
    public function isTestKey($idShop)
    {
        return 0 === strpos((string) Configuration::get(self::CLIENT_ID, null, null, $idShop), 'test_');
    }

    /**
     * MPS takes payments in EUR only.
     */
    public function isEuro(Cart $cart)
    {
        $currency = new Currency((int) $cart->id_currency);

        return 'EUR' === $currency->iso_code;
    }

    public function title($idShop)
    {
        $title = trim((string) Configuration::get(self::TITLE, null, null, $idShop));

        return '' === $title ? $this->defaultTitle() : $title;
    }

    private function defaultTitle()
    {
        return $this->trans('Pay with MPS', [], 'Modules.Mpspayments.Shop');
    }

    private function paymentDescription($idShop)
    {
        $description = trim((string) Configuration::get(self::DESCRIPTION, null, null, $idShop));

        return '' === $description
            ? $this->trans('Pay by card or with your banking app on the secure MPS payment page.', [], 'Modules.Mpspayments.Shop')
            : $description;
    }

    private function gatewayUrlIsFixed()
    {
        return defined('_PS_MPS_PAYMENTS_GATEWAY_URL_');
    }
}
