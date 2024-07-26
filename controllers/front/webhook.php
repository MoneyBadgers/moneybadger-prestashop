<?php

class moneybadgerWebhookModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        $PS_ORDER_STATUS_PAID = (int) Configuration::get('PS_OS_PAYMENT');
        $PS_ORDER_STATUS_CANCELLED = (int) Configuration::get('PS_OS_CANCELED');

        $cartId = (int) Tools::getValue('cart_id');
        // check if order exists
        if (empty($cartId)) {
            header('HTTP/1.1 404 Not Found');
            exit;
        }
        $cart = new Cart($cartId);
        // Ensure cart is loaded:
        if (false === Validate::isLoadedObject($cart)) {
            header('HTTP/1.1 404 Not Found');
            exit;
        }

        // Check if order already exists for cart ID, if so, exit
        $order = new Order((int) Order::getOrderByCartId($cartId));
        if (true === Validate::isLoadedObject($order)) {
            echo 'order already exists';
            exit;
        }

        $merchantCode = Configuration::get('MONEYBADGER_MERCHANT_CODE');
        $cryptoReference = $merchantCode . ((string) $cartId);
        $cryptoInvoice = $this->getInvoice($cryptoReference);
        $orderStatus = -1;
        $orderTotal = (float) $cart->getOrderTotal(true, Cart::BOTH);

        // Only create order if invoice status is 'CONFIRMED' or 'CANCELLED' or 'TIMEDOUT' or 'ERROR'
        switch ($cryptoInvoice->status) {
            case MoneyBadger::PAYMENT_STATUS_CANCELLED:
                $orderStatus = $PS_ORDER_STATUS_CANCELLED;
                break;
            case MoneyBadger::PAYMENT_STATUS_TIMEDOUT:
            case MoneyBadger::PAYMENT_STATUS_ERRORED:
                $orderStatus = (int) Configuration::get(MoneyBadger::ORDER_STATE_CAPTURE_TIMEDOUT);
                break;
            case MoneyBadger::PAYMENT_STATUS_CONFIRMED:
                // Double check the order total matches the amount actually paid
                if ((int) ($orderTotal * 100) !== (int) $cryptoInvoice->amount_cents) {
                    echo 'Order total does not match amount paid';
                    throw new PrestaShopException('Order total does not match amount paid');
                }
                $orderStatus = $PS_ORDER_STATUS_PAID;
                break;
            default: // 'REQUESTED' or 'AUTHORIZED' = ignore
                echo 'ignoring webhook for invoice with status ' . $cryptoInvoice->status;
                exit;
        }

        $paymentMethodName = $this->module->displayName;
        $customer = new Customer($cart->id_customer);
        # Create the order
        $this->module->validateOrder(
            $cartId,
            $orderStatus,
            (float) ($cryptoInvoice->amount_cents / 100.0),
            $paymentMethodName,
            null,
            [
                'transaction_id' => $cryptoInvoice->id,
            ],
            (int) $cart->id_currency,
            false,
            $customer->secure_key
        );

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
	private function updateOrderStatus(Order $order, int $orderStatus): void
	{
        // NOTE: We can't use $order->setCurrentState($newOrderStatus) because it will create a new PAYMENT entry, and we pre-create the payment entry when the iframe is loaded.
        // $orderHistory->changeIdOrderState must be called with $use_existing_payment = true. This is not documented. Much frustration.

		// Add the order change to the order history table
        $orderCurrentState = (int) $order->getCurrentState();
        // only update the order status if it is not already the new status
        if ($orderCurrentState === $newOrderStatus) {
            return;
        }
        $orderId = (int) $order->id;
		$orderHistory           = new \OrderHistory();
		$orderHistory->id_order = $orderId;

		// Store the change and make sure to create an invoice using existing payments (in case the status is changed to 'paid with crypto')
		$orderHistory->changeIdOrderState($orderStatus, $orderId, true);
		$orderHistory->add();
	}
}
