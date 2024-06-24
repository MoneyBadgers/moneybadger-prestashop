<?php

class moneybadgerIframeModuleFrontController extends ModuleFrontController
{
    /**
     * @var PaymentModule
     */
    public $module;

    /**
     * {@inheritdoc}
     */
    public function postProcess()
    {
        $this->context->controller->registerJavascript(
            'moneybadger-payments',
            'modules/moneybadger/views/js/build/checkout.js'
        );

        if (false === $this->checkIfContextIsValid() || false === $this->checkIfPaymentOptionIsAvailable()) {
            Tools::redirect(
                $this->context->link->getPageLink(
                    'order',
                    true,
                    (int) $this->context->language->id,
                    [
                        'step' => 1,
                    ]
                )
            );
        }

        $customer = new Customer($this->context->cart->id_customer);

        if (false === Validate::isLoadedObject($customer)) {
            Tools::redirect(
                $this->context->link->getPageLink(
                    'order',
                    true,
                    (int) $this->context->language->id,
                    [
                        'step' => 1,
                    ]
                )
            );
        }

        $orderTotal = (float) $this->context->cart->getOrderTotal(true, Cart::BOTH);
        $amountInCents = $orderTotal * 100;

        $merchantAPIKey = Configuration::get('MONEYBADGER_MERCHANT_API_KEY', '');
        $merchantCode = Configuration::get('MONEYBADGER_MERCHANT_CODE');

        $orderState = $this->getOrderState();

        $id_cart = $this->context->cart->id;

        $this->module->validateOrder(
            (int) $id_cart,
            (int) $orderState,
            $orderTotal,
            $this->getOptionName(),
            null,
            [],
            (int) $this->context->currency->id,
            false,
            $customer->secure_key
        );

        $orderConfirmationURL = $this->context->link->getPageLink(
            'order-confirmation',
            true,
            (int) $this->context->language->id,
            [
                'id_cart' => (int) $id_cart,
                'id_module' => (int) $this->module->id,
                'id_order' => (int) $this->module->currentOrder,
                'key' => $customer->secure_key,
            ]
        );

        $orderId = $this->module->currentOrder;

        $statusWebhookUrl = urldecode(
            $this->context->link->getModuleLink(
                $this->module->name,
                'webhook',
                [
                    'ajax' => true,
                    'order_id' => $orderId,
                ],
                true
            )
        );

        $orderStatusAJAXUrl = urldecode(
            $this->context->link->getModuleLink(
                $this->module->name,
                'api',
                [
                    'ajax' => true,
                    'order_id' => $orderId,
                ],
                true
            )
        );

        // add payment to the order
        $order = new Order((int) $orderId);

        if (false === Validate::isLoadedObject($order)) {
            throw new PrestaShopException('Failed to load Order for Payment');
        }

        // load the payment form
        $this->context->smarty->assign([
            'src' => 'https://pay' . (Configuration::get('MONEYBADGER_TEST_MODE', false) ? '.staging' : '') . '.cryptoqr.net/?' . http_build_query(
                [
                    'amountCents' => $amountInCents,
                    'orderId' => $orderId,
                    'merchantCode' => $merchantCode,
                    'statusWebhookUrl' => $statusWebhookUrl,
                    'orderDescription' => $merchantCode . ' - Order #' . $orderId,
                    'autoConfirm' => 'true',
                ]
            ),
            'invoiceId' => $orderId,
            'orderStatusURL' => $orderStatusAJAXUrl,
            'orderConfirmationURL' => $orderConfirmationURL,
        ]);

        $this->setTemplate('module:moneybadger/views/templates/front/iframe.tpl');
    }

    /**
     * Check if the context is valid
     *
     * @return bool
     */
    private function checkIfContextIsValid()
    {
        return true === Validate::isLoadedObject($this->context->cart)
            && true === Validate::isUnsignedInt($this->context->cart->id_customer)
            && true === Validate::isUnsignedInt($this->context->cart->id_address_delivery)
            && true === Validate::isUnsignedInt($this->context->cart->id_address_invoice);
    }

    /**
     * Check that this payment option is still available in case the customer changed
     * his address just before the end of the checkout process
     *
     * @return bool
     */
    private function checkIfPaymentOptionIsAvailable()
    {
        $modules = Module::getPaymentModules();

        if (empty($modules)) {
            return false;
        }

        foreach ($modules as $module) {
            if (isset($module['name']) && $this->module->name === $module['name']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get OrderState identifier
     *
     * @return int
     */
    private function getOrderState()
    {
        return (int) Configuration::get(MoneyBadger::ORDER_STATE_CAPTURE_WAITING);
    }

    /**
     * Get translated Payment Option name
     *
     * @return string
     */
    private function getOptionName()
    {
        $option = Tools::getValue('option');
        $name = $this->module->displayName;

        switch ($option) {
            case 'offline':
                $name = $this->l('Offline');
                break;
            case 'external':
                $name = $this->l('External');
                break;
            case 'iframe':
                $name = $this->l('Iframe');
                break;
            case 'embedded':
                $name = $this->l('Embedded');
                break;
            case 'binary':
                $name = $this->l('Binary');
                break;
        }

        return $name;
    }
}
