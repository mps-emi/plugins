# MPS Payments for Magento

A Magento 2 payment method (`Mps_Payments`) that sends the shopper to the MPS-hosted checkout and settles
the order from the payment as the gateway reports it — the same flow as the WooCommerce plugin
(`../woocommerce`), in Magento's shape. Merchant-facing install steps are on the docs site
(`src/Mps.Payments.Docs/content/docs/magento.mdx`).

| Path | What lives there |
|---|---|
| `mps-payments/` | The module, exactly as it is zipped. Plain PHP, XML and one Knockout renderer — no build step to run it. |
| `mps-payments/etc/di.xml` | The method: Magento's gateway `Adapter` with an `initialize` and a `refund` command, the EUR/credentials/country validators, the debug logger. |
| `mps-payments/Model/Api.php` | The two internet-payment calls and the refund call, with the API key as the bearer — the only credential; no token is fetched. |
| `mps-payments/Model/PaymentStarter.php` | Opens the payment for a placed order. |
| `mps-payments/Model/Settlement.php` | Reads the payment and moves the order — the one place an order changes. |
| `mps-payments/Gateway/Command/RefundCommand.php`, `Model/Refunder.php`, `Model/Refund.php` | A credit memo refunded online: the refund call, what MPS's answer means, the merchant's message when it refuses. |
| `mps-payments/Controller/Checkout/` | `redirect` (after place order), `return` (`ReturnAction`), `webhook`. |
| `mps-payments/Cron/CheckPendingPayments.php` | The scheduled reads. |
| `mps-payments/Block/Info.php` | The payment on the order: the MPS payment reference (`paymentInfoKeys`), labelled. |
| `mps-payments/Model/System/MissingApiKey.php` | The admin system message (every admin page) while MPS is enabled for a store without an API key (`etc/adminhtml/di.xml`). |
| `mps-payments/Setup/Patch/Data/RemoveClientCredentials.php` | 1.0.0's upgrade: deletes a stored `client_id` / `client_secret` in every scope, and the tokens they bought from the cache. |
| `mps-payments/Model/Signature.php`, `CallbackUrl.php`, `Outcome.php`, `Notification.php`, `Refund.php` | The pure rules, with no Magento dependency. |
| `mps-payments/i18n/` | nl_NL, nl_BE, fr_FR, fr_BE. English is the source. |
| `tests/run.php` | The pure rules, the gateway calls and the refund outcomes, tested without Magento (`tests/magento.php` stands in for the few Magento classes they touch). |
| `build.sh` | Lint, tests, writes `dist/`. |
| `dev/` | A throwaway Magento 2.4.9 / PHP 8.5 shop with the module mounted from source (`up.sh`), and the Marketplace review rehearsed per Magento / PHP pair (`check.sh`, `matrix.sh`, `qa.php`, `browser/`, `fake-gateway.php`). |
| `marketplace/` | What the Commerce Marketplace submission uploads besides the zip: user guide, icon, listing copy (`listing.md`), and `build.sh` to render them. |

## How an order is settled

1. **Place** — the order is placed in *Pending Payment* (`InitializeCommand`), without the confirmation
   e-mail. The checkout then sends the browser to `mpspayments/checkout/redirect`, which opens an internet
   payment with `amount` (cents), `reference` (the order number), `successUrl`/`failureUrl` (both the
   module's return handler, carrying the order's protect code) and, when the shop is publicly reachable
   over HTTPS, `callbackUrl`. The `paymentReference` is kept on the order payment (additional information,
   shown with the payment on the order, and as its last transaction id, which the scheduled checks look
   for). One payment per order: if it cannot be opened, the order is cancelled and the cart restored.
   **Idempotency-Key** `magento-<order number>-<32 random hex>` (`PaymentInfo::idempotencyKey`): made by
   `InitializeCommand` when the order is placed and saved with it (`mps_idempotency_key` in the payment's
   additional information), so every request to open that order's payment sends the same key with the same
   body — the redirect asked for twice (both requests get the one payment), and one resend a second after a
   409 (MPS still opening the first). The order is the attempt: a
   new order after a failed or abandoned payment has a new key. The random part keeps two shops on one MPS
   key apart. The key is logged with the opened payment in the debug log.
