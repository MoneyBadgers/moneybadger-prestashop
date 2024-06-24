<?php

class moneybadgerAPIModuleFrontController extends ModuleFrontController
{
    public function displayAjax()
    {
        parent::initContent();
        $order_id = Tools::getValue('order_id');

        if (empty($order_id)) {
            header('HTTP/1.1 404 Not Found');
            exit;
        }

        $order = new Order((int) $order_id);

        if (false === Validate::isLoadedObject($order)) {
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
