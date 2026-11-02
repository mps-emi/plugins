# Changelog

`build.sh` refuses to build a version without an entry here.

## 20.0.2.0.0

- **First major release**, and the first released since 20.0.1.0.0: it also brings 20.0.1.1.0 – 20.0.1.2.1,
  which were never released on their own — refunds from Odoo's Refund button (in full or in parts, held 4
  hours by MPS and cancelable until then), the API key, and an `Idempotency-Key` on every payment and refund.
  Their entries below have the details. What a provider on 20.0.1.0.0 has to do: paste an API key, since a
  client ID and secret no longer connect.
- **Breaking for a provider without an API key:** the addon connects with the API key (`mps_api_key`,
  `mps_live_…` or `mps_test_…`) only. The client ID and secret of a setup made before MPS issued API keys,
  and the token they bought from `/connect/token`, are gone: the fields, the token request and its cache.
  Upgrading erases any stored client ID, secret and token (`migrations/20.0.2.0.0/pre-migrate.py`), and
  takes a live or published MPS provider without an API key off line (not live, not published) — until an
  API key is pasted it cannot take payments. Paste the API key from the MPS platform (Online › Get
  started) before upgrading, or after it and enable the provider again.
- A provider cannot be live or published without an API key; the form says so while it is empty, and
  saving refuses it with that message.
- Refunds are made and read under the payment they refund, as MPS now routes them:
  `POST /api/payments/{paymentReference}/refunds` (body `amount` and `reason`; the payment reference is
  no longer in the body) and `GET /api/payments/{paymentReference}/refunds/{refundId}`, the payment
  reference URL-encoded as one path segment. A refund transaction whose payment reference is unknown is not
  read.
- Fix: Odoo now shows the Refund button on a paid MPS payment. The MPS payment method said it supported no
  refunds, so Odoo hid the button although the provider supports them; it now supports refunds in full and
  in parts, and upgrading marks an installed method so too.

## 20.0.1.2.1

- Opening a payment (`POST /api/payments/internet/create`) sends the transaction's reference as
  `Idempotency-Key`, as refunds already do: a request sent again for the same attempt answers the payment
  it opened instead of opening a second one. Paying again after a failed, cancelled or abandoned payment
  is a new Odoo transaction with a new reference, so it opens a new payment.

## 20.0.1.2.0

- Refunds from Odoo, in full or in parts (`support_refund` = partial): Odoo's Refund button on a confirmed
  MPS transaction calls `POST /api/payments/{paymentReference}/refunds` with the amount in cents and
  `reason` `requested_by_customer`, and the refund transaction's reference as `Idempotency-Key`,
  so a retry never refunds twice. The MPS refund id becomes the refund transaction's provider reference.
- `refunded` → done, `failed` → error, `canceled` → cancelled, `pending` (or anything else) → pending
  with a message. MPS holds every refund (card or bank transfer, live or test key) for 4 hours before
  sending it; the message says until when it can still be canceled in MPS. Pending refunds are read again
  (`GET /api/payments/{paymentReference}/refunds/{refundId}`) by the same scheduled reads as payments; the
  6-hour read comes after the hold.
- A refund MPS refuses is explained, and no refund transaction is kept: 400 shows MPS's reason (such as
  more than is left to refund), 403 that the key lacks `payments:refund` (a production key holds it only
  when MPS granted it, a test key always; a provider still on a client ID and secret needs an API key),
  404 that the payment is unknown for this key, 409 to try again.

## 20.0.1.1.0

- Connects with an MPS API key (`mps_api_key`): one value to paste, sent with every call — no token to
  fetch. A key starting `mps_test_` is shown as a test key. Saving proves the key with a read MPS answers
  404 to, and refuses one MPS does not accept. Like the client secret, it is cleared when a database is
  neutralised.
- A provider set up with a client ID and secret goes on working unchanged; they are used while the API
  key is empty. A live provider needs one or the other.

## 20.0.1.0.0

- First release on the Odoo Apps Store. The module is unchanged from 20.0.0.1.0; the store page now names
  the support address, ict@mps-emi.com.

## 20.0.0.1.0

- First release for Odoo 20: MPS as a payment provider on the MPS-hosted checkout, settled from the
  payment as MPS reports it — on return, on notification, and by scheduled reads for six hours.
- Notifications are ignored while no webhook signing secret is set on the provider.
- The provider form says what is sent to MPS, and that a test key accepts only the MPS test cards and
  charges nothing.
