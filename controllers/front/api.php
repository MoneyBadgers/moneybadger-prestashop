<?php

class moneybadgerAPIModuleFrontController extends ModuleFrontController
{
    public function displayAjax()
    {
        parent::initContent();
        $cartReference = Tools::getValue('cart_ref');
        if (empty($cartReference)) {
            header('HTTP/1.1 404 Not Found');
            exit;
        }
        // cart reference is in the format of cartId-randomString
        $cartId = (int) explode('-', $cartReference)[0];
        if (empty($cartId)) {
            header('HTTP/1.1 404 Not Found');
            exit;
        }

        // Load order from cart ID:
        $order = new Order((int) Order::getOrderByCartId($cartId));
        if (false === Validate::isLoadedObject($order)) {
            // This is expected, the order will only exist later.

            // exit with http status 404
            header('HTTP/1.1 404 Not Found');
            exit;
        }

        if ($order->module !== 'moneybadger') {
            // exit with http status 404
            header('HTTP/1.1 404 Not Found');
            exit;
        }

        header('Content-Type: application/json');
        $is_paid = (int) $order->current_state === (int) Configuration::get('PS_OS_PAYMENT') ||
                    (int) $order->current_state === (int) Configuration::get('PS_OS_OUTOFSTOCK_PAID');

        echo json_encode(['is_paid' => $is_paid]);
    }
}
