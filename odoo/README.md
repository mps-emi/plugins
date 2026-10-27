# MPS Payments for Odoo

An Odoo 20 payment provider (`payment_mps`) that sends the shopper to the MPS-hosted checkout and
settles the transaction from the payment as the gateway reports it. It is the Odoo counterpart of
`src/sdk/woocommerce` and follows the same rules. Merchant-facing install steps are on the docs site
(`src/Mps.Payments.Docs/content/docs/odoo.mdx`).

| Path | What lives there |
|---|---|
| `payment_mps/` | The addon, exactly as it is zipped. Plain Python and XML — no build step to run it. |
| `payment_mps/models/payment_provider.py` | Settings, the token (cached on the provider), the request hooks, log masking. |
| `payment_mps/models/payment_transaction.py` | Create, the state mapping, the scheduled reads. |
| `payment_mps/controllers/main.py` | The return and webhook routes. |
| `payment_mps/utils.py` | The webhook signature and callback-URL rules, the same as the WooCommerce plugin's. |
| `payment_mps/data/` | The provider and its payment method, and the cron for the scheduled reads. |
| `payment_mps/i18n/` | `payment_mps.pot` and the nl/fr `.po` files; Odoo compiles them itself. |
| `payment_mps/tests/` | Odoo tests: rules, state mapping, token cache, routes, scheduled reads. |
| `test.sh` | Runs the tests in a throwaway Odoo + PostgreSQL pod. |
| `i18n.sh` | Regenerates `payment_mps.pot` from the installed addon. |
| `payment_mps/static/description/` | The Odoo Apps Store page (`index.html`), its banner and screenshots, the icon. |
| `build.sh` | Tests, then writes `dist/`: the zips and `dist/apps-store/`, the repository the Apps Store scans. |
| `dev/up.sh`, `dev/down.sh` | A throwaway Odoo shop with the addon mounted from source. |
| `dev/check.sh` | The merchant's path rehearsed on the built zip: install, settings, pay/decline/leave, webhook, scheduled read, uninstall. |
| `dev/fake-gateway.php`, `dev/browser/` | The stand-in gateway and the Playwright half of `dev/check.sh`. |
| `apps-store/` | The Apps Store banner, the image build, the repository README, `listing.md` (how to publish). |

Odoo 20 only. Its payment framework changed shape in 19/20 (`_record` queues payment data that a cron
applies through `_apply_updates`); an Odoo 17 or 18 build would be a separate branch of this addon.

## How a transaction is settled

1. **Create** — `_get_specific_rendering_values` opens an internet payment with `amount` (cents),
   `reference` (the Odoo transaction reference), `successUrl`/`failureUrl` (both the addon's return
   route, with `ref`) and, when the database's base URL is publicly reachable over HTTPS,
   `callbackUrl`. The `paymentReference` becomes the transaction's `provider_reference`; the shopper
   is redirected to `checkoutUrl`. Odoo opens a new transaction for every attempt.
2. **Return, webhook and scheduled read** all do one thing: read
   `GET /api/payments/internet/{paymentReference}` and hand the answer to `_record`. Which URL the
   shopper came back on only decides what they see — Odoo's `/payment/status` page. A webhook is
   answered after re-reading, so a forged one cannot mark anything paid. Without a webhook signing
   secret on the provider, webhooks are ignored (200, no lookup, no read); with one, a missing or
   non-matching signature is refused with 401.
3. `executed` with the full amount → done. `executed` with less (a partial approval) → pending, with
   a message; the sale order is not confirmed. `failed` and `abandoned` → cancelled; the order stays
   payable. Anything else is pending. Odoo's own amount check turns `executed` in another currency
   into an error.

The scheduled reads (cron *Payment: Read Open MPS Payments*, every 5 minutes, reading each open
transaction at 10 min, 30 min, 1 h and 6 h) are what settle a database that cannot receive webhooks
— localhost, a private network, or no webhook signing secret pasted — when the shopper closes the tab.

A client ID starting `test_` is a test key. It works for 24 hours and accepts only the MPS test cards:
the checkout shows a test-mode banner, no card is charged and nothing is paid out, and transactions
still settle exactly as live. The settings form says so.

Refunds are not supported (`support_refund` stays `none`): refund the payment through MPS.

## Build and publish

Releases go through CD - MPS (`src/Pipelines/cd.yml`, parameter `deploy_plugins`): it builds and tests
the plugins, and after the Production approval publishes them to github.com/mps-emi/plugins, whose
Release workflow releases each plugin whose version is new as `<plugin>-v<version>` and
`<plugin>-latest` — the address the docs link to (`../README.md`). CI - MPS refuses a pull request that
changes `payment_mps/` without raising the version and its changelog entry
(`../check-plugin-versions.sh`), so every merged change is a release.

```bash
./build.sh      # what the release runs, to try it locally
```

