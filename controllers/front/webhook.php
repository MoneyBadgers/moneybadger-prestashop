<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class moneybadgerWebhookModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        $order_id = (int) Tools::getValue('order_id');

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

        $orderCurrentState = $order->getCurrentState();

        // check if order is unpaid or already marked paid
        if (
      $orderCurrentState === (int) Configuration::get('PS_OS_PAYMENT') ||
      $orderCurrentState === (int) Configuration::get('PS_OS_OUTOFSTOCK_PAID')
    ) {
            exit;
        }

        $invoice = $this->getInvoice($order_id);

        // add transaction id to order payment
        $orderPaymentCollection = $order->getOrderPaymentCollection();
        if ($orderPaymentCollection && $orderPaymentCollection->count()) {
            /** @var OrderPayment $orderPayment */
            $orderPayment = $orderPaymentCollection->getLast();
            $orderPayment->transaction_id = $invoice->id;
            $orderPayment->update();
        }

        switch ($invoice->status) {
      case MoneyBadger::PAYMENT_STATUS_CANCELLED:
        $order->setCurrentState((int) Configuration::get('PS_OS_CANCELED'));
        break;
      case MoneyBadger::PAYMENT_STATUS_TIMEDOUT:
        $order->setCurrentState((int) Configuration::get(MoneyBadger::ORDER_STATE_CAPTURE_TIMEDOUT));
        break;
      case MoneyBadger::PAYMENT_STATUS_PAID:
        // mark the order as paid
        if ($orderCurrentState !== (int) Configuration::get('PS_OS_OUTOFSTOCK_PAID')) {
            $order->setCurrentState((int) Configuration::get('PS_OS_PAYMENT'));
        }
        break;
      default:
        break;
    }
    }

    /**
     * Get the invoice from MoneyBadger
     *
     * @param int $invoiceId
     *
     * @return mixed
     *
     * @throws GuzzleException
     */
    private function getInvoice($invoiceId)
    {
        $client = new Client();

        $merchantAPIKey = Configuration::get('MONEYBADGER_MERCHANT_API_KEY', '');

        $apiBase = 'https://api' . (Configuration::get('MONEYBADGER_TEST_MODE', false) ? 'staging.' : '') . 'cryptoqr.net/api/v2';

        try {
            $response = $client->request('GET', $apiBase . '/invoices/' . $invoiceId, [
        'headers' => [
          'Content-Type' => 'application/json',
          'X-API-Key' => $merchantAPIKey,
        ],
      ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode !== 200) {
                throw new \Exception("Invoice request failed with status: $statusCode");
            }

            // $invoice->orderId 38
            // $invoice->status is a string with one of these values: requested, paid, timedout, cancelled
            $invoice = json_decode($response->getBody());

            return $invoice;
        } catch (GuzzleException $e) {
            throw $e;
        }
    }
}