2. **Return, webhook and scheduled check** all call `Settlement::settle`: read
   `GET /api/payments/internet/{paymentReference}` and act on `state`, under a per-order lock with the order
   re-read inside it, so a return and a webhook arriving together invoice once. Which URL the shopper came
   back on only decides what they see. A payment read back in another currency than EUR, or with a
   `reference` other than the order number, moves nothing (logged as *left as it is*).
   A webhook is only acted on when a **webhook signing secret** is configured and its `X-Mps-Signature`
   matches (otherwise 401). Without a secret the module ignores notifications — answered 200, nothing
   read, *Webhook ignored: no signing secret configured* in the debug log — and settles by the shopper's
   return and the scheduled checks; paste the secret to have orders confirmed the moment MPS is paid.
   The order is found by the notification's `merchantReference` (the order number, indexed) and taken
   only when the MPS payment reference stored on it is the notification's `paymentReference`; a
   notification without `merchantReference` falls back to the order payment's last transaction id.
3. `executed` with the full amount → invoiced (`registerCaptureNotification`, transaction id = the
   payment reference), *Processing*, and the confirmation e-mail goes out. `executed` with less (a partial
   approval) → *On Hold* with a comment. `failed` or `abandoned` → *Canceled* with a comment, and a
   returning shopper gets their cart back. Anything else is pending.

Unlike WooCommerce, a Magento order is not paid again after a failed attempt: the shopper places a new
order from the restored cart. That is why `abandoned` cancels here where WooCommerce leaves the order payable.

The scheduled checks (Magento cron, every 5 minutes; each order at 10 min, 30 min, 1 h and 6 h after its
payment was opened) are what settle an order on a shop that cannot receive webhooks — localhost, or a
private network — when the shopper closes the tab. Magento itself cancels orders left in *Pending Payment*
after **Pending Payment Order Lifetime** (8 h by default), after the last check.

The module connects with an **API key** (`mps_live_…`, or `mps_test_…` for a test key), pasted with the
webhook signing secret from the MPS platform (Online › Get started), stored encrypted, and sent as
`Authorization: Bearer` on every call — there is no token request, and no other credential. Saving the settings
proves the key (`Observer/VerifyCredentials`) by reading a payment that does not exist (MPS answers 404 to a key
it accepts).

Before 1.0.0 a shop could instead connect with a client ID and secret, which bought a token from
`/connect/token`. 1.0.0 dropped them: its data patch (`Setup/Patch/Data/RemoveClientCredentials`, run by
`bin/magento setup:upgrade`) deletes `payment/mps_payments/client_id` and `client_secret` from
`core_config_data` in every scope, and removes the tokens they bought from Magento's cache. A store left without
an API key is not configured: the method is not offered at checkout (`AvailabilityValidator`), a refund is
refused with a message asking for a key, and while the method is enabled the admin shows *MPS Payments is
enabled but has no API key* on every page (`Model/System/MissingApiKey`) and when the settings are saved. A
client ID or secret set in `app/etc/env.php` or the environment is not in the database, so the patch cannot
remove it; the module no longer reads it — delete it there.

An API key starting `mps_test_` is a test key: the MPS checkout shows a test-mode banner and accepts only
the MPS test cards — no card is charged and nothing is paid out — while orders still move to paid or
failed exactly as live. It works until it is replaced or revoked; the module says so when the key is saved.

## Refunds (credit memos)

A credit memo created from an MPS invoice offers **Refund** (online) beside **Refund Offline** (`can_refund`,
`can_refund_partial_per_invoice` in `config.xml`). **Refund** runs the method's `refund` command
(`Gateway/Command/RefundCommand`), which calls `POST /api/payments/{paymentReference}/refunds`
(`../../Mps.Payments.Docs/content/docs/refunds.mdx`) for the stored `paymentReference` (URL-encoded as one path
segment) with the body `{"amount": <cents>, "reason": "requested_by_customer"}`: the credit memo's grand total in
cents (the order currency — EUR, what MPS was paid in). The payment is named by the path only, never in the body.
Partial and repeated refunds work; MPS refuses anything over what is left. MPS answers 201 with the refund, at
`/api/payments/{paymentReference}/refunds/{refundId}`.

- **Idempotency-Key** `magento-<paymentReference>-refund-<refunded before, cents>-<amount, cents>`: a credit memo has
  no number before it is saved, so the key is made of what identifies it. Submitting the same credit memo again after
  a timeout sends the same key and MPS answers with the first refund; the next credit memo starts from a higher
  refunded total and gets its own key. After MPS refused one (`failed` / `canceled`), the same key would only return
  that refusal, so a count of refused attempts (Magento cache, a day) numbers the next key (`…-2`).
- **Accepted** — `pending` (or a status the module does not know) and `refunded`: the MPS refund id becomes the refund
  transaction's id (closed when `refunded`), the refund's fields its raw details, the id and status are added to
  `mps_refunds` in the payment's additional information, and the order comment Magento writes ("We refunded … online")
  is prefixed with the status: *being processed*, sent after `cancelableUntil` (UTC) and cancelable in MPS until
  then. Every refund — card or bank transfer, live or test — is held 4 hours before it is sent, so a new refund is
  practically always `pending`; a test key's refund is simulated and reads `refunded` once the window has passed.
