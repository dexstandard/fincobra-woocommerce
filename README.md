# FinCobra for WooCommerce

Private-pilot payment gateway for WooCommerce. Shoppers pay on FinCobra's
hosted checkout. The store marks an order paid only after a signed webhook and
an authoritative invoice lookup.

This plugin is distributed as a GitHub Releases zip. There is no auto-updater.

- Docs: <https://fincobra.com/docs/>
- Live demo shop: <https://woo-demo.fincobra.com/>

## What this release supports

- WooCommerce stores priced in **USD**
- One-time product orders (classic checkout and Checkout Blocks)
- FinCobra API key connect plus a store webhook for payment confirmation

It does not support automatic refunds, subscriptions, or pre-orders.

## Pricing

Use either FinCobra WooCommerce plan:

| Plan | Subscription | FinCobra commission |
| --- | --- | --- |
| Annual | $99 per year | 0% |
| Pay as you go | None | 0.5% of confirmed order volume |

Both plans use the same plugin, hosted checkout, and webhook confirmation.
Unpaid, voided, and expired invoices do not count as commission volume.

## Install

1. Download `fincobra-woocommerce.zip` from
   [GitHub Releases](https://github.com/dexstandard/fincobra-woocommerce/releases).
2. In WordPress, open **Plugins → Add New → Upload Plugin** and upload the zip.
3. Activate **FinCobra for WooCommerce**. WooCommerce must already be active.
4. Open **WooCommerce → Settings → Payments → FinCobra**.
5. Paste a FinCobra Checkout API key and save. The key is exchanged once for
   encrypted, store-scoped credentials and a webhook secret. The raw API key is
   not stored.
6. Enable the payment method and save.

Requirements: WordPress 6.6+, WooCommerce 9.0+, PHP 8.1+, a USD store, HTTPS,
and a FinCobra account with a receiving wallet.

## How payment works

1. The customer chooses FinCobra and places the order.
2. The plugin creates one FinCobra invoice and puts the WooCommerce order on
   hold.
3. The customer pays on the hosted FinCobra page.
4. FinCobra POSTs a signed webhook to the store.
5. The plugin verifies the signature, fetches the invoice, and marks the order
   paid only after confirmation.

Returning from the payment page does not mark an order paid. A scheduled
reconciliation job covers missed webhooks.

## Build and test

```sh
npm test
npm run test:php
npm run lint
npm run format:check
npm run build
```

`npm run build` writes `dist/fincobra-woocommerce.zip` with a
`fincobra-woocommerce/` folder at the zip root.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
