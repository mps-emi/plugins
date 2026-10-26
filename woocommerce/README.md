# MPS Payments for WooCommerce

A WooCommerce payment method that sends the shopper to the MPS-hosted checkout and
settles the order from the payment as the gateway reports it. Merchant-facing install steps are on the
docs site (`src/Mps.Payments.Docs/content/docs/woocommerce.mdx`) and in `mps-payments/readme.txt`.

| Path | What lives there |
|---|---|
| `mps-payments/` | The plugin, exactly as it is zipped. Plain PHP and one plain script — no build step to run it. |
| `mps-payments/includes/class-mps-payments-gateway.php` | The payment method: settings, create, return, webhook, scheduled checks. |
| `mps-payments/includes/class-mps-payments-api.php` | Token (cached in a transient) and the two internet-payment calls. |
| `mps-payments/includes/class-mps-payments-blocks.php`, `assets/js/blocks.js` | The block checkout registration. |
| `mps-payments/includes/class-mps-payments-updater.php` | Offers new versions from `info.json`, for copies installed from the zip. Loaded only when `MPS_PAYMENTS_UPDATE_URL` is set; left out of the Marketplace zip. |
| `mps-payments/changelog.txt` | The changelog in WooCommerce Marketplace format; keep it in step with the readme.txt changelog. |
| `mps-payments/languages/` | `mps-payments.pot` and the nl/fr `.po` files; `.mo` files are compiled by `build.sh`. |
| `tests/run.php` | The webhook signature, webhook verdict and callback-URL rules, tested without WordPress. |
| `build.sh` | Tests, compiles translations, writes the zips and `info.json` to `dist/`. |
| `dev/up.sh`, `dev/down.sh` | A throwaway shop on localhost:8088 with the plugin mounted from source, against a real gateway. |
| `dev/lint.sh` | The Marketplace's code tests, rehearsed: WooCommerce-Core phpcs (QIT Security), PHPCompatibilityWP from PHP 7.4 (QIT PHPCompatibility), `php -l` on PHP 7.4. |
| `dev/check.sh`, `dev/matrix.sh` | The Marketplace's install and checkout tests, rehearsed on the Marketplace zip for one WordPress / WooCommerce / PHP combination, or for every combination the listing claims. Against `dev/fake-gateway.php`; `dev/browser/` is the Playwright half, `dev/Containerfile` the web server on the PHP under test. |
| `marketplace/` | The listing: `listing.md` (every field of the Vendor Dashboard), `icon.svg`, and `build.sh`, which writes the icon PNG and checks the gallery is there. |

## How an order is settled

1. **Create** — `process_payment` opens an internet payment with `amount` (cents), `reference` (the
   order number), `successUrl`/`failureUrl` (both the plugin's return handler) and, when the shop is
   publicly reachable over HTTPS, `callbackUrl`. Every attempt's `paymentReference` is kept on the order.
2. **Return, webhook and scheduled check** all do one thing: read
   `GET /api/payments/internet/{paymentReference}` and act on `state`. Which URL the shopper came back on
   only decides what they see. A webhook is acted on only when a webhook signing secret is configured
   and the signature matches (otherwise 401); without a secret the plugin ignores it — answers 200 and
   reads nothing — because MPS sends no webhooks for a key without one. Even a valid one is only a
   prompt to re-read, so it cannot mark anything paid by itself.
3. `executed` with the full amount → paid. `executed` with less (a partial approval) → on hold with a
   note. `failed` → failed. `abandoned` → a note; the order stays payable. Anything else is pending.

The scheduled checks (Action Scheduler, at 10 min, 30 min, 1 h and 6 h) are what settles an order on a
shop that cannot receive webhooks — localhost, a private network, or no signing secret pasted — when
the shopper closes the tab. Paste the secret to get orders confirmed immediately.

A client ID starting `test_` is a test key. It is simulated: the MPS checkout shows a test-mode banner
and accepts only the MPS test cards; no card is charged and nothing is paid out. Orders still move to
paid or failed exactly as live. It works for 24 hours; the plugin says so when the key is saved.

## Build and publish

Releases go through the CD pipeline once it is switched on: `src/Pipelines/cd.yml` builds and publishes
the plugins when its `pluginsEnabled` variable is `'true'` — **off for now**, since the staging service
connection it needs (`WWD01UD-SUB0001`) does not exist yet. It runs this build and uploads `dist/` to
`<plugins base URL>/woocommerce/` on the CDN —
staging first, production after the approval (`templates/publish_plugins.yml`). A version already
published is kept, so bump the version first. The steps below are what it does, for a manual one-off.

```bash
PLUGIN_BASE_URL=https://<cdn-host>/woocommerce ./build.sh
```

Upload `mps-payments-<version>.zip`, `mps-payments.zip` and `info.json` from `dist/` to that folder.
Upload `mps-payments-<version>.zip` before `info.json`: installed copies read `info.json` and download
the zip it names straight away.

The build also writes `mps-payments-marketplace-<version>.zip`, the upload for the WooCommerce
Marketplace. It is the same plugin without `includes/class-mps-payments-updater.php` and with
`MPS_PAYMENTS_UPDATE_URL` left empty, whatever `PLUGIN_BASE_URL` was: Woo.com delivers updates to
Marketplace installs, and the Marketplace rules forbid a second update source. It is not uploaded to
the CDN; submit it in the Woo.com vendor dashboard.

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
| `client_id` | plugin settings | string | Yes | — | API client id. A `test_` prefix marks a test key: simulated, accepts only the MPS test cards, valid 24 hours. | `test_BE0000000000` |
| `client_secret` | plugin settings | string | Yes | — | Its secret. Checked with a token request on save. | — |
| `webhook_secret` | plugin settings | string | No | empty | Webhook signing secret, shown with every key (test and live) in the MPS platform under Online › Get started; a new key has a new one. When set, a notification with a matching `X-Mps-Signature` is acted on and any other is refused with 401. When empty, notifications are ignored (200, nothing read) and orders settle by the return and the scheduled checks only. | `whsec_…` |
| `gateway_url` | plugin settings | string | No | `https://gateway.mps-emi.com` | Gateway the plugin calls for tokens and payments. | `https://gateway.mps-emi.dev` |
| `debug` | plugin settings | bool | No | `no` | Log every call under source `mps-payments`: outgoing (method, path, status, duration, payment reference and state) and incoming (returns, webhooks). Never tokens, secrets, `checkoutUrl` or the return URLs. | `yes` |
| `title`, `description` | plugin settings | string | No | translated defaults | What the shopper sees at checkout. | `MPS` |
| `MPS_PAYMENTS_GATEWAY_URL` | PHP constant in `wp-config.php` | string | No | unset | Overrides `gateway_url`, for development against a local or staging gateway. | `https://host.containers.internal:7280` |
| `MPS_PAYMENTS_UPDATE_URL` | stamped by `build.sh` from env var `PLUGIN_BASE_URL` | string | No | empty (no update checks) | Folder holding `info.json` and the zips. The updater is loaded only when this is set. Empty in a source checkout and in the Marketplace zip. | `https://<cdn-host>/woocommerce` |
| `PLUGIN_BASE_URL` | env var for `build.sh` | string | No | empty | Public HTTPS folder the build is published to; becomes `MPS_PAYMENTS_UPDATE_URL` and the `download_url` in `info.json`. Never stamped into the Marketplace zip. | `https://<cdn-host>/woocommerce` |
| `CONTAINER_CLI` | env var for `build.sh` | string | No | `docker`, else `podman` | Container CLI for the tests and the `.mo` compiler. | `podman` |
