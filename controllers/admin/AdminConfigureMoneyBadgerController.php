<?php
class AdminConfigureMoneyBadgerController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->className = 'Configuration';
        $this->table = 'configuration';

        parent::__construct();

        if (empty(Currency::checkPaymentCurrencies($this->module->id))) {
            $this->warnings[] = $this->l('No currency has been set for this module.');
        }

        /* Check if SSL is enabled */
        if (!Configuration::get('PS_SSL_ENABLED')) {
            $this->warnings[] = $this->l('You must enable SSL in your store if you want to use this module in production.');
        }

        $this->fields_options = [
            $this->module->name => [
                'title' => $this->l('MoneyBadger Settings'),
                'description' => $this->l('Configure the settings for the MoneyBadger module.'),

                // Add the path to your logo image here
                'fields' => [
                    'MONEYBADGER_TEST_MODE' => [
                        'type' => 'bool',
                        'title' => $this->l('Test Mode'),
                        'validation' => 'isBool',
                        'cast' => 'intval',
                        'required' => false,
                        'default' => false,
                    ],
                    'MONEYBADGER_MERCHANT_CODE' => [
                        'title' => $this->l('Merchant Code'),
                        'type' => 'text',
                        'required' => true,
                    ],
                    'MONEYBADGER_MERCHANT_API_KEY' => [
                        'title' => $this->l('Merchant API Key'),
                        'type' => 'text',
                        'required' => true,
                    ],
                    'MONEYBADGER_LABEL' => [
                        'title' => $this->l('Label'),
                        'type' => 'text',
                        'default' => 'MoneyBadger Crypto Payments',
                        'required' => false,
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                ],
            ],
        ];
    }

    public function postProcess()
    {
        if (Tools::isSubmit('submitOptionsconfiguration')) {
            $merchantCode = Tools::getValue('MERCHANT_CODE');
            Configuration::updateValue('MERCHANT_CODE', $merchantCode);

            $apiKey = Tools::getValue('MERCHANT_API_KEY');
            Configuration::updateValue('MERCHANT_API_KEY', $apiKey);

            parent::postProcess();
        }
    }
}
