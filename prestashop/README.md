# MPS Payments for PrestaShop

A PrestaShop 8/9 payment module (`mpspayments`) that sends the shopper to the MPS-hosted checkout and
makes the order once MPS says the cart was paid — the same flow as the WooCommerce plugin
(`../woocommerce`), in PrestaShop's shape. Merchant-facing install steps are on the docs site
(`src/Mps.Payments.Docs/content/docs/prestashop.mdx`).

| Path | What lives there |
|---|---|
| `mpspayments/` | The module, exactly as it is zipped. Plain PHP and Smarty templates — no build step to run it, no Composer. Written for PHP 7.2+ (PrestaShop 8's floor). AFL-3.0 (`LICENSE`, and the header on every file). |
| `mpspayments/mpspayments.php` | Install (refused without the curl extension), the settings page (HelperForm), the payment option (`hookPaymentOptions`), the confirmation line (`hookDisplayPaymentReturn`), refunds (`hookActionOrderSlipAdd`). |
| `mpspayments/upgrade/` | `upgrade-<version>.php`, run by Module Manager when a newer zip is uploaded over an installed module (0.3.0 registers the refund hook; 0.3.1 adds the attempt's idempotency key to the payment table; 1.0.0 deletes the client ID, secret and cached tokens of a shop set up before API keys). |
| `mpspayments/views/templates/` | `front/waiting.tpl`, `hook/` (payment option text, confirmation line), `admin/` (the scheduled-check address). All markup lives here, none in PHP. |
| `mpspayments/classes/MpsPaymentsApi.php` | The two internet-payment calls and the refund, with the API key as the bearer. |
| `mpspayments/classes/MpsPaymentsRepository.php` | `{prefix}mpspayments_payment`: one row per checkout attempt. |
| `mpspayments/classes/MpsPaymentsSettlement.php` | Reads the payment and makes the order — the one place a cart becomes an order. |
| `mpspayments/classes/MpsPaymentsRefunder.php` | Refunds a credit slip in MPS and reports the answer — the one place a PrestaShop refund becomes money back. |
| `mpspayments/controllers/front/` | `redirect` (from the payment option), `return`, `webhook`, `cron`. |
| `mpspayments/classes/MpsPaymentsSignature.php`, `…CallbackUrl.php`, `…Outcome.php`, `…GatewayUrl.php`, `…OrderCheck.php`, `…Refund.php` | The pure rules, with no PrestaShop dependency. |
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
   HTTPS, `callbackUrl`. The attempt is stored in `{prefix}mpspayments_payment` before the call, with
   an `Idempotency-Key` of `prestashop-cart-<id_cart>-<random UUID>` the call carries; the payment reference
   is filled in once MPS answered. No order exists yet.
   - **Same key** (`MpsPaymentsRepository::isResendable`): the cart's last attempt has no outcome yet, is
     for the same total and is less than 24 hours old (MPS keeps a key that long) — a double-submitted
     checkout, a reload of `redirect`, a shopper back on the payment step from a checkout they did not
     finish, or a try after no answer or a `5xx`. The same key, total, reference and return URLs (the
     attempt's token) go out, so MPS answers with the payment it already opened and its `checkoutUrl`, and
     no second payment exists. If that attempt already has a payment it is settled first: one MPS reads
     as failed or abandoned by then gets an outcome, and a new attempt is made. A `409` (MPS still
     opening the first send) is sent once more after a second. A per-cart `GET_LOCK` makes a double submit
     find one attempt.
   - **New key**: the last payment failed or was abandoned (back on the failure URL, or settled as above),
     the cart total changed, MPS refused the request with a `4xx` other than `409`, or the attempt is
     older than 24 hours.
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
   note to refund that second payment in MPS: a credit slip refunds only the payment the order was made
   from (see Refunds).

Making the order only when paid is the usual PrestaShop pattern for redirect payments, and it is why this
module needs no "awaiting payment" order state: an unpaid attempt leaves no order, no e-mail and no
reserved stock. The cost is that a cart changed after its payment was opened is caught by PrestaShop's own
amount check (Payment error), not prevented.

The scheduled checks (the `cron` controller, each attempt at 10 min, 30 min, 1 h and 6 h after it was
opened) are what settle a cart on a shop that cannot receive webhooks — localhost, or a private network —
when the shopper closes the tab. PrestaShop has no cron of its own: the merchant calls the URL shown on the
settings page every 5 minutes, from a crontab or the Cron tasks manager module.

The module connects with an **API key** (`mps_live_…`, or `mps_test_…` for a test key), pasted with the
webhook signing secret from the MPS platform (Online › Get started) and sent as `Authorization: Bearer` on
every call — there is no token request. Saving the settings proves the key by reading a payment that does
not exist (MPS answers 404 to a key it accepts). Without an API key the module is not configured: the
option is not offered, and the settings page and Module Manager say an API key is needed. Client IDs and
secrets are not used since 1.0.0; upgrading deletes a shop's stored client ID, secret and cached tokens
(`upgrade/upgrade-1.0.0.php`), so a shop set up with them needs an API key.

An API key starting `mps_test_` is a test key. It is simulated: the MPS checkout shows a test-mode banner and
accepts only the MPS test cards, no card is charged and nothing is paid out, and orders move exactly as
they will live. It works until it is replaced or revoked; the module says so when the key is saved.

## Refunds

PrestaShop has no online refund for payment modules; it makes a **credit slip**, and calls
`actionOrderSlipAdd` once it is saved — for a **partial refund**, a **standard refund** and a **product
return** alike (PrestaShop 8 and 9, `OrderSlipCreator`). For an order paid with MPS the module refunds the
slip's amount on the order's MPS payment (`MpsPaymentsRefunder`):

- `POST /api/payments/{paymentReference}/refunds` — the payment the order was made from (its payment
  transaction ID), encoded as a path segment — with `amount` in integer cents and `reason`
  `requested_by_customer`, and an `Idempotency-Key` of `prestashop-slip-<id_order_slip>-<paymentReference>`,
  so a resent request never refunds twice. MPS answers `201` with the refund, at
  `/api/payments/{paymentReference}/refunds/{refundId}`.
- The amount is what the shopper paid back, tax included: the slip's `amount` plus `shipping_cost_amount`
  (a chosen amount, or the products less a deducted voucher, included). For a customer group shown prices
  tax excluded the slip's tax-included totals are used; with a chosen amount or a deducted voucher there
  the slip has no tax-included figure, so nothing is sent and the note says to refund in MPS.
- **Generate a voucher** ticked on the refund form: the voucher is the refund, nothing is sent to MPS.
- What MPS answered goes in a **private order message** (the order page's Messages), in a message at the
  top of the order page, and — for anything but an accepted refund — in Advanced Parameters › Logs
  whatever the debug setting:
  - `pending`: accepted. Every refund — live or test, whatever the payment method — is held 4 hours, until
    its `cancelableUntil`, and can be canceled in MPS until then; then it is sent. The final outcome is in
    MPS; the module does not read it back.
  - `refunded`: paid back (a test key's refund reads `refunded` once its hold has passed).
  - `failed` or `canceled`, `400` (MPS's reason, e.g. more than is left), `403` (the key may not refund),
    `404` (MPS knows no such payment for this key: a test key and a live payment, or the reverse), `409`
    (not executed yet, or busy): nothing was paid back.
  - No answer, or any other status: it may or may not have been refunded — check in MPS before refunding
    again.

The slip is PrestaShop's: it stays whatever MPS answers. A refused refund is made again in MPS, not by a
second slip (that would credit the order twice).

**The API key needs the `payments:refund` scope.** A test key always holds it. A live key holds it only
once MPS granted it to that key — ask MPS; without it every refund answers `403` and the module says so.

## Build and publish

Releases go through CD - MPS (`src/Pipelines/cd.yml`, parameter `deploy_plugins`): it builds and tests
the plugins, and after the Production approval publishes them to github.com/mps-emi/plugins, whose
Release workflow releases each plugin whose version is new as `<plugin>-v<version>` and
`<plugin>-latest` — the address the docs link to (`../README.md`). CI - MPS refuses a pull request that
changes `mpspayments/` without raising the version and its changelog entry
(`../check-plugin-versions.sh`), so every merged change is a release.

```bash
./build.sh      # what the release runs, to try it locally
```

PrestaShop has no self-update for modules from outside its marketplace: a merchant
updates by uploading the new zip in Module Manager, which runs the upgrade.

The zip holds an `index.php` in every folder and the AFL-3.0 header on every `.php` and `.tpl`, as the
PrestaShop Addons validator requires. Still to do for an Addons listing: the `module_key` (issued by the
Addons seller account, then set in the constructor) and the merchant PDF.

Before releasing: bump `$this->version` in `mpspayments/mpspayments.php` and add a `## <version>` entry
to `CHANGELOG.md` (the build refuses a version without one). When strings change, add them to `i18n.py`;
the build regenerates the XLIFF files and fails on a string without its translations.

## Run it locally

```bash
MPS_API_KEY=mps_test_… ./dev/up.sh http://host.containers.internal:<gateway-port>   # local Aspire gateway
MPS_API_KEY=mps_test_… ./dev/up.sh https://gateway.mps-emi.dev                     # staging
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
| `MPS_PAYMENTS_API_KEY` | module settings | string (secret) | Yes | — | API key, sent as the bearer on every call; the only credential. Refunding a credit slip needs the key to hold `payments:refund`: a test key always does, a live key once MPS granted it. A `mps_test_` prefix marks a test key: MPS test cards only, nothing charged, valid until it is replaced or revoked. Checked on save by reading a payment that does not exist (404 = accepted); never shown again (an empty field on save keeps it). Without it the module is not configured: the option is not offered, and the settings page and Module Manager ask for a key. Never logged. | `mps_test_abcdef123456_…` |
| `MPS_PAYMENTS_WEBHOOK_SECRET` | module settings | string | No | empty | Signing secret, shown with the key in the MPS platform (Online › Get started); every key, test or live, has one. When set, notifications with a missing or non-matching `X-Mps-Signature` are refused with 401. When empty, every notification is answered 200 and ignored, and the shop is settled by the return and the scheduled checks only. | `whsec_…` |
| `MPS_PAYMENTS_GATEWAY_URL` | module settings | string | No | `https://gateway.mps-emi.com` | Gateway the module calls for payments and refunds. Only an absolute `https://` URL is saved, or empty for the default; anything else shows an error and keeps the stored value. | `https://gateway.mps-emi.dev` |
| `_PS_MPS_PAYMENTS_GATEWAY_URL_` | PHP constant in `config/defines_custom.inc.php` | string | No | unset | Overrides `MPS_PAYMENTS_GATEWAY_URL` (the field turns read-only), for development against a local or staging gateway. | `http://host.containers.internal:7280` |
| `MPS_PAYMENTS_DEBUG` | module settings | bool | No | `0` | Log every call to Advanced Parameters › Logs (object type `MpsPayments`): outgoing (method, path, status, duration, payment reference and state) and incoming (returns, webhooks). Never the API key, secrets, `checkoutUrl` or the return URLs. | `1` |
| `MPS_PAYMENTS_TITLE` | module settings | string | No | empty → "Pay with MPS", translated | The option's name at checkout. | `MPS` |
| `MPS_PAYMENTS_DESCRIPTION` | module settings | string | No | empty → a standard text, translated | Shown under the title at checkout. | `Pay on the MPS page.` |
| `MPS_PAYMENTS_CRON_TOKEN` | generated on install | string | — | random | The secret in the scheduled-check URL; a call without it gets 403. | — |
| `PS_PORT` | env var for `dev/*.sh` | int | No | `8090` | Port of the dev shop. | `8091` |
| `PS_VERSION` | env var for `dev/up.sh` | string | No | `9` | PrestaShop image tag. | `8` |
| `MODULE_FROM` | env var for `dev/up.sh` | `source` or `zip` | No | `source` | Mount the module from source, or install `dist/mpspayments.zip`. | `zip` |
| `MPS_API_KEY` | env var for `dev/up.sh` | string | No | unset (option not offered) | API key the dev shop is configured with. | `mps_test_…` |
| `CONTAINER_CLI` | env var for `build.sh` and `dev/*.sh` | string | No | `docker`, else `podman` | Container CLI for the lint, the tests and the dev shop. | `podman` |
