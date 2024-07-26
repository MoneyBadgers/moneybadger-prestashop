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
            'modules/moneybadger/views/js/checkout.js'
        );

        if (false === $this->checkIfContextIsValid() || false === $this->checkIfPaymentOptionIsAvailable()) {
            Tools::redirect(
                $this->context->link->getPageLink(
                    'order',
                    $this->ssl,
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
                    $this->ssl,
                    (int) $this->context->language->id,
                    [
                        'step' => 1,
                    ]
                )
            );
        }

        $cartId = (int) $this->context->cart->id;
        $orderTotal = (float) $this->context->cart->getOrderTotal(true, Cart::BOTH);
        $paymentMethodName = $this->module->displayName;

        // Note: Pre-create an order in the DB. Most plugins only create the order after the payment is confirmed.
        $this->module->validateOrder(
            $cartId,
            (int) Configuration::get(MoneyBadger::ORDER_STATE_CAPTURE_WAITING),
            $orderTotal, // NB: This value must be compared to the amount actually paid once payment is confirmed.
            $paymentMethodName,
            null,
            [],
            (int) $this->context->currency->id,
            false,
            $customer->secure_key
        );
        // Retrieve newly created order
        $orderId = (int) $this->module->currentOrder;
        $order = new Order($orderId);
        if (false === Validate::isLoadedObject($order)) {
            throw new PrestaShopException('Failed to load Order for Payment');
        }

        $orderValidationURL = $this->context->link->getModuleLink(
            $this->module->name,
            'validation',
            [
                'order_id' => $orderId,
            ],
            $this->ssl
        );

        $statusWebhookUrl = urldecode(
            $this->context->link->getModuleLink(
                $this->module->name,
                'webhook',
                [
                    'order_id' => $orderId,
                    'ajax' => true, // Hack to avoid Smarty template error, without ajax Presta will try to render a template which doesn't exist for the webhook
                ],
                $this->ssl
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
                $this->ssl
            )
        );

        $merchantCode = Configuration::get('MONEYBADGER_MERCHANT_CODE');

        $shopName = Configuration::get('PS_SHOP_NAME');
        $amountInCents = (int) ($orderTotal * 100);

        if ((int) $amountInCents != (int) ($orderTotal * 100)) { // Make sure we don't lose precision
            throw new PrestaShopException('Failed to convert order total to cents');
        }
        $orderReference = $order->reference;

        // load the payment form
        $this->context->smarty->assign([
            'src' => 'https://pay' . (Configuration::get('MONEYBADGER_TEST_MODE', false) ? '.staging' : '') . '.cryptoqr.net/?' . http_build_query(
                [
                    'amountCents' => $amountInCents,
                    'orderId' => $orderReference,
                    'userId' => $customer->id,
                    'merchantCode' => $merchantCode,
                    'statusWebhookUrl' => $statusWebhookUrl,
                    'orderDescription' => $shopName . ' - Order #' . $orderReference,
                    'autoConfirm' => 'true',
                ]
            ),
            'invoiceId' => $orderId,
            'orderStatusURL' => $orderStatusAJAXUrl,
            'orderValidationURL' => $orderValidationURL,
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
}
