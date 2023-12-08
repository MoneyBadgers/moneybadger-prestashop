<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
class AdminConfigureCryptoConvertController extends ModuleAdminController
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
                'title' => $this->l('CryptoConvert Settings'),
                'description' => $this->l('Configure the settings for the CryptoConvert module.'),

                // Add the path to your logo image here
                'fields' => [
                    'CRYPTOCONVERT_TEST_MODE' => [
                        'type' => 'bool',
                        'title' => $this->l('Test Mode'),
                        'validation' => 'isBool',
                        'cast' => 'intval',
                        'required' => false,
                        'default' => false,
                    ],
                    'CRYPTOCONVERT_MERCHANT_CODE' => [
                        'title' => $this->l('Merchant Code'),
                        'type' => 'text',
                        'required' => true,
                    ],
                    'CRYPTOCONVERT_MERCHANT_API_KEY' => [
                        'title' => $this->l('Merchant API Key'),
                        'type' => 'text',
                        'required' => true,
                    ],
                    'CRYPTOCONVERT_LABEL' => [
                        'title' => $this->l('Label'),
                        'type' => 'text',
                        'default' => 'Pay with CryptoConvert',
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