Odoo has no update channel for an uploaded addon: a merchant uploads the new zip and upgrades the
module. The Apps Store picks the new version up from the branch `20.0`, which the same Release workflow
pushes (`apps-store/listing.md`).

Before releasing: bump `version` in `payment_mps/__manifest__.py` (Odoo convention:
`20.0.<major>.<minor>.<patch>`) and add a `## <version>` entry to `CHANGELOG.md` — the build refuses a
version without one. When strings change, run `./i18n.sh`, then merge and fill in nl and fr:

```bash
msgmerge -U payment_mps/i18n/nl.po payment_mps/i18n/payment_mps.pot
msgmerge -U payment_mps/i18n/fr.po payment_mps/i18n/payment_mps.pot
```

## Apps Store rehearsal

```bash
./build.sh
SHOTS="$PWD/dist/apps-store/screenshots" ./dev/check.sh   # SHOTS optional; KEEP=1 leaves the shop running
```

`dev/check.sh` runs what a merchant does with the zip on Odoo 20 with eCommerce, against
`dev/fake-gateway.php` (not MPS): unzip into the addons path, install from Apps, uninstall while unused and
install again; save the provider with a test key in the browser and publish it; a shopper pays (transaction
done, order confirmed), is declined (cancelled, back at the payment step with the message) and leaves the MPS
page twice; a webhook with a bad signature is refused (401), a signed one settles the first open payment; a
scheduled read finds the second approved for less and leaves it pending; no addon errors in Odoo's log; and
with payments made, Odoo refuses the uninstall, as it does for every provider. Odoo on port 8300+slot, the
fake gateway on 9300+slot (`./dev/check.sh [slot]`). How the listing is published is in
`apps-store/listing.md`.

## Configuration Settings

Merchant settings live on the provider record: Invoicing/Website › Configuration › Payment Providers ›
MPS (model `payment.provider`, code `mps`).

| Setting | Source | Type | Required | Default | Description | Example |
|---------|--------|------|----------|---------|-------------|---------|
| `mps_client_id` | provider form, Configuration › Credentials | string | Yes (to publish) | — | API client id. A `test_` prefix marks a test key: valid 24 hours, MPS test cards only, nothing charged. | `test_BE0000000000` |
| `mps_client_secret` | provider form, Configuration › Credentials | string | Yes (to publish) | — | Its secret. Saving new credentials fetches a token, and refuses to save if MPS rejects them. | — |
| `mps_webhook_secret` | provider form, Configuration › Advanced | string | No | empty | Signing secret, shown with every key (test or live) in the MPS platform. When set, notifications with a non-matching or missing `X-Mps-Signature`, or older than five minutes, are refused with 401. When empty, notifications are ignored (answered 200, nothing read) and the shop is settled by the return and the scheduled reads. | `whsec_…` |
| `mps_gateway_url` | provider form, Configuration › Advanced | string | No | `https://gateway.mps-emi.com` | Gateway the addon calls for tokens and payments. | `https://gateway.mps-emi.dev` |
| `is_published`, `is_live` | provider form (Odoo's own) | bool | — | `false` | Odoo's standard switches: offered at checkout, and live rather than test mode. MPS behaves the same either way; a test key is what makes a test. | `true` |
| `web.base.url` | Odoo system parameter | string | — | set by Odoo | Base of the return and webhook URLs sent to MPS. `callbackUrl` is only sent when it is `https://` on a public host. | `https://shop.example.com` |
| `ODOO_IMAGE` | env var for `test.sh`, `i18n.sh`, `dev/up.sh`, `dev/check.sh` | string | No | `docker.io/library/odoo:20.0` | Odoo image the scripts run. | `docker.io/library/odoo:20.0-20261015` |
| `CONTAINER_CLI` | env var for the scripts | string | No | `docker`, else `podman` | Container CLI. | `podman` |
| `MPS_CLIENT_ID`, `MPS_CLIENT_SECRET` | env vars for `dev/up.sh` | string | No | unset (provider left unconfigured) | Credentials the dev shop is configured and published with. | `test_BE0000000000` |
| `ODOO_PORT` | env var for `dev/up.sh` | int | No | `8069` | Host port of the dev shop. | `8070` |
| `SHOTS` | env var for `dev/check.sh` | path | No | unset (no screenshots) | Folder the Apps Store screenshots are saved to; `apps-store/build.sh` reads them from `dist/apps-store/screenshots`. | `$PWD/dist/apps-store/screenshots` |
| `KEEP` | env var for `dev/check.sh` | bool | No | unset | Any value leaves the check's shop running afterwards. | `1` |

Every MPS call is logged by the standard Odoo logger under `odoo.addons.payment_mps`, at INFO for a
call that worked and WARNING for a refusal. `checkoutUrl`, the return and callback URLs, tokens and
secrets are masked as `[REDACTED]`.
