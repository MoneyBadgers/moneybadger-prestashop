<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
    exit;
}

class MoneyBadger extends PaymentModule
{
    const CONFIG_PO_EXTERNAL_ENABLED = 'MONEYBADGER_PO_EXTERNAL_ENABLED';

    const CONFIG_PO_IFRAME_ENABLED = 'MONEYBADGER_PO_IFRAME_ENABLED';
    const MODULE_ADMIN_CONTROLLER = 'AdminConfigureMoneyBadger';

    const ORDER_STATE_CAPTURE_WAITING = 'MONEYBADGER_CAPTURE_WAITING';
    const ORDER_STATE_CAPTURE_TIMEDOUT = 'MONEYBADGER_CAPTURE_TIMEDOUT';
    // cancelled and paid statuses are built-in

    const PAYMENT_STATUS_REQUESTED = 'REQUESTED';
    const PAYMENT_STATUS_AUTHORIZED = 'AUTHORIZED';
    const PAYMENT_STATUS_CONFIRMED = 'CONFIRMED';
    const PAYMENT_STATUS_TIMEDOUT = 'TIMED_OUT';
    const PAYMENT_STATUS_CANCELLED = 'CANCELLED';

    const ORDER_STATES = [
        [
            'id' => self::ORDER_STATE_CAPTURE_WAITING,
            'color' => '#34209E',
            'description' => 'Waiting for MoneyBadger capture',
        ],
        [
            'id' => self::ORDER_STATE_CAPTURE_TIMEDOUT,
            'color' => '#E74C3C',
            'description' => 'MoneyBadger capture timed out',
        ],
    ];

    const HOOKS = [
        'actionPaymentCCAdd',
        'actionObjectShopAddAfter',
        'paymentOptions',
        'displayAdminOrderLeft',
        'displayAdminOrderMainBottom',
        'displayCustomerAccount',
        'displayOrderConfirmation',
        'displayOrderDetail',
        'displayPaymentByBinaries',
        'displayPaymentReturn',
        'displayPDFInvoice',
    ];

    public function __construct()
    {
        $this->name = 'moneybadger';
        $this->tab = 'payments_gateways';
        $this->version = '0.9.0';
        $this->author = 'MoneyBadger';
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->ps_versions_compliancy = [
            'min' => '1.7',
            'max' => _PS_VERSION_,
        ];
        $this->controllers = [
            'account',
            'cancel',
            'external',
            'validation',
        ];

        parent::__construct();

        $this->displayName = $this->l('MoneyBadger Crypto Payments');
        $this->description = $this->l('Accept crypto payments from Bitcoin Lightning, Luno, Valr or Binance wallets');
    }

    /**
     * @return bool
     */
    public function install()
    {
        /*
         * Clear both Smarty and Symfony cache.
         */
        Tools::clearAllCache();

        return (bool) parent::install()
            && (bool) $this->registerHook(static::HOOKS)
            && $this->installOrderState()
            && $this->installConfiguration()
            && $this->installTabs();
    }

    /**
     * @return bool
     */
    public function uninstall()
    {
        return (bool) parent::uninstall()
            && $this->deleteOrderState()
            && $this->uninstallConfiguration()
            && $this->uninstallTabs();
    }

    /**
     * Module configuration page
     */
    public function getContent()
    {
        // Redirect to our ModuleAdminController when click on Configure button
        Tools::redirectAdmin($this->context->link->getAdminLink(static::MODULE_ADMIN_CONTROLLER));
    }

    /**
     * This hook is used to save additional information will be displayed on BO Order View, Payment block with "Details" button
     *
     * @param array $params
     */
    public function hookActionPaymentCCAdd(array $params)
    {
        if (empty($params['paymentCC'])) {
            return;
        }

        /** @var OrderPayment $orderPayment */
        $orderPayment = $params['paymentCC'];

        if (false === Validate::isLoadedObject($orderPayment) || empty($orderPayment->order_reference)) {
            return;
        }

        /** @var Order[] $orderCollection */
        $orderCollection = Order::getByReference($orderPayment->order_reference);

        foreach ($orderCollection as $order) {
            if ($this->name !== $order->module) {
                return;
            }
        }

        $cardNumber = Tools::getValue('cardNumber');
        $cardBrand = Tools::getValue('cardBrand');
        $cardHolder = Tools::getValue('cardHolder');
        $cardExpiration = Tools::getValue('cardExpiration');

        if (false === empty($cardNumber) && Validate::isGenericName($cardNumber)) {
            $orderPayment->card_number = $cardNumber;
        }

        if (false === empty($cardBrand) && Validate::isGenericName($cardBrand)) {
            $orderPayment->card_brand = $cardBrand;
        }

        if (false === empty($cardHolder) && Validate::isGenericName($cardHolder)) {
            $orderPayment->card_holder = $cardHolder;
        }

        if (false === empty($cardExpiration) && Validate::isGenericName($cardExpiration)) {
            $orderPayment->card_expiration = $cardExpiration;
        }

        $orderPayment->save();
    }

