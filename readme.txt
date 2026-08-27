=== FinCobra for WooCommerce ===
Contributors: fincobra
Tags: cryptocurrency, bitcoin, stablecoin, payments, checkout
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Requires Plugins: woocommerce
WC requires at least: 9.0
WC tested up to: 11.0
Stable tag: 0.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept supported cryptocurrencies through FinCobra's hosted, non-custodial checkout.

== Description ==

FinCobra adds a WooCommerce payment method that redirects shoppers to a hosted
FinCobra payment page. Payment confirmation is verified server-to-server before
the WooCommerce order is marked paid.

The plugin sends order total, USD currency, a store-scoped order reference,
return URLs, and order identifiers to the FinCobra service. It does not send
cart contents, passwords, wallet private keys, or card data.

FinCobra is an external service operated at https://fincobra.com/. Its terms
are at https://fincobra.com/terms and privacy policy is at
https://fincobra.com/privacy.

A FinCobra account and either a USD 99/year WooCommerce plan with no percentage
commission or the standard no-subscription plan with a 0.5% commission are
required.

Version 1 supports USD stores and standard one-time product orders. It does not
claim support for automatic refunds, subscriptions, or pre-orders.

Docs: https://fincobra.com/docs/
Demo: https://woo-demo.fincobra.com/

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

1. Activate WooCommerce first.
2. Upload and activate FinCobra for WooCommerce. There is no WordPress.org listing or auto-updater.
3. Open WooCommerce > Settings > Payments > FinCobra, or
   wp-admin/admin.php?page=wc-settings&tab=checkout&section=fincobra
   if the Payments table does not load.
4. Paste a FinCobra merchant API key and click Connect.
5. Choose Annual or Commission at https://fincobra.com/woocommerce if prompted.
6. Enable FinCobra at checkout and save.

== Frequently Asked Questions ==

= How is the plugin installed? =

Download the release zip and upload it in WordPress. There is no auto-updater;
install a newer release zip when you want to upgrade.

= Does returning from the payment page mark an order paid? =

No. Only a signed webhook followed by an authoritative invoice lookup can mark
an order paid. A scheduled reconciliation also recovers missed webhooks.

= Which currencies are supported? =

Version 1 accepts WooCommerce orders denominated in USD only.

= Are credentials logged? =

No. Optional WooCommerce diagnostic logs contain only redacted identifiers and
status information.

== Changelog ==

= 0.1.2 =

* Check for a WooCommerce billing plan after connect and warn on the settings screen.
* Add a Connect action, Connected badge, and FinCobra checkout defaults for new installs.
* Add a Payments-tab fallback link when the WooCommerce payments table does not load.

= 0.1.1 =

* Show the WooCommerce billing page link on the settings screen after connect.
* Replace the raw missing-plan invoice error with a merchant-facing message.

= 0.1.0 =

* Initial private pilot release.
