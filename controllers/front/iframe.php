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
        # Add random string so multiple payment requests may be created for the same cart
        $cartReference = $cryptoReference = $cartId . '-' . Tools::passwdGen(6);

        $orderValidationURL = $this->context->link->getModuleLink(
            $this->module->name,
            'validation',
            [
                'cart_ref' => $cartReference,
            ],
            $this->ssl
        );

        $statusWebhookUrl = urldecode(
            $this->context->link->getModuleLink(
                $this->module->name,
                'webhook',
                [
                    'cart_ref' => $cartReference,
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
                    'cart_ref' => $cartReference,
                ],
                $this->ssl
            )
        );

        $shopName = Configuration::get('PS_SHOP_NAME');
        $orderTotal = (float) $this->context->cart->getOrderTotal(true, Cart::BOTH);
        $amountInCents = (int) ($orderTotal * 100);

        if ((int) $amountInCents != (int) ($orderTotal * 100)) { // Make sure we don't lose precision
            throw new PrestaShopException('Failed to convert order total to cents');
        }

        // load the payment form
        $this->context->smarty->assign([
            'src' => 'https://pay' . (Configuration::get('MONEYBADGER_TEST_MODE', false) ? '.staging' : '') . '.cryptoqr.net/?' . http_build_query(
                [
                    'amountCents' => $amountInCents,
                    'orderId' => $cartReference,
                    'userId' => $customer->id,
                    'merchantCode' => $merchantCode,
                    'statusWebhookUrl' => $statusWebhookUrl,
                    'orderDescription' => $shopName . ' - Cart #' . $cartId,
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