    /**
     * This hook called after a new Shop is created
     *
     * @param array $params
     */
    public function hookActionObjectShopAddAfter(array $params)
    {
        if (empty($params['object'])) {
            return;
        }

        /** @var Shop $shop */
        $shop = $params['object'];

        if (false === Validate::isLoadedObject($shop)) {
            return;
        }

        $this->addCheckboxCarrierRestrictionsForModule([(int) $shop->id]);
        $this->addCheckboxCountryRestrictionsForModule([(int) $shop->id]);

        if ($this->currencies_mode === 'checkbox') {
            $this->addCheckboxCurrencyRestrictionsForModule([(int) $shop->id]);
        } elseif ($this->currencies_mode === 'radio') {
            $this->addRadioCurrencyRestrictionsForModule([(int) $shop->id]);
        }
    }

    /**
     * @param array $params
     *
     * @return array Should always return an array
     */
    public function hookPaymentOptions(array $params)
    {
        /** @var Cart $cart */
        $cart = $params['cart'];

        if (false === Validate::isLoadedObject($cart) || false === $this->checkCurrency($cart)) {
            return [];
        }

        $paymentOptions = [
            $this->getIframePaymentOption(),
        ];

        return $paymentOptions;
    }

    /**
     * This hook is used to display additional information on BO Order View, under Payment block
     *
     * @since PrestaShop 1.7.7 This hook is replaced by displayAdminOrderMainBottom on migrated BO Order View
     *
     * @param array $params
     *
     * @return string
     */
    public function hookDisplayAdminOrderLeft(array $params)
    {
        if (empty($params['id_order'])) {
            return '';
        }

        $order = new Order((int) $params['id_order']);

        if (false === Validate::isLoadedObject($order) || $order->module !== $this->name) {
            return '';
        }

        $this->context->smarty->assign([
            'moduleName' => $this->name,
            'moduleDisplayName' => $this->displayName,
            'moduleLogoSrc' => $this->getPathUri() . 'moneybadger-config-icon.png',
        ]);

        return $this->context->smarty->fetch('module:moneybadger/views/templates/hook/displayAdminOrderLeft.tpl');
    }

    /**
     * This hook is used to display additional information on BO Order View, under Payment block
     *
     * @since PrestaShop 1.7.7 This hook replace displayAdminOrderLeft on migrated BO Order View
     *
     * @param array $params
     *
     * @return string
     */
    public function hookDisplayAdminOrderMainBottom(array $params)
    {
        if (empty($params['id_order'])) {
            return '';
        }

        $order = new Order((int) $params['id_order']);

        if (false === Validate::isLoadedObject($order) || $order->module !== $this->name) {
            return '';
        }

        $this->context->smarty->assign([
            'moduleName' => $this->name,
            'moduleDisplayName' => $this->displayName,
            'moduleLogoSrc' => $this->getPathUri() . 'moneybadger-config-icon.png',
        ]);

        return $this->context->smarty->fetch('module:moneybadger/views/templates/hook/displayAdminOrderMainBottom.tpl');
    }

    /**
     * This hook is used to display information in customer account
     *
     * @param array $params
     *
     * @return string
     */
    public function hookDisplayCustomerAccount(array $params)
    {
        $this->context->smarty->assign([
            'moduleDisplayName' => $this->displayName,
            'moduleLogoSrc' => $this->getPathUri() . 'moneybadger-config-icon.png',
            'transactionsLink' => $this->context->link->getModuleLink(
                $this->name,
                'account'
            ),
        ]);

        return $this->context->smarty->fetch('module:moneybadger/views/templates/hook/displayCustomerAccount.tpl');
    }