- **Refused** — a `LocalizedException`, so Magento saves no credit memo and shows the message: `failed` / `canceled`;
  400 (with the message MPS gives for the field); 401; 403 (the key lacks `payments:refund` — a live key holds it only
  when MPS granted it, a test key always); 404 (unknown for this key — test and live keys do not see each other's
  payments); 409/429 (not paid yet or busy: try again); no answer or 5xx (may have been made: submit again, same key).
  Without an API key in the store's scope nothing is sent and the message asks for one.
- Not done here: reading a `pending` refund back (`GET /api/payments/{paymentReference}/refunds[/{refundId}]`),
  cancelling it from Magento (`DELETE /api/payments/{paymentReference}/refunds/{refundId}`). Both are in the MPS
  platform.

## Requirements

Magento Open Source or Adobe Commerce 2.4.7 – 2.4.9 (or Mage-OS on the same framework), PHP 8.1 – 8.5,
as declared in `mps-payments/composer.json`. The shop's currency must be EUR.

## Build and publish

Releases go through CD - MPS (`src/Pipelines/cd.yml`, parameter `deploy_plugins`): it builds and tests
the plugins, and after the Production approval publishes them to github.com/mps-emi/plugins, whose
Release workflow releases each plugin whose version is new as `<plugin>-v<version>` and
`<plugin>-latest` — the address the docs link to (`../README.md`). CI - MPS refuses a pull request that
changes `mps-payments/` without raising the version and its changelog entry
(`../check-plugin-versions.sh`), so every merged change is a release.

```bash
./build.sh      # what the release runs, to try it locally
```

Magento has no self-update for modules installed from a zip: a merchant updates by installing the new zip
the same way, or by `composer update` when installed as a Composer artifact.

Before releasing: bump `version` in `mps-payments/composer.json` and add a `## <version>` entry to
`CHANGELOG.md` (the build refuses a version without one). When strings change, add them to every file in
`mps-payments/i18n/`.

## Run it locally

```bash
MPS_API_KEY=mps_test_… ./dev/up.sh http://host.containers.internal:<gateway-port>   # local Aspire gateway
MPS_API_KEY=mps_test_… ./dev/up.sh https://gateway.mps-emi.dev                       # staging
./dev/magento.sh cron:run     # the scheduled checks; the container runs no cron
./dev/down.sh
```

## Commerce Marketplace

The module is submitted to the Commerce Marketplace (commercedeveloper.adobe.com) as
`mobilepaymentsolutions/module-payments`: the vendor has to be the partner account's (before 1.0.0 it was
`mps/module-payments`).
`marketplace/listing.md` holds every field of the submission. Before each one:

1. `./build.sh` — lints on PHP 8.1 and 8.5 (the range must not narrow what Magento 2.4.7 allows), tests,
   writes the zip.
2. `./dev/matrix.sh` — what the Marketplace's installation test and Manual QA do, for every pair the
   listing claims: Magento 2.4.7, 2.4.8 and 2.4.9 at their latest patch, on each PHP version each allows.
   Per pair (`dev/check.sh`): Composer install from the zip, production mode (compile and static content),
   Varnish serving the cacheable pages, the Manual QA basics with the module active — category, simple
   product with an image, configurable product, customer, guest and customer checkouts, reorder, wishlist,
   compare, invoice, shipment, credit memo (offline), admin order and reorder — and MPS paid, declined and abandoned
   in a browser, against `dev/fake-gateway.php` (no MPS involved), with the webhook and return-URL checks.
   It needs about 8 GB free in the container VM; logs in `dist/matrix/`.
3. `SHOTS="$PWD/dist/marketplace/screenshots" ./dev/check.sh 2.4.9 8.4` then
   `SUPPORT_EMAIL=… ./marketplace/build.sh` — the gallery screenshots, the icon and the user guide PDF in
   `dist/marketplace/`.

Not covered here, and checked by the Marketplace: its own code sniffer run (run
`phpcs --standard=Magento2` from `magento/magento-coding-standard` on `mps-payments/`; warnings do not
block, errors do), the malware scan, and Adobe Commerce (not Open Source) editions.

## Configuration Settings

