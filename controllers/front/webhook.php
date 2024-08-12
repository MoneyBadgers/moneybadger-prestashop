<?php

class moneybadgerWebhookModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        $PS_ORDER_STATUS_PAID = (int) Configuration::get('PS_OS_PAYMENT');
        $PS_ORDER_STATUS_CANCELLED = (int) Configuration::get('PS_OS_CANCELED');

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

        $cryptoInvoice = $this->getInvoice($cartReference);

        // Only create the order if invoice status is "authorized"
        if ($cryptoInvoice->status !== MoneyBadger::PAYMENT_STATUS_AUTHORIZED) {
            echo 'ignoring webhook for invoice with status ' . $cryptoInvoice->status;
            exit;
        }

        // If the amount is incorrect, cancel the crypto payment
        $orderTotal = (float) $cart->getOrderTotal(true, Cart::BOTH);
        if ((int) ($orderTotal * 100) !== (int) $cryptoInvoice->amount_cents) {
            $this->cancelInvoice($cartReference);
            echo 'amount mismatch, cancelling invoice';
            exit;
        }

        // Otherwise it is paid and the correct amount is paid - confirm with MB
        $this->confirmInvoice($cartReference);
        // Then create the order
        $orderStatus = $PS_ORDER_STATUS_PAID;
        $paymentMethodName = $this->module->displayName;
        $customer = new Customer($cart->id_customer);

        // Check whether order already exists to avoid race condition due to multiple webhooks:
        $orderCheck = new Order((int) Order::getOrderByCartId($cartId));
        if (true === Validate::isLoadedObject($orderCheck)) {
            echo 'order already exists';
            exit;
        }

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

    private function moneyBadgerAPIBase()
    {
        return 'https://api' . (Configuration::get('MONEYBADGER_TEST_MODE', false) ? '.staging' : '') . '.cryptoqr.net/api/v2';
    }

    /**
     * Confirm the invoice from MoneyBadger
     *
     * @param string $invoiceId
     *
     * @return mixed
     *
     * @throws Exception if the cURL request fails or returns a non-200 status code
     */
    private function confirmInvoice($invoiceId)
    {
        $merchantAPIKey = Configuration::get('MONEYBADGER_MERCHANT_API_KEY', '');

        $apiBase = $this->moneyBadgerAPIBase();
        $url = $apiBase . '/invoices/' . $invoiceId . '/confirm';

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-API-Key: ' . $merchantAPIKey,
        ]);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \Exception("cURL request failed: $error");
        }

        if ($httpCode !== 200) {
            curl_close($ch);
            throw new \Exception("Invoice confirm failed with status: $httpCode");
        }

        curl_close($ch);

        $invoice = json_decode($response);

        return $invoice;
    }

    /**
     * Cancel the invoice from MoneyBadger
     *
     * @param string $invoiceId
     *
     * @return mixed
     *
     * @throws Exception if the cURL request fails or returns a non-200 status code
     */
    private function cancelInvoice($invoiceId)
    {
        $merchantAPIKey = Configuration::get('MONEYBADGER_MERCHANT_API_KEY', '');

        $apiBase = $this->moneyBadgerAPIBase();
        $url = $apiBase . '/invoices/' . $invoiceId . '/cancel';

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-API-Key: ' . $merchantAPIKey,
        ]);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \Exception("cURL request failed: $error");
        }

        if ($httpCode !== 200) {
            curl_close($ch);
            throw new \Exception("Invoice confirm failed with status: $httpCode");
        }

        curl_close($ch);

        $invoice = json_decode($response);

        return $invoice;
    }


    /**
     * Get the invoice from MoneyBadger
     *
     * @param string $invoiceId
     *
     * @return mixed
     *
     * @throws Exception if the cURL request fails or returns a non-200 status code
     */
    private function getInvoice($invoiceId)
    {
        $merchantAPIKey = Configuration::get('MONEYBADGER_MERCHANT_API_KEY', '');

        $apiBase = $this->moneyBadgerAPIBase();
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