    /**
     * This hook is used to display additional information on order confirmation page
     *
     * @param array $params
     *
     * @return string
     */
    public function hookDisplayOrderConfirmation(array $params)
    {
        if (empty($params['order'])) {
            return '';
        }

        /** @var Order $order */
        $order = $params['order'];

        if (false === Validate::isLoadedObject($order) || $order->module !== $this->name) {
            return '';
        }

        $transaction = '';

        if ($order->getOrderPaymentCollection()->count()) {
            /** @var OrderPayment $orderPayment */
            $orderPayment = $order->getOrderPaymentCollection()->getFirst();
            $transaction = $orderPayment->transaction_id;
        }

        $this->context->smarty->assign([
            'moduleName' => $this->name,
            'transaction' => $transaction,
        ]);

        return $this->context->smarty->fetch('module:moneybadger/views/templates/hook/displayOrderConfirmation.tpl');
    }

    /**
     * This hook is used to display additional information on FO (Guest Tracking and Account Orders)
     *
     * @param array $params
     *
     * @return string
     */
    public function hookDisplayOrderDetail(array $params)
    {
        if (empty($params['order'])) {
            return '';
        }

        /** @var Order $order */
        $order = $params['order'];

        if (false === Validate::isLoadedObject($order) || $order->module !== $this->name) {
            return '';
        }

        $transaction = '';

        if ($order->getOrderPaymentCollection()->count()) {
            /** @var OrderPayment $orderPayment */
            $orderPayment = $order->getOrderPaymentCollection()->getFirst();
            $transaction = $orderPayment->transaction_id;
        }

        $this->context->smarty->assign([
            'moduleName' => $this->name,
            'transaction' => $transaction,
        ]);

        return $this->context->smarty->fetch('module:moneybadger/views/templates/hook/displayOrderDetail.tpl');
    }

    /**
     * This hook is used to display additional information on bottom of order confirmation page
     *
     * @param array $params
     *
     * @return string
     */
    public function hookDisplayPaymentReturn(array $params)
    {
        if (empty($params['order'])) {
            return '';
        }

        /** @var Order $order */
        $order = $params['order'];

        if (false === Validate::isLoadedObject($order) || $order->module !== $this->name) {
            return '';
        }

        $transaction = '';

        if ($order->getOrderPaymentCollection()->count()) {
            /** @var OrderPayment $orderPayment */
            $orderPayment = $order->getOrderPaymentCollection()->getFirst();
            $transaction = $orderPayment->transaction_id;
        }

        $this->context->smarty->assign([
            'moduleName' => $this->name,
            'transaction' => $transaction,
            'transactionsLink' => $this->context->link->getModuleLink(
                $this->name,
                'account'
            ),
        ]);

        return $this->context->smarty->fetch('module:moneybadger/views/templates/hook/displayPaymentReturn.tpl');
    }

    /**
     * This hook is used to display additional information on Invoice PDF
     *
     * @param array $params
     *
     * @return string
     */
    public function hookDisplayPDFInvoice(array $params)
    {
        if (empty($params['object'])) {
            return '';
        }

        /** @var OrderInvoice $orderInvoice */
        $orderInvoice = $params['object'];

        if (false === Validate::isLoadedObject($orderInvoice)) {
            return '';
        }

        $order = $orderInvoice->getOrder();

        if (false === Validate::isLoadedObject($order) || $order->module !== $this->name) {
            return '';
        }

        $transaction = '';

        if ($order->getOrderPaymentCollection()->count()) {
            /** @var OrderPayment $orderPayment */
            $orderPayment = $order->getOrderPaymentCollection()->getFirst();
            $transaction = $orderPayment->transaction_id;
        }

        $this->context->smarty->assign([
            'moduleName' => $this->name,
            'transaction' => $transaction,
        ]);

        return $this->context->smarty->fetch('module:moneybadger/views/templates/hook/displayPDFInvoice.tpl');
    }

