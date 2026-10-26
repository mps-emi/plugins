# MPS Payments for PrestaShop

A PrestaShop 8/9 payment module (`mpspayments`) that sends the shopper to the MPS-hosted checkout and
makes the order once MPS says the cart was paid — the same flow as the WooCommerce plugin
(`../woocommerce`), in PrestaShop's shape. Merchant-facing install steps are on the docs site
(`src/Mps.Payments.Docs/content/docs/prestashop.mdx`).

| Path | What lives there |
|---|---|
| `mpspayments/` | The module, exactly as it is zipped. Plain PHP and Smarty templates — no build step to run it, no Composer. Written for PHP 7.2+ (PrestaShop 8's floor). AFL-3.0 (`LICENSE`, and the header on every file). |
| `mpspayments/mpspayments.php` | Install (refused without the curl extension), the settings page (HelperForm), the payment option (`hookPaymentOptions`), the confirmation line (`hookDisplayPaymentReturn`). |
| `mpspayments/views/templates/` | `front/waiting.tpl`, `hook/` (payment option text, confirmation line), `admin/` (the scheduled-check address). All markup lives here, none in PHP. |
| `mpspayments/classes/MpsPaymentsApi.php` | Token (cached in `ps_configuration`) and the two internet-payment calls. |
| `mpspayments/classes/MpsPaymentsRepository.php` | `{prefix}mpspayments_payment`: one row per checkout attempt. |
| `mpspayments/classes/MpsPaymentsSettlement.php` | Reads the payment and makes the order — the one place a cart becomes an order. |
| `mpspayments/controllers/front/` | `redirect` (from the payment option), `return`, `webhook`, `cron`. |
| `mpspayments/classes/MpsPaymentsSignature.php`, `…CallbackUrl.php`, `…Outcome.php`, `…GatewayUrl.php`, `…OrderCheck.php` | The pure rules, with no PrestaShop dependency. |
| `mpspayments/translations/` | nl-NL and fr-FR XLIFF, generated — edit `i18n.py`, not these. `i18n.py` also writes their `index.php`. |
| `i18n.py` | The Dutch and French for every string, and the generator of the XLIFF files. |
| `tests/run.php` | The pure rules, tested without PrestaShop. |
| `tests/translations.py` | Every `trans()`/`{l}` string of the module has its nl and fr row, and every row is used. |
| `build.sh` | Translations, lint and tests on PHP 7.2 and 8.5 (PrestaShop 8.0's floor, 9.2's ceiling), writes `dist/`. |
| `logo-64.png` | The 64×64 original of `mpspayments/logo.png` (shipped at 32×32, as PrestaShop wants). |
| `dev/` | A throwaway PrestaShop shop with the module mounted from source (or installed from the zip). |

## How a cart is settled

1. **Open** — the payment option posts to the `redirect` controller, which opens an internet payment
   with `amount` (the cart total in cents), `reference` (`cart-<id>`), `successUrl`/`failureUrl` (both the
   `return` controller, carrying a random per-attempt token) and, when the shop is publicly reachable over
   HTTPS, `callbackUrl`. The attempt is stored in `{prefix}mpspayments_payment`. No order exists yet.
2. **Return, webhook and scheduled check** all call `MpsPaymentsSettlement::settle`: read
   `GET /api/payments/internet/{paymentReference}` and act on `state`, under a MySQL `GET_LOCK` per cart
   with the attempt re-read inside it, so a return and a webhook arriving together make one order. Which URL
   the shopper came back on only decides what they see. A webhook is answered after re-reading, so a
   forged one cannot make an order. Without a webhook signing secret configured a webhook is answered
   `200` and ignored — no lookup, no read (MPS signs everything it sends, and sends nothing for a key
   without a secret); with one, a missing or non-matching signature or a stale timestamp gets `401`.
   The webhook and cron controllers answer in maintenance mode too.
3. `executed` with the full amount → `validateOrder` in **Payment accepted**, the MPS reference as the
   payment's transaction ID; the confirmation e-mail goes out then. `executed` with less (a partial
   approval) → `validateOrder` with the approved amount as the amount paid: PrestaShop itself puts the order
   in **Payment error**, and a private note gives the amounts. `failed` or `abandoned` → no order; the cart
   is still the shopper's, and a returning shopper is sent back to the payment step. Anything else is
   pending: a shopper back on the success URL sees a page that looks again every few seconds.
4. Before `validateOrder` the customer must still exist, the module still be enabled and still be offered
   for the cart (`Module::getPaymentModules()`, as `ps_wirepayment` checks). Otherwise no order is made,
   the attempt stays open for the next check, and an error is logged whatever the debug setting.
5. If a second attempt for a cart is paid after the order was made (two tabs), the order gets a private
   note to refund it in MPS.

Making the order only when paid is the usual PrestaShop pattern for redirect payments, and it is why this
module needs no "awaiting payment" order state: an unpaid attempt leaves no order, no e-mail and no
reserved stock. The cost is that a cart changed after its payment was opened is caught by PrestaShop's own
amount check (Payment error), not prevented.

The scheduled checks (the `cron` controller, each attempt at 10 min, 30 min, 1 h and 6 h after it was
opened) are what settle a cart on a shop that cannot receive webhooks — localhost, or a private network —
when the shopper closes the tab. PrestaShop has no cron of its own: the merchant calls the URL shown on the
settings page every 5 minutes, from a crontab or the Cron tasks manager module.

