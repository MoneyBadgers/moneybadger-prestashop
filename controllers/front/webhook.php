<?php

class moneybadgerWebhookModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        $PS_ORDER_STATUS_PAID = (int) Configuration::get('PS_OS_PAYMENT');
        $PS_ORDER_STATUS_OUTOFSTOCK_PAID = (int) Configuration::get('PS_OS_OUTOFSTOCK_PAID');
        $PS_ORDER_STATUS_CANCELLED = (int) Configuration::get('PS_OS_CANCELED');

        $orderId = (int) Tools::getValue('order_id');
        // check if order exists
        if (empty($orderId)) {
            header('HTTP/1.1 404 Not Found');
            exit;
        }
        $order = new Order($orderId);
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

        $cryptoInvoice = $this->getInvoice($order->reference);

        // add transaction id to order payment
        $orderPaymentCollection = $order->getOrderPaymentCollection();
        if ($orderPaymentCollection && $orderPaymentCollection->count()) {
            /** @var OrderPayment $orderPayment */
            $orderPayment = $this->getLast($orderPaymentCollection);
            $orderPayment->transaction_id = $cryptoInvoice->id;
            $orderPayment->update();
        }

        switch ($cryptoInvoice->status) {
            case MoneyBadger::PAYMENT_STATUS_CANCELLED:
                $this->updateOrderStatus($order, $PS_ORDER_STATUS_CANCELLED);
                break;
            case MoneyBadger::PAYMENT_STATUS_TIMEDOUT:
                $this->updateOrderStatus($order, (int) Configuration::get(MoneyBadger::ORDER_STATE_CAPTURE_TIMEDOUT));
                break;
            case MoneyBadger::PAYMENT_STATUS_AUTHORIZED:
            case MoneyBadger::PAYMENT_STATUS_CONFIRMED:
                // We expect CONFIRMED, but AUTHORIZED is also valid since we will request auto confirmation
                // NB: Ensure the order total matches the amount actually paid
                if ((int) ($order->total_paid * 100) !== (int) $cryptoInvoice->amount_cents) {
                    throw new PrestaShopException('Order total does not match amount paid');
                }
                // mark the order as paid
                if ($orderCurrentState !== $PS_ORDER_STATUS_OUTOFSTOCK_PAID) {
                    $this->updateOrderStatus($order, $PS_ORDER_STATUS_PAID);
                }
                break;
            default:
                break;
        }
        echo 'OK';
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
	 * @param Order $order
	 * @throws \PrestaShopException
	 */
	private function updateOrderStatus(Order $order, string $orderStatus): void
	{
        // NOTE: We can't use $order->setCurrentState($newOrderStatus) because it will create a new PAYMENT entry, and we pre-create the payment entry when the iframe is loaded.
        // $orderHistory->changeIdOrderState must be called with $use_existing_payment = true. This is not documented. Much frustration.

		// Add the order change to the order history table
        $orderId = (int) $order->id;
		$orderHistory           = new \OrderHistory();
		$orderHistory->id_order = $orderId;

		// Store the change and make sure to create an invoice using existing payments (in case the status is changed to 'paid with crypto')
		$orderHistory->changeIdOrderState($orderStatus, $orderId, true);
		$orderHistory->add();
	}
}