    /**
     * Check if currency is allowed in Payment Preferences
     *
     * @param Cart $cart
     *
     * @return bool
     */
    private function checkCurrency(Cart $cart)
    {
        $currency_order = new Currency($cart->id_currency);
        /** @var array $currencies_module */
        $currencies_module = $this->getCurrency($cart->id_currency);

        if (empty($currencies_module)) {
            return false;
        }

        foreach ($currencies_module as $currency_module) {
            if ($currency_order->id == $currency_module['id_currency']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Factory of PaymentOption for Iframe Payment
     *
     * @return PaymentOption
     */
    public function getIframePaymentOption()
    {
        $iframeOption = new PaymentOption();
        $description = 'Pay with Crypto - Bitcoin Lightning, Luno, Valr or Binance';
        if (!empty(Configuration::get('MONEYBADGER_LABEL'))) {
            $description = Configuration::get('MONEYBADGER_LABEL');
        }
        $iframeOption->setCallToActionText($this->l($description))
            ->setAction($this->context->link->getModuleLink($this->name, 'iframe', [], true))
            ->setLogo(Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/views/img/option/moneybadger-icon.png'));

        return $iframeOption;
    }

    /**
     * @return bool
     */
    private function installOrderState()
    {
        foreach (static::ORDER_STATES as $orderState) {
            $result = $this->createOrderState(
                $orderState['id'],
                $orderState['color'],
                $orderState['description']
            );

            if (false === $result) {
                return false;
            }
        }

        return true;
    }

    /**
     * Create a custom OrderState used for payment
     *
     * @param string $id Id of the OrderState (as a string)
     * @param string $color Color of the OrderState
     * @param string $description Description of the OrderState
     *
     * @return bool
     */
    public function createOrderState($id, $color, $description)
    {
        if (
            !Configuration::get($id)
            || !Validate::isLoadedObject(new OrderState($id))
        ) {

            $order_state = new OrderState();

            $tabNameByLangId = []; // Define $tabNameByLangId before using it

            $nameByLangIsoCode = ['en' => $description];
            foreach ($nameByLangIsoCode as $langIsoCode => $name) {
                foreach (Language::getLanguages(false) as $language) {
                    if (Tools::strtolower($language['iso_code']) === $langIsoCode) {
                        $tabNameByLangId[(int) $language['id_lang']] = $name;
                    } elseif (isset($nameByLangIsoCode['en'])) {
                        $tabNameByLangId[(int) $language['id_lang']] = $nameByLangIsoCode['en'];
                    }
                }
            }


            $order_state->name = $tabNameByLangId;
            $order_state->invoice = false;
            $order_state->send_email = false;
            $order_state->logable = true;
            $order_state->color = $color;
            $order_state->module_name = $this->name;
            $order_state->add();

            $result = (bool) $order_state->add();

            if (false === $result) {
                $this->_errors[] = sprintf(
                    'Failed to create OrderState %s',
                    $id
                );

                return false;
            }

            $result = (bool) Configuration::updateGlobalValue((string) $id, (int) $order_state->id);

            if (false === $result) {
                $this->_errors[] = sprintf(
                    'Failed to save OrderState %s to Configuration',
                    $id
                );

                return false;
            }
        }

        return true;
    }

    /**
     * Delete custom OrderState used for payment
     * We mark them as deleted to not break passed Orders
     *
     * @return bool
     */
    private function deleteOrderState()
    {
        $result = true;

        $orderStateCollection = new PrestaShopCollection('OrderState');
        $orderStateCollection->where('module_name', '=', $this->name);
        /** @var OrderState[] $orderStates */
        $orderStates = $orderStateCollection->getAll();

        foreach ($orderStates as $orderState) {
            $orderState->deleted = true;
            $result = $result && (bool) $orderState->save();
        }

        return $result;
    }

    /**
     * Install default module configuration
     *
     * @return bool
     */
    private function installConfiguration()
    {
        return true;
    }

    /**
     * Uninstall module configuration
     *
     * @return bool
     */
    private function uninstallConfiguration()
    {
        return true;
    }

    /**
     * Install Tabs
     *
     * @return bool
     */
    public function installTabs()
    {
        if (Tab::getIdFromClassName(static::MODULE_ADMIN_CONTROLLER)) {
            return true;
        }

        $tab = new Tab();
        $tab->class_name = static::MODULE_ADMIN_CONTROLLER;
        $tab->module = $this->name;
        $tab->active = true;
        $tab->id_parent = -1;
        $tab->name = array_fill_keys(
            Language::getIDs(false),
            $this->displayName
        );

        return (bool) $tab->add();
    }

    /**
     * Uninstall Tabs
     *
     * @return bool
     */
    public function uninstallTabs()
    {
        $id_tab = (int) Tab::getIdFromClassName(static::MODULE_ADMIN_CONTROLLER);

        if ($id_tab) {
            $tab = new Tab($id_tab);

            return (bool) $tab->delete();
        }

        return true;
    }
}
