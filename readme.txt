=== FinCobra for WooCommerce ===
Contributors: fincobra
Tags: cryptocurrency, bitcoin, stablecoin, payments, checkout
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 8.1
Requires Plugins: woocommerce
WC requires at least: 9.0
WC tested up to: 10.9
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept supported cryptocurrencies through FinCobra's hosted, non-custodial checkout.

== Description ==

FinCobra is a private-pilot WooCommerce payment method. Shoppers are redirected
to a hosted FinCobra payment page. Payment confirmation is verified
server-to-server before the WooCommerce order is marked paid.

This release supports USD stores and standard one-time product orders. It does
not support automatic refunds, subscriptions, or pre-orders.

The plugin sends order total, USD currency, a store-scoped order reference,
return URLs, and order identifiers to FinCobra. It does not send cart contents,
passwords, wallet private keys, or card data.

Connect with a FinCobra Checkout API key. The plugin exchanges that key once
for encrypted store-scoped credentials and registers a webhook for payment
confirmation.

Install the zip from GitHub Releases. There is no auto-updater.

A FinCobra account and either a USD 99/year WooCommerce plan with no percentage
commission or the standard no-subscription plan with a 0.5% commission are
required.

Docs: https://fincobra.com/docs/
Demo: https://woo-demo.fincobra.com/

FinCobra is an external service operated at https://fincobra.com/. Its terms
are at https://fincobra.com/terms and privacy policy is at
https://fincobra.com/privacy.

== External service ==

This plugin connects to FinCobra to create hosted payment invoices, retrieve
their authoritative status, and receive signed payment notifications. These
requests occur only after a merchant connects the plugin and when a shopper
chooses FinCobra or an order needs reconciliation.

The service receives the order total and currency, a store-scoped order
reference, return URL, optional customer name/email/ID, and limited order
metadata. It does not receive cart contents, WordPress passwords, wallet private
keys, or card data.

FinCobra service: https://fincobra.com/
Terms: https://fincobra.com/terms
Privacy policy: https://fincobra.com/privacy

== Installation ==

1. Download fincobra-woocommerce.zip from this GitHub repository's Releases page.
2. In WordPress, open Plugins > Add New > Upload Plugin and upload the zip.
3. Activate FinCobra for WooCommerce. WooCommerce must already be active.
4. Open WooCommerce > Settings > Payments > FinCobra.
5. Enter a FinCobra Checkout API key and save.
6. The key is exchanged once for encrypted, store-scoped credentials and a
   webhook secret. The raw API key is not saved.
7. Enable the payment method and save.

== Frequently Asked Questions ==

= How is the plugin installed? =

Download the release zip and upload it in WordPress. There is no auto-updater;
install a newer release zip when you want to upgrade.

= Does returning from the payment page mark an order paid? =

No. Only a signed webhook followed by an authoritative invoice lookup can mark
an order paid. A scheduled reconciliation also recovers missed webhooks.

= Which currencies are supported? =

This release accepts WooCommerce orders denominated in USD only.

= Are credentials logged? =

No. Optional WooCommerce diagnostic logs contain only redacted identifiers and
status information.

== Changelog ==

= 0.1.0 =

* Initial private pilot release.
