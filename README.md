# MPS Payments plugins

Shop plugins that add MPS Payments to an online store: the shopper pays on the MPS-hosted checkout and
the order is settled from the payment as MPS reports it.

| Plugin | Platform | Download | License |
|---|---|---|---|
| [WooCommerce](#woocommerce) | WordPress 6.4+, WooCommerce 8.3+, PHP 7.4+ | [`mps-payments.zip`](https://github.com/mps-emi/plugins/releases/download/woocommerce-latest/mps-payments.zip) | GPL-2.0-or-later |
| [Magento](#magento) | Magento Open Source / Adobe Commerce / Mage-OS 2.4.7 – 2.4.9, PHP 8.1 – 8.5 | [`mps-module-payments.zip`](https://github.com/mps-emi/plugins/releases/download/magento-latest/mps-module-payments.zip) | OSL-3.0 |
| [PrestaShop](#prestashop) | PrestaShop 8 – 9, with the PHP `curl` extension | [`mpspayments.zip`](https://github.com/mps-emi/plugins/releases/download/prestashop-latest/mpspayments.zip) | AFL-3.0 |
| [Odoo](#odoo) | Odoo 20, Community or Enterprise, self-hosted or Odoo.sh | [`payment_mps.zip`](https://github.com/mps-emi/plugins/releases/download/odoo-latest/payment_mps.zip) | LGPL-3 |

Each download link always gives the newest version. Earlier versions, with what changed in each, are in
the [releases](https://github.com/mps-emi/plugins/releases): `<plugin>-v<version>`. To hear about a new
version, watch this repository's releases (**Watch › Custom › Releases**).

## Before you start

- The shop's currency is **EUR**; MPS is not offered at checkout in another currency.
- You need a **client ID, client secret and webhook signing secret** from the MPS platform, under
  **Online › Get started**. Start with a test key (its client ID starts with `test_`): no card is charged,
  and orders still move through paid or failed exactly as they will live. Replace it with your live key
  when you are ready.

Every plugin checks the key with MPS when you save its settings, and says what MPS refused if it does not
accept it.

## WooCommerce

1. In WordPress, open **Plugins › Add New › Upload Plugin**, choose `mps-payments.zip`, click
   **Install Now**, then **Activate**.
2. Open **WooCommerce › Settings › Payments** and click **MPS Payments**.
3. Paste the **Client ID**, **Client secret** and **Webhook signing secret**, tick **Enable**, and click
   **Save changes**.

Both the block checkout and the classic checkout work.

**Update:** upload the new zip the same way; WordPress offers **Replace current with uploaded** and keeps
your settings.

## Magento

Both ways need shell access to the server.

From the zip, into `app/code`:

```bash
mkdir -p app/code/Mps/Payments
unzip mps-module-payments.zip -d app/code/Mps/Payments
bin/magento module:enable Mps_Payments
bin/magento setup:upgrade
bin/magento setup:di:compile                # in production mode
bin/magento setup:static-content:deploy     # in production mode
bin/magento cache:flush
```

Or with Composer, using the zip as an artifact repository (put it in a folder of its own):

```bash
composer config repositories.mps artifact /path/to/folder-with-the-zip
composer require mps/module-payments
bin/magento setup:upgrade
```

Then, in the admin:

1. Open **Stores › Configuration › Sales › Payment Methods** and expand **MPS Payments**.
2. Paste the **Client ID**, **Client secret** and **Webhook signing secret**, set **Enabled** to **Yes**,
   and click **Save Config**.

Magento's cron has to be running, as for any production shop.

**Update:** unzip the new zip over `app/code/Mps/Payments`, or put it in the artifact folder and run
`composer update mps/module-payments`; then `bin/magento setup:upgrade`.

## PrestaShop

1. In the back office, open **Modules › Module Manager**, click **Upload a module**, and drop
   `mpspayments.zip` on it. PrestaShop installs it straight away.
2. Click **Configure** on **MPS Payments**.
3. Paste the **Client ID**, **Client secret** and **Webhook signing secret** and click **Save**.
4. Copy the address under **Scheduled checks** and have it called every 5 minutes, with a crontab line on
   your server or PrestaShop's **Cron tasks manager** module. PrestaShop has no scheduler of its own; the
   checks are the safety net for a shopper who closes the tab on a shop MPS cannot notify.

   ```bash
   */5 * * * * curl -fsS "https://<your-shop>/module/mpspayments/cron?token=…" >/dev/null
   ```

**Update:** upload the new zip the same way. PrestaShop sees it is a newer version, upgrades it, and keeps
your settings.

## Odoo

Not on Odoo Online: the odoo.com SaaS does not allow uploading addons.

1. Unzip `payment_mps.zip` into a folder on your Odoo server's addons path (on Odoo.sh, commit the
   `payment_mps` folder to your repository) and restart Odoo.
2. Turn on developer mode, open **Apps**, click **Update Apps List**, search for **MPS** and click
   **Activate**.
3. Open **Invoicing › Configuration › Payment Providers** (or **Website › Configuration › Payment
   Providers**) and click **MPS**.
4. On the **Configuration** tab, paste the **Client ID** and **Client Secret**, and under **Advanced** the
   **Webhook Signing Secret**. Click **Publish** so the provider is offered at checkout, and save.

**Update:** replace the `payment_mps` folder with the one from the new zip, restart Odoo, and click
**Upgrade** on the MPS app in **Apps**.

## Not supported yet

- Refunds from the shop: refund the payment through MPS.
- Currencies other than EUR.

## About this repository

Each folder holds a plugin's source; `./build.sh` in it lints, tests and builds the zip into `dist/` (it
needs docker or podman, and zip). A push to `main` releases every plugin whose version has no release
yet, and points `<plugin>-latest` at it.
