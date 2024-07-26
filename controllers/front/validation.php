<?php
/**
 * This Controller receive customer after approval on bank payment page
 */
class MoneyBadgerValidationModuleFrontController extends ModuleFrontController
{
    /**
     * @var MoneyBadger
     */
    public $module;

    /**
     * {@inheritdoc}
     */
    public function postProcess()
    {
        $cart = $this->context->cart;
        // Note: $cart->id is null here for some reason ???
        if (0 === $cart->id_customer || 0 === $cart->id_address_delivery || 0 === $cart->id_address_invoice || false === $this->checkIfPaymentOptionIsAvailable()) {
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
            return;
        }

        $cartId = (int) Tools::getValue('cart_id'); // From URL parameter
        $order = new Order((int) Order::getOrderByCartId($cartId));
        // check if order exists
        if (false === Validate::isLoadedObject($order)) {
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
            return;
        }

        // make sure order and cart customer ids match
        if ((int) $order->id_customer !== (int) $cart->id_customer) {
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
            return;
        }

        // Make sure customer is loaded and same as for order
        $customer = new Customer($cart->id_customer);

        // NOTE! $order->id_customer is a string for some reason ???
        if (false === Validate::isLoadedObject($customer) || (string) $customer->id !== $order->id_customer) {
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
            return;
        }

        // If it's a guest, send them to guest tracking
        if (Cart::isGuestCartByCartId($order->id_cart)) {
			Tools::redirect($this->context->link->getPageLink('guest-tracking', $this->ssl, null, ['order_reference' => $order->reference, 'email' => $customer->email]));

			return;
		}

        Tools::redirect(
            $this->context->link->getPageLink(
                'order-confirmation',
                $this->ssl,
                (int) $this->context->language->id,
                [
                    'id_cart' => (int) $order->id_cart, // NOTE! can't use $cart->id, it is null ???
                    'id_module' => (int) $this->module->id,
                    'id_order' => (int) $order->id,
                    'key' => $customer->secure_key,
                ]
            )
        );
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
