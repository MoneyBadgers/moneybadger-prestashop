# MoneyBadger payments for PrestaShop

## MoneyBadger module for PrestaShop v8.1.0

This is the MoneyBadger payments module for PrestaShop. Please feel free to [contact the MoneyBadger support team](info@moneybadger.co.za) or ([@MoneyBadgerPay](https://twitter.com/MoneyBadgerPay)) should you require any assistance.

## Installation
1. Login to the PrestaShop Admin dashboard.
2. Navigate to **Modules** -> **Module Manager**.
3. Click the **Upload a module** button.
4. Click **Drop your module archive here or select file** and select **[moneybadger-prestashop.zip].
5. Click the **Configure** button. The MoneyBadger configuration options will now display.
6. Enter your preferred details and click **Save Changes** at the bottom of the page.

## Collaboration

Please submit pull requests with any tweaks, features, or fixes you would like to share.

## Process Flow:

1. Create order from cart (most plugins only create the order after payment).
2. Display iframe with MoneyBadger payment page. Javacript loaded on iframe keeps polling MoneyBadger API for payment status.
3. Once payment is complete, javascript poller redirect away from iFrame page to Presta validation page.