Merchant settings live in **Stores › Configuration › Sales › Payment Methods › MPS Payments** (paths
`payment/mps_payments/*` in `core_config_data`). Any of them can also be set with `bin/magento config:set`,
in `app/etc/env.php` (`system/default/payment/mps_payments/...`, which locks the field in the admin), or as
an environment variable `CONFIG__DEFAULT__PAYMENT__MPS_PAYMENTS__<FIELD>`.

| Setting | Source | Type | Required | Default | Description | Example |
|---------|--------|------|----------|---------|-------------|---------|
| `active` | module settings (website) | bool | Yes | `0` | Offer MPS at checkout. | `1` |
| `api_key` | module settings (website), stored encrypted | string | Yes | — | API key, sent as the bearer on every call — the only credential (a `client_id` / `client_secret` from before 1.0.0 is deleted on upgrade and never read). A `mps_test_` prefix marks a test key: valid until it is replaced or revoked, accepts only the MPS test cards, charges no card. Checked on save by reading a payment that does not exist (404 = accepted). Without it the method is not offered, and while `active` is `1` the admin shows a message asking for it. Never logged. | `mps_test_abcdef123456_…` |
| `webhook_secret` | module settings (website), stored encrypted | string | No | empty | Signing secret, shown with every key (test and live) in the MPS platform under **Online › Get started**; a new key has a new one. When set, notifications are acted on only when `X-Mps-Signature` matches (else 401). When empty, notifications are ignored (200) and orders are settled by the return and the scheduled reads only — MPS sends no webhooks for a key without a secret anyway. | `whsec_…` |
| `gateway_url` | module settings (website) | string | No | `https://gateway.mps-emi.com` | Gateway the module calls for payments and refunds. Set it in `env.php` or the environment for development against a local or staging gateway. | `https://gateway.mps-emi.dev` |
| `debug` | module settings (website) | bool | No | `0` | Log every call to `var/log/mps_payments.log`: outgoing (method, path, status, duration, payment reference and state) and incoming (returns, webhooks). Never the API key, secrets, `checkoutUrl` or the return URLs. | `1` |
| `title` | module settings (store view) | string | No | `MPS` | The method's name at checkout. | `MPS` |
| `instructions` | module settings (store view) | string | No | translated default | Shown under the title at checkout. The default is translated; a merchant's own text is shown as typed. | `Pay on the MPS page.` |
| `allowspecific`, `specificcountry` | module settings (website) | bool, country list | No | all countries | Magento's usual billing-country restriction. | `1`, `BE,NL` |
| `sort_order` | module settings (website) | int | No | `10` | Position among the payment methods. | `1` |
| `paymentInfoKeys` | `config.xml` default (not in the admin) | comma-separated list | No | `mps_payment_reference` | Additional-information keys shown with the payment on the order (admin, shopper's order view, e-mails). | `mps_payment_reference` |
| `can_refund` | `config.xml` default (not in the admin) | bool | No | `1` | Offer **Refund** (online, through MPS) on a credit memo created from an MPS invoice. `0` leaves only **Refund Offline**. | `0` |
| `can_refund_partial_per_invoice` | `config.xml` default (not in the admin) | bool | No | `1` | Allow an online credit memo for part of an invoice (and several per invoice). | `0` |
| `MAGENTO_PORT` | env var for `dev/up.sh` | int | No | `8089` | Port of the dev shop. | `8090` |
| `MPS_API_KEY` | env var for `dev/up.sh` | string | No | unset (method left off) | API key the dev shop is configured with. | `mps_test_…` |
| `KEEP` | env var for `dev/check.sh` | bool | No | unset | Leave the checked shop running afterwards (on port 8100 + slot). | `1` |
| `SHOTS` | env var for `dev/check.sh` | path | No | unset | Save the listing screenshots there. | `$PWD/dist/marketplace/screenshots` |
| `REMOVE_IMAGE` | env var for `dev/check.sh` | bool | No | unset (`1` from `dev/matrix.sh`) | Remove the Magento image after the run, for disk space. | `1` |
| `PHP_VERSION`, `MAGENTO_VERSION` | build args of `dev/Containerfile` | string | No | `8.5`, `2.4.9` | The PHP image and the Magento release the shop is built from. | `8.1`, `2.4.7-p10` |
| `FAKE_PUBLIC_URL` | env var for `dev/fake-gateway.php` | URL | No | `http://localhost:9000` | Where the browser reaches the fake gateway; its checkout links start with it. | `http://localhost:9101` |
| `SUPPORT_EMAIL` | env var for `marketplace/build.sh` | string | Yes | — | The support address printed in the user guide. | — |
| `CONTAINER_CLI` | env var for `build.sh` and `dev/*.sh` | string | No | `docker`, else `podman` | Container CLI for the lint, the tests and the dev shop. | `podman` |
