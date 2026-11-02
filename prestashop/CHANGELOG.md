# Changelog

`build.sh` refuses to build a version without an entry here. The version is `$this->version` in
`mpspayments/mpspayments.php`.

## 1.0.0

- **First major release**, and the first released since 0.1.0: it also brings 0.2.0 – 0.3.1, which were never
  released on their own — refunds from a credit slip (partial refund, standard refund or product return; held
  4 hours by MPS and cancelable until then), the API key, and an `Idempotency-Key` on every payment and
  refund. Their entries below have the details. What a shop on 0.1.0 has to do: paste an API key, since a
  client ID and secret no longer connect.
- **Breaking for a shop without an API key:** the module connects with the API key only. Client IDs and
  secrets, and the tokens they bought from `/connect/token`, are no longer used. Upgrading deletes the
  stored `MPS_PAYMENTS_CLIENT_ID`, `MPS_PAYMENTS_CLIENT_SECRET` and every cached `MPS_PAYMENTS_TOKEN_*`
  (`upgrade/upgrade-1.0.0.php`), so no secret is left behind. A shop that had only those is not configured
  until an API key from the MPS platform (Online › Get started) is saved: MPS is not offered at checkout,
  and the settings page and Module Manager say so. A shop with an API key is not affected. The settings
  page has no client ID and secret fields any more.
- Refunds go to MPS's new route, the refund under its payment: `POST /api/payments/{paymentReference}/refunds`
  with only `amount` and `reason` in the body (the payment reference is in the path, URL-encoded). The
  idempotency key and the answers are unchanged.

## 0.3.1

- Opening a payment sends an `Idempotency-Key`, as refunds already did: one per checkout attempt,
  `prestashop-cart-<id_cart>-<random UUID>`, stored with the attempt before MPS is asked. A double-submitted
  checkout, a reload, or a shopper back from a checkout they did not finish resends the attempt with the
  same key and body, and MPS hands back the payment it already opened instead of a second one. A new key is
  made once the last payment failed or was abandoned, the cart total changed, MPS refused the request, or
  the attempt is older than 24 hours. A `409` (the first send still being opened) is sent once more a
  second later. The key is in the debug log.
- Upgrading from 0.3.0 adds the key to `{prefix}mpspayments_payment` and lets its payment reference be
  empty until MPS answered (`upgrade/upgrade-0.3.1.php`).

## 0.3.0

- Refunds from PrestaShop: a credit slip on an MPS order — partial refund, standard refund or product
  return — refunds its amount, tax included, on the order's MPS payment (`POST /api/payments/refunds`, in
  cents, with an idempotency key per slip). What MPS answered is a private order message and a message on
  the order page: accepted (every refund is held 4 hours, until then it can be canceled in MPS), refunded, or
  refused with why — including a live API key without `payments:refund`, which MPS grants on request. A
  slip with a voucher sends nothing.
- The API key needs `payments:refund` to refund; a test key always has it. A shop on a client ID and secret
  asks for a separate refund token, so its payments work as before.
- Upgrading from 0.2.0 registers the refund hook (`upgrade/upgrade-0.3.0.php`).

## 0.2.0

- Connects with an MPS API key: one value to paste, sent with every call — no token to fetch. A test key
  starts with `mps_test_`. Saving proves the key with a read MPS answers 404 to; like the secrets, it is
  never shown again (an empty field on save keeps it).
- A shop set up with a client ID and secret goes on working unchanged; they are used while the API key
  is empty.

## 0.1.0

- First release for PrestaShop 8 and 9: MPS as a payment option on the MPS-hosted checkout. The order
  is made once MPS says the cart was paid — on return, on notification, or by the scheduled checks for six
  hours — so an unpaid attempt leaves the cart as it was. Dutch, French and English.
- Before its first publication: the payment table is `{prefix}mpspayments_payment` (was `{prefix}mps_payment`).
  0.1.0 was never published, so there is no upgrade script.
- Webhooks are ignored (answered 200) when no signing secret is configured; MPS signs every notification.
- Test keys are described as they are: MPS test cards only, nothing charged.
- The order confirmation page shows the order reference and that the payment was received.
- No order is made for a deleted customer, a disabled module, or a cart the module is no longer offered
  for; it is logged instead.
- The gateway URL must be an absolute https URL. Install needs the PHP curl extension.
- Webhook and scheduled checks work in maintenance mode. PrestaShop 8.0 to 9.x. AFL-3.0.
