# MPS Payments plugins

Shop plugins that add MPS Payments to an online store: the shopper pays on the MPS-hosted checkout and
the order is settled from the payment as MPS reports it.

| Plugin | Platform | Folder | License |
|---|---|---|---|
| MPS Payments for WooCommerce | WordPress 6.4+ / WooCommerce, PHP 7.4+ | [`woocommerce/`](woocommerce) | GPL-2.0-or-later |
| MPS Payments for Magento | Magento Open Source / Adobe Commerce 2.4.7 – 2.4.9, PHP 8.1 – 8.5 | [`magento/`](magento) | OSL-3.0 |
| MPS Payments for PrestaShop | PrestaShop 8 / 9, PHP 7.2+ | [`prestashop/`](prestashop) | AFL-3.0 |
| MPS Payments for Odoo | Odoo 20 | [`odoo/`](odoo) | LGPL-3 |

## Download

Every version is a [GitHub Release](../../releases) named `<plugin>-v<version>`, with the installable
zip attached. The release `<plugin>-latest` always holds the newest one under a name that does not
change, e.g. `releases/download/woocommerce-latest/mps-payments.zip`. Install it the way the platform
installs an uploaded plugin; each plugin's README and changelog say what changed.

## Build

Each folder builds on its own with `./build.sh`, which lints and tests the plugin and writes the zip to
`dist/`. It needs docker or podman (the tests run in containers) and zip.

## Releasing

Actions › **Release** › choose the plugin, or `gh workflow run release.yml -f plugin=<plugin>`. The
workflow runs that plugin's `build.sh` and publishes the zip as the release `<plugin>-v<version>`, the
version being the one in the plugin's source. A version that is already released is refused.

## Where changes are made

This repository is a mirror. The plugins are developed in the MPS repository and copied here on each
publish, so a change made directly here is overwritten by the next one.