A client ID starting `test_` is a test key. It is simulated: the MPS checkout shows a test-mode banner and
accepts only the MPS test cards, no card is charged and nothing is paid out, and orders move exactly as
they will live. It works for 24 hours; the module says so when the key is saved.

## Build and publish

Releases go through the CD pipeline once it is switched on: `src/Pipelines/cd.yml` builds and publishes
the plugins when its `pluginsEnabled` variable is `'true'` — **off for now**, since the staging service
connection it needs (`WWD01UD-SUB0001`) does not exist yet. It runs this build and uploads `dist/` to
`<plugins base URL>/prestashop/` on the CDN —
staging first, production after the approval (`templates/publish_plugins.yml`). A version already
published is kept, so bump the version first. The steps below are what it does, for a manual one-off.

```bash
./build.sh
```

Upload `dist/mpspayments-<version>.zip` and `dist/mpspayments.zip` to the folder `PRESTASHOP_PLUGIN_URL`
(docs site) points at. PrestaShop has no self-update for modules from outside its marketplace: a merchant
updates by uploading the new zip in Module Manager, which runs the upgrade.

The zip holds an `index.php` in every folder and the AFL-3.0 header on every `.php` and `.tpl`, as the
PrestaShop Addons validator requires. Still to do for an Addons listing: the `module_key` (issued by the
Addons seller account, then set in the constructor) and the merchant PDF.

Before releasing: bump `$this->version` in `mpspayments/mpspayments.php` and add a `## <version>` entry
to `CHANGELOG.md` (the build refuses a version without one). When strings change, add them to `i18n.py`;
the build regenerates the XLIFF files and fails on a string without its translations.

## Run it locally

```bash
MPS_CLIENT_ID=test_… MPS_CLIENT_SECRET=… ./dev/up.sh http://host.containers.internal:<gateway-port>
./dev/cron.sh       # the scheduled checks, once; the container runs no cron
./dev/down.sh
```

`PS_VERSION=8` runs PrestaShop 8 instead of 9; `MODULE_FROM=zip` installs `dist/mpspayments.zip` instead
of mounting the source.

## Configuration Settings

Merchant settings live in **Modules › Module Manager › MPS Payments › Configure** (`ps_configuration`,
per shop in a multistore).

| Setting | Source | Type | Required | Default | Description | Example |
|---------|--------|------|----------|---------|-------------|---------|
| `MPS_PAYMENTS_CLIENT_ID` | module settings | string | Yes | — | API client id. A `test_` prefix marks a test key: MPS test cards only, nothing charged, valid 24 hours. Without it and the secret the option is not offered. | `test_BE0000000000` |
| `MPS_PAYMENTS_CLIENT_SECRET` | module settings | string | Yes | — | Its secret. Checked with a token request on save; never shown again (an empty field on save keeps it). | — |
| `MPS_PAYMENTS_WEBHOOK_SECRET` | module settings | string | No | empty | Signing secret, shown with the key in the MPS platform (Online › Get started); every key, test or live, has one. When set, notifications with a missing or non-matching `X-Mps-Signature` are refused with 401. When empty, every notification is answered 200 and ignored, and the shop is settled by the return and the scheduled checks only. | `whsec_…` |
| `MPS_PAYMENTS_GATEWAY_URL` | module settings | string | No | `https://gateway.mps-emi.com` | Gateway the module calls for tokens and payments. Only an absolute `https://` URL is saved, or empty for the default; anything else shows an error and keeps the stored value. | `https://gateway.mps-emi.dev` |
| `_PS_MPS_PAYMENTS_GATEWAY_URL_` | PHP constant in `config/defines_custom.inc.php` | string | No | unset | Overrides `MPS_PAYMENTS_GATEWAY_URL` (the field turns read-only), for development against a local or staging gateway. | `http://host.containers.internal:7280` |
| `MPS_PAYMENTS_DEBUG` | module settings | bool | No | `0` | Log every call to Advanced Parameters › Logs (object type `MpsPayments`): outgoing (method, path, status, duration, payment reference and state) and incoming (returns, webhooks). Never tokens, secrets, `checkoutUrl` or the return URLs. | `1` |
| `MPS_PAYMENTS_TITLE` | module settings | string | No | empty → "Pay with MPS", translated | The option's name at checkout. | `MPS` |
| `MPS_PAYMENTS_DESCRIPTION` | module settings | string | No | empty → a standard text, translated | Shown under the title at checkout. | `Pay on the MPS page.` |
| `MPS_PAYMENTS_CRON_TOKEN` | generated on install | string | — | random | The secret in the scheduled-check URL; a call without it gets 403. | — |
| `MPS_PAYMENTS_TOKEN_<hash>` | written by the module | JSON | — | — | Cached access tokens, one per gateway/client/secret. | — |
| `PS_PORT` | env var for `dev/*.sh` | int | No | `8090` | Port of the dev shop. | `8091` |
| `PS_VERSION` | env var for `dev/up.sh` | string | No | `9` | PrestaShop image tag. | `8` |
| `MODULE_FROM` | env var for `dev/up.sh` | `source` or `zip` | No | `source` | Mount the module from source, or install `dist/mpspayments.zip`. | `zip` |
| `MPS_CLIENT_ID`, `MPS_CLIENT_SECRET` | env vars for `dev/up.sh` | string | No | unset (option not offered) | Credentials the dev shop is configured with. | `test_BE0000000000` |
| `CONTAINER_CLI` | env var for `build.sh` and `dev/*.sh` | string | No | `docker`, else `podman` | Container CLI for the lint, the tests and the dev shop. | `podman` |
