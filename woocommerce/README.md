# MPS Payments for WooCommerce

A WooCommerce payment method that sends the shopper to the MPS-hosted checkout,
settles the order from the payment as the gateway reports it, and refunds it from WooCommerce's refund button. Merchant-facing install steps are on the
docs site (`src/Mps.Payments.Docs/content/docs/woocommerce.mdx`) and in `mps-payments/readme.txt`.

| Path | What lives there |
|---|---|
| `mps-payments/` | The plugin, exactly as it is zipped. Plain PHP and one plain script — no build step to run it. |
| `mps-payments/includes/class-mps-payments-gateway.php` | The payment method: settings, create, return, webhook, scheduled checks, refunds, and the upgrade that deletes a pre-1.0.0 client ID and secret. |
| `mps-payments/includes/class-mps-payments-api.php` | The two internet-payment calls and the refund call, with the API key as the bearer. |
| `mps-payments/includes/class-mps-payments-api-exception.php` | A non-2xx answer: the status, and what a 400 said was wrong, field by field. |
| `mps-payments/includes/class-mps-payments-blocks.php`, `assets/js/blocks.js` | The block checkout registration. |
| `mps-payments/includes/class-mps-payments-updater.php` | Offers new versions from `info.json`, for copies installed from the zip. Loaded only when `MPS_PAYMENTS_UPDATE_URL` is set; left out of the Marketplace zip. |
| `mps-payments/changelog.txt` | The changelog in WooCommerce Marketplace format; keep it in step with the readme.txt changelog. |
| `mps-payments/languages/` | `mps-payments.pot` and the nl/fr `.po` files; `.mo` files are compiled by `build.sh`. |
| `tests/run.php` | The webhook signature, webhook verdict and callback-URL rules, the API key (the only credential; the not-configured message, the upgrade's clean-up of a client ID and secret), the create's idempotency key (sent, resent for the same attempt, new for a new one), refunds (the request built, the cents, the idempotency key, every outcome) and the refund webhooks, tested without WordPress: `php tests/run.php`, or `./build.sh`, which runs it in a php:cli container. |
| `build.sh` | Tests, compiles translations, writes the zips and `info.json` to `dist/`. |
| `dev/up.sh`, `dev/down.sh` | A throwaway shop on localhost:8088 with the plugin mounted from source, against a real gateway. |
| `dev/lint.sh` | The Marketplace's code tests, rehearsed: WooCommerce-Core phpcs (QIT Security), PHPCompatibilityWP from PHP 7.4 (QIT PHPCompatibility), `php -l` on PHP 7.4. |
| `dev/check.sh`, `dev/matrix.sh` | The Marketplace's install and checkout tests, rehearsed on the Marketplace zip for one WordPress / WooCommerce / PHP combination, or for every combination the listing claims. Against `dev/fake-gateway.php`; `dev/browser/` is the Playwright half, `dev/Containerfile` the web server on the PHP under test. |
| `marketplace/` | The listing: `listing.md` (every field of the Vendor Dashboard), `icon.svg`, and `build.sh`, which writes the icon PNG and checks the gallery is there. |

## How an order is settled

1. **Create** — `process_payment` opens an internet payment with `amount` (cents), `reference` (the
   order number), `successUrl`/`failureUrl` (both the plugin's return handler) and, when the shop is
   publicly reachable over HTTPS, `callbackUrl`. Every attempt's `paymentReference` is kept on the order.
   `Idempotency-Key` is `wc-<order id>-payment-<cents>-<uuid>`, saved on the order (meta
   `_mps_payment_idempotency_key`) before the call and dropped once MPS answers with a payment. So the
   same key, with the same body, goes out again for a resend of the same attempt — a double-submitted
   checkout, or placing the order again after no answer, a 5xx or a 409 — and MPS hands back the payment it
   made. A new key is made when the order is paid for again after a payment was recorded (it failed or the
   shopper left it), when the total changed (the cents are in the key), and after a 4xx refusal other than
   409 (nothing was opened, and a key MPS refused would otherwise refuse the order's next attempt too). The
   plugin does not retry a create by itself.
2. **Return, webhook and scheduled check** all do one thing: read
   `GET /api/payments/internet/{paymentReference}` and act on `state`. Which URL the shopper came back on
   only decides what they see. A webhook is acted on only when a webhook signing secret is configured
   and the signature matches (otherwise 401); without a secret the plugin ignores it — answers 200 and
   reads nothing — because MPS sends no webhooks for a key without one. Even a valid one is only a
   prompt to re-read, so it cannot mark anything paid by itself. Refund events are the exception — see
   *How a refund is made*.
3. `executed` with the full amount → paid. `executed` with less (a partial approval) → on hold with a
   note. `failed` → failed. `abandoned` → a note; the order stays payable. Anything else is pending.

## How a refund is made

WooCommerce's **Refund via MPS Payments** button calls `process_refund`, which sends
`POST /api/payments/{paymentReference}/refunds` for the order's paid payment (WooCommerce's transaction id, or
the current attempt for an order on hold after a partial approval; URL-encoded as one path segment), with
`{ "amount": <cents>, "reason": "requested_by_customer" }` — the cents converted from the decimal string, never
through a float multiplication; the merchant's own reason stays in WooCommerce. The payment is named by the path
only, never in the body. The MPS refund id is kept on the WooCommerce refund and
on the order (meta `_mps_refund_id`), and an order note says what happened:

- `refunded` → done. `pending` → accepted, *being processed*. MPS holds every refund — card or bank
  transfer, live or test — for 4 hours before sending it; when the answer carries `cancelableUntil` the
  note says it is sent after that time and can be canceled in MPS until then. A status the plugin
  does not know is treated as pending.
- `failed`, 400 (with MPS's own words, e.g. more than is left), 403 (the key lacks `payments:refund`), 404
  (not this key's payment: a test key refunds only test payments), 409 (not paid yet, or busy) and no answer
  are refused with a message to the merchant, and WooCommerce deletes its refund.

`Idempotency-Key` is `wc-<order id>-refund-<n>-<cents>`, where `n` counts the order's MPS refunds so far. Not
the WooCommerce refund id: WooCommerce deletes its refund when the gateway answers an error, so a retry after a
timeout gets a new id, while this key stays the same and MPS hands back the refund it already made.

**How it ends** arrives as a signed webhook on the payment's `callbackUrl` (`handle_event`), so only on a
shop that sends one (public HTTPS, signing secret pasted). The event's `refundId` finds the WooCommerce
refund by its `_mps_refund_id`; an unknown one changes nothing. Every refund event is answered 200.

- `refund.refunded` → a note *MPS refund … was paid out*, once: the outcome is kept on the WooCommerce refund
  (`_mps_refund_status`), so a redelivery adds nothing.
- `refund.failed` / `refund.canceled` → nothing was paid back, so the WooCommerce refund is undone the way the
  order screen's *Delete refund* does it: `$refund->delete( true )` and
  `do_action( 'woocommerce_refund_deleted', $refund_id, $order_id )`, which gives the order its refunded total
  back. That restocks nothing, and neither does the plugin. A full refund had set the order to Refunded; it goes
  back to the status kept on the refund when it was made (`_mps_order_status_before_refund`). A note says the
  WooCommerce refund was removed and the amount can be refunded again. Once the refund is gone a redelivery
  finds nothing. The MPS refund id stays in the order's list, so the next refund gets a new idempotency key.

Refunds need the **`payments:refund`** scope on the key. A test key always holds it (its refunds are simulated:
`pending` with a `cancelableUntil` like a live one, `refunded` once the 4 hours have passed, nothing is paid); a live key holds it only when MPS granted it to that key.

The scheduled checks (Action Scheduler, at 10 min, 30 min, 1 h and 6 h) are what settles an order on a
shop that cannot receive webhooks — localhost, a private network, or no signing secret pasted — when
the shopper closes the tab. Paste the secret to get orders confirmed immediately.

The plugin connects with an **API key** (`mps_live_…`, or `mps_test_…` for a test key), pasted with the
webhook signing secret from the MPS platform (Online › Get started) and sent as `Authorization: Bearer` on
every call — there is no token request. Saving the settings proves the key by reading a payment that does not
exist (MPS answers 404 to a key it accepts).

Since 1.0.0 the API key is the only credential. A client ID and secret (what a shop set up before MPS issued
API keys connected with, buying a token from `/connect/token`) no longer connect: the upgrade
(`Mps_Payments_Gateway::forget_client_credentials`, run on `plugins_loaded` because an update activates nothing)
deletes `client_id` and `client_secret` from the settings and the cached `mps_payments_token_*` transients, once.
Without an API key the payment method is not offered at checkout, and while it is enabled every admin screen
shows an error notice asking for the key.

An API key starting `mps_test_` is a test key. It is simulated: the MPS checkout shows a test-mode banner
and accepts only the MPS test cards; no card is charged and nothing is paid out. Orders still move to
paid or failed exactly as live. It works until it is replaced or revoked; the plugin says so when the key is saved.

## Build and publish

Releases go through CD - MPS (`src/Pipelines/cd.yml`, parameter `deploy_plugins`): it builds and tests
the plugins, and after the Production approval publishes them to github.com/mps-emi/plugins, whose
Release workflow releases each plugin whose version is new as `<plugin>-v<version>` and
`<plugin>-latest` — the address the docs link to (`../README.md`). CI - MPS refuses a pull request that
changes `mps-payments/` without raising the version and its `readme.txt` changelog entry
(`../check-plugin-versions.sh`), so every merged change is a release.

```bash
./build.sh      # what the release runs, to try it locally
```

The release is built without `PLUGIN_BASE_URL`, so its zip has no update URL and never loads the updater:
a merchant who installed from the zip updates by uploading the new one (WordPress offers **Replace current
with uploaded**). `PLUGIN_BASE_URL` stamps an update folder in for a copy published somewhere that serves
`info.json` beside the zips; nothing publishes one today.

The build also writes `mps-payments-marketplace-<version>.zip`, the upload for the WooCommerce
Marketplace. It is the same plugin without `includes/class-mps-payments-updater.php` and with
`MPS_PAYMENTS_UPDATE_URL` left empty, whatever `PLUGIN_BASE_URL` was: Woo.com delivers updates to
Marketplace installs, and the Marketplace rules forbid a second update source. It is not released on
GitHub; submit it in the Woo.com vendor dashboard.

Before a Marketplace submission, rehearse what its automated tests (QIT) check, and write the listing files:

```bash
./build.sh && ./dev/lint.sh && ./dev/matrix.sh   # an hour or so; one combination: ./dev/check.sh 7.1.3 11.2.1 8.5
./marketplace/build.sh                           # dist/marketplace/icon.png; the gallery comes from dev/matrix.sh
```

`marketplace/listing.md` says what goes in each field of the listing, and what is still to do (a demo shop,
public documentation, a support address).

Before releasing: bump `Version` in `mps-payments.php` and `MPS_PAYMENTS_VERSION`, `Stable tag` in
`readme.txt` (the build refuses a mismatch), and add a `= <version> =` changelog entry — the build
copies it into `info.json`, which is what the update dialog shows — and the same entry to
`changelog.txt`, which the Marketplace reads. When strings change, regenerate the
template and fill in nl and fr:

```bash
podman run --rm -v "$PWD/mps-payments:/plugin" -w /plugin docker.io/library/wordpress:cli-php8.3 \
  wp i18n make-pot . languages/mps-payments.pot --domain=mps-payments
```

## Configuration Settings

Merchant settings live in WooCommerce › Settings › Payments › MPS Payments (option
`woocommerce_mps_payments_settings`).

| Setting | Source | Type | Required | Default | Description | Example |
|---------|--------|------|----------|---------|-------------|---------|
| `api_key` | plugin settings | string (secret) | Yes | — | API key, sent as the bearer on every call; the only credential (a client ID and secret no longer connect, and are deleted on upgrade to 1.0.0). Without it the payment method is not offered at checkout. Needs the `payments:refund` scope for refunds from WooCommerce: a test key always holds it, a live key only when MPS granted it. A `mps_test_` prefix marks a test key: simulated, accepts only the MPS test cards, valid until it is replaced or revoked. Checked on save by reading a payment that does not exist (404 = accepted). Never logged. | `mps_test_abcdef123456_…` |
| `webhook_secret` | plugin settings | string | No | empty | Webhook signing secret, shown with every key (test and live) in the MPS platform under Online › Get started; a new key has a new one. When set, a notification with a matching `X-Mps-Signature` is acted on and any other is refused with 401 — payments are re-read, refund outcomes note the payout or undo a failed or canceled refund. When empty, notifications are ignored (200, nothing read), orders settle by the return and the scheduled checks only, and a refund's outcome is not reflected in WooCommerce. | `whsec_…` |
| `gateway_url` | plugin settings | string | No | `https://gateway.mps-emi.com` | Gateway the plugin calls for payments and refunds. | `https://gateway.mps-emi.dev` |
| `debug` | plugin settings | bool | No | `no` | Log every call under source `mps-payments`: outgoing (method, path, status, duration, payment reference and state; for a create the amount, reference and idempotency key; for a refund the refund id, status, amount and idempotency key) and incoming (returns, webhooks). Never the API key, secrets, `checkoutUrl` or the return URLs. | `yes` |
| `title`, `description` | plugin settings | string | No | translated defaults | What the shopper sees at checkout. | `MPS` |
| `MPS_PAYMENTS_GATEWAY_URL` | PHP constant in `wp-config.php` | string | No | unset | Overrides `gateway_url`, for development against a local or staging gateway. | `https://host.containers.internal:7280` |
| `MPS_PAYMENTS_UPDATE_URL` | stamped by `build.sh` from env var `PLUGIN_BASE_URL` | string | No | empty (no update checks) | Folder holding `info.json` and the zips. The updater is loaded only when this is set. Empty in a source checkout and in the Marketplace zip. | `https://<cdn-host>/woocommerce` |
| `PLUGIN_BASE_URL` | env var for `build.sh` | string | No | empty | Public HTTPS folder the build is published to; becomes `MPS_PAYMENTS_UPDATE_URL` and the `download_url` in `info.json`. Never stamped into the Marketplace zip. | `https://<cdn-host>/woocommerce` |
| `CONTAINER_CLI` | env var for `build.sh` | string | No | `docker`, else `podman` | Container CLI for the tests and the `.mo` compiler. | `podman` |
