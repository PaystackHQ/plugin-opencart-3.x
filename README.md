# Paystack OpenCart Extension

## Description
OpenCart Paystack payment gateway integration. Visit the [Paystack Website](http://paystack.com) to know how paystack works.
Install to receive payments for your goods in naira from any Mastercard, Visa or Verve card in the world.

## Requirements
- Curl 7.34.0 or more recent
- PHP with the 21 Oct 2026 security fixes for critical remote code execution flaws (GHSA-w8hw-w9fx-j79w, GHSA-6wmf-4rv5-p3rp; [php.net advisory](https://www.php.net/releases/8_2_35.php)): **8.2.35, 8.3.36, 8.4.27, 8.5.12 or later**. PHP 8.1 and older no longer receive security fixes.
- OpenCart 3.0.5.x. OpenCart 3.0.3.x doesn't run on PHP 8, and this module was tested on OpenCart 3.0.5.1 with PHP 8.3.36.
- OpenSSL v1.0.1 or more recent

## Notes
- The payment method is offered for these store currencies: `NGN`, `GHS`, `USD` and `ZAR`.
- You need to have created an account on [paystack.com](https://dashboard.paystack.co/#/signup).

## Features:
- Paystack payment gateway integration
- Activates payment module only when cart currency is `NGN`, `GHS`, `USD` or `ZAR`.
- Activate payment module only when order total reaches the amount you specified
- Captures call back notification to automatically update order status
- Simply turn on Live Mode to accept live payments.

## Installation
0. Visit [Paystack OpenCart Extension](http://www.opencart.com/index.php?route=extension/extension/info&extension_id=25767&filter_search=paystack) to download the latest release.
1. Unzip the files. Select the right version for your OpenCart.
2. Upload the files to your OpenCart installation folder with a FTP client 
                     `OR`
3. Upload the zipped file to the root of your OpenCart installation then unzip using cPanel File Manager. The folders should merge. 
4. In admin panel, proceed to ‘Extensions > Payment’ and install ‘Paystack’.
5. Configure the module accordingly. 
 - To get your keys, visit [the Paystack Dashboard](https://dashboard.paystack.co/#/settings/developer). A restricted key is recommended (see below).
6. Enable Paystack payment gateway on your OpenCart admin.
7. Add and set NGN, GHS or USD as your default store currency.
8. Proceed to [Paystack OpenCart Extension](http://www.opencart.com/index.php?route=extension/extension/info&extension_id=25767&filter_search=paystack) to rate our work.

## Using a restricted key

Each mode's key field accepts a Paystack restricted key (`rk_test_…` / `rk_live_…`) as well as a secret key (`sk_…`). You don't need a public key with a restricted key: the plugin reads it from Paystack when you save.

Create the key on the Paystack Dashboard with only these permissions:

- **Account: read**: lets the plugin read your public key and confirm the key's mode
- **Transactions: read**: verifies payments

Saving checks the key with Paystack and refuses it when:

- the key is invalid, revoked, or restricted keys aren't enabled on your account;
- a test key is in the live field, or a live key in the test field;
- it is missing either permission above;
- it has a permission the plugin doesn't need. This covers **Transactions: write**, Disputes and refunds, Bulk charges, Direct debit, Preauthorizations, Settlements, Subaccounts, Transfers, Virtual accounts, Webhooks, Connect, Payouts, Customers and Balance;
- Paystack couldn't be reached to check it. Try again. If you use an IP whitelist on your Paystack account, add your server's address.

Account: write can't be detected, so keep it off yourself. Permissions are checked when you save a key and when you switch a mode on. If you later add permissions to a key on the Dashboard, save it again.

Saved keys are never shown on the settings page, only their last four characters. Leave a field blank to keep the saved key, or tick **Remove saved key** to delete it (not possible for the mode in use). Secret keys keep working as before.

## Webhooks

Set your webhook URL on the Paystack Dashboard to the URL shown on the settings page. Then paste the **webhook signing secret** (`whsec_…`) from the Dashboard into the matching mode's field.

- With a signing secret, webhooks are verified with the `Paystack-Signature` header, and the older `X-Paystack-Signature` header is refused.
- With a secret key and no signing secret, webhooks are verified with `X-Paystack-Signature`, as before.
- With a restricted key and no signing secret, webhooks are ignored. A restricted key can't verify Paystack's signature.

Every `charge.success` webhook is re-checked with Paystack's API before an order changes. Customers returning to the shop complete their orders either way.

## Order status rules

- An order is marked paid only when Paystack confirms a successful payment whose reference, amount and currency match the order.
- A payment that doesn't match keeps the order's status and adds one history note for you to check (orders not yet confirmed appear under the **Missing Orders** filter in Sales → Orders).
- Failed or abandoned payments, including closing the payment popup, leave the order unchanged.
- An order that is already paid or completed is never changed again.

## Tests

The standalone checks in `tools/` run from the command line only (they are excluded from the Docker image and from release archives). Run them all with:

```bash
php tools/run-tests.php
```

It runs each `tools/test-*.php` script and exits non-zero if any fails.

## Running the paystack OpenCart plugin on docker
Contained within this repo, is a dockerfile and a docker-compose file to quickly spin up an opencart and mysql container with the paystack opencart plugin installed.

### Prerequisites
- Install [Docker](https://www.docker.com/)

### Quick Steps
- Create a `local.env` file off the `local.env.sample` in the root directory. Replace the `*******` with the right values
- Run `docker-compose up` from the root directory to build and start the mysql and opencart containers.
- Visit `localhost:8000` on your browser to access and setup opencart.
- Run `docker-compose stop` from the root directory to stop the containers.

## Other Configuration
To add currencies, you can do so at System > Localisation > Currencies. 

## Documentation
* [Paystack Documentation](https://developers.paystack.co/v2.0/docs/)
* [Paystack Helpdesk](https://paystack.com/help)

## Support
For bug reports and feature requests directly related to this plugin, please use the [issue tracker](https://github.com/PaystackHQ/plugin-opencart-3.x/issues). 

For general support or questions about your Paystack account, you can reach out by sending a message from [our website](https://paystack.com/contact).

## Community
If you are a developer, please join our Developer Community on [Slack](https://slack.paystack.com).

## Contributing to OpenCart Extension for Paystack

If you have a patch or have stumbled upon an issue with the OpenCart extension for Paystack, you can contribute this back to the code. Please read our [contributor guidelines](https://github.com/PaystackHQ/plugin-opencart-3.x/blob/master/CONTRIBUTING.md) for more information how you can do this.
