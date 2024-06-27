<?php

class moneybadgerWebhookModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        $order_id = (int) Tools::getValue('order_id');

        $PS_ORDER_STATUS_PAID = (int) Configuration::get('PS_OS_PAYMENT');
        $PS_ORDER_STATUS_OUTOFSTOCK_PAID = (int) Configuration::get('PS_OS_OUTOFSTOCK_PAID');
        $PS_ORDER_STATUS_CANCELLED = (int) Configuration::get('PS_OS_CANCELED');

        // check if order exists
        if (empty($order_id)) {
            header('HTTP/1.1 404 Not Found');
            exit;
        }

        $order = new Order($order_id);

        if (!$order->id) {
            // exit with http status 404 if order not found
            header('HTTP/1.1 404 Not Found');
            exit;
        }

        $orderCurrentState = (int) $order->getCurrentState();

        // check if order is unpaid or already marked paid
        if (
            $orderCurrentState === $PS_ORDER_STATUS_PAID ||
            $orderCurrentState === $PS_ORDER_STATUS_OUTOFSTOCK_PAID
        ) {
            exit;
        }

        $invoice = $this->getInvoice($order_id);

        // add transaction id to order payment
        $orderPaymentCollection = $order->getOrderPaymentCollection();
        if ($orderPaymentCollection && $orderPaymentCollection->count()) {
            /** @var OrderPayment $orderPayment */
            // $orderPayment = $orderPaymentCollection->getLast();
            $orderPayment = $this->getLast($orderPaymentCollection);
            $orderPayment->transaction_id = $invoice->id;
            $orderPayment->update();
        }

        switch ($invoice->status) {
            case MoneyBadger::PAYMENT_STATUS_CANCELLED:
                $this->updateOrderStatus($order, $PS_ORDER_STATUS_CANCELLED);
                break;
            case MoneyBadger::PAYMENT_STATUS_TIMEDOUT:
                $this->updateOrderStatus($order, (int) Configuration::get(MoneyBadger::ORDER_STATE_CAPTURE_TIMEDOUT));
                break;
            case MoneyBadger::PAYMENT_STATUS_AUTHORIZED:
            case MoneyBadger::PAYMENT_STATUS_CONFIRMED:
                // We expect CONFIRMED, but AUTHORIZED is also valid since we will request auto confirmation
                // mark the order as paid
                if ($orderCurrentState !== $PS_ORDER_STATUS_OUTOFSTOCK_PAID) {
                    $this->updateOrderStatus($order, $PS_ORDER_STATUS_PAID);
                }
                break;
            default:
                break;
        }
    }

    // Implement getLast because it is not available in PrestaShop 1.7
    public function getLast($collection)
    {
        $collection->getAll();
        if (!count($this)) {
            return false;
        }

        return $collection[count($collection) - 1];
    }

    /**
     * Get the invoice from MoneyBadger
     *
     * @param int $invoiceId
     *
     * @return mixed
     *
     * @throws Exception if the cURL request fails or returns a non-200 status code
     */
    private function getInvoice($invoiceId)
    {
        $merchantAPIKey = Configuration::get('MONEYBADGER_MERCHANT_API_KEY', '');

        $apiBase = 'https://api' . (Configuration::get('MONEYBADGER_TEST_MODE', false) ? '.staging' : '') . '.cryptoqr.net/api/v2';
        $url = $apiBase . '/invoices/' . $invoiceId;

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-API-Key: ' . $merchantAPIKey,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \Exception("cURL request failed: $error");
        }

        if ($httpCode !== 200) {
            curl_close($ch);
            throw new \Exception("Invoice request failed with status: $httpCode");
        }

        curl_close($ch);

        $invoice = json_decode($response);

        return $invoice;
    }

    /**
     * Update the order status
     *
     * @param Order $order
     * @param int $newOrderStatus
     */
    private function updateOrderStatus($order, $newOrderStatus)
    {
        $orderCurrentState = (int) $order->getCurrentState();
        // only update the order status if it is not already the new status
        if ($orderCurrentState !== $newOrderStatus) {
            $order->setCurrentState($newOrderStatus);
        }
    }
}
