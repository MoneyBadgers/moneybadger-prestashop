<?php
/**
 * This Controller display transactions in customer account
 */
class MoneyBadgerAccountModuleFrontController extends ModuleFrontController
{
    /**
     * {@inheritdoc}
     */
    public $auth = true;

    /**
     * {@inheritdoc}
     */
    public $authRedirection = 'my-account';

    /**
     * {@inheritdoc}
     */
    public function initContent()
    {
        parent::initContent();

        $orderPaymentsQuery = new DbQuery();
        $orderPaymentsQuery->select('op.order_reference, op.amount, op.id_currency, op.payment_method, op.transaction_id, op.card_number, op.card_brand, op.card_expiration, op.card_holder, op.date_add');
        $orderPaymentsQuery->from('order_payment', 'op');
        $orderPaymentsQuery->innerJoin('orders', 'o', 'op.order_reference = o.reference');
        $orderPaymentsQuery->where('o.id_customer = ' . (int) $this->context->customer->id);
        $orderPaymentsQuery->where('o.module = "' . pSQL($this->module->name) . '"');

        $orderPayments = Db::getInstance((bool) _PS_USE_SQL_SLAVE_)->executeS($orderPaymentsQuery);

        if (false === empty($orderPayments)) {
            foreach ($orderPayments as $key => $orderPayment) {
                $orderPayments[$key]['amount_formatted'] = Tools::displayPrice(
                    $orderPayment['amount'],
                    (int) $orderPayment['id_currency']
                );

                if (version_compare(_PS_VERSION_, '>=', '8')) {
                    $formattedDate = Tools::displayDate(
                        $orderPayment['date_add'],
                        true
                    );
                } else {
                    $formattedDate = Tools::displayDate(
                        $orderPayment['date_add'],
                        (int) $this->context->language->id,
                        true
                    );
                }
                $orderPayments[$key]['date_formatted'] = $formattedDate;
            }
        }

        $this->context->smarty->assign([
            'moduleDisplayName' => $this->module->displayName,
            'orderPayments' => $orderPayments,
        ]);

        $this->setTemplate('module:moneybadger/views/templates/front/account.tpl');
    }
}
