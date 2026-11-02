# Changelog

`build.sh` refuses to build a version without an entry here. The version is the one in
`mps-payments/composer.json`.

## 1.0.0

- **First major release**, and the first released since 0.1.0: it also brings 0.2.0 – 0.3.1, which were never
  released on their own — online refunds from a credit memo (in full or in parts, held 4 hours by MPS and
  cancelable until then), the API key, and an `Idempotency-Key` on every payment and refund. Their entries
  below have the details. What a shop on 0.1.0 has to do: paste an API key, since a client ID and secret no
  longer connect.
- The Composer package is `mobilepaymentsolutions/module-payments` (was `mps/module-payments`), as the Commerce
  Marketplace requires the account's vendor name; it replaces the old one. A shop that installed 0.1.0 with
  Composer runs `composer remove mps/module-payments` and `composer require mobilepaymentsolutions/module-payments`.
  The module (`Mps_Payments`) and its settings are unchanged.
- **Breaking for a shop without an API key:** the module connects with an MPS API key only (`mps_live_…`, or
  `mps_test_…` for a test key). A client ID and secret are no longer accepted, and no token is fetched from MPS
  any more. Upgrading removes a stored client ID and secret from every scope, and the access tokens they bought
  from the cache. A store that had only those is no longer offered at checkout until an API key is pasted; the
  admin says so on every page, and when the settings are saved, until then.
- Refunds use MPS's refund routes under the payment (`POST /api/payments/{paymentReference}/refunds`), with
  `requested_by_customer` as the reason. A credit memo on a store without an API key is refused with a message
  asking for one.

## 0.3.1

- Each payment is opened with an `Idempotency-Key`, made when the order is placed and kept with it, so the
  checkout's redirect asked for twice opens one payment, not two. A new order — after a failed or abandoned
  payment — has its own key.

## 0.3.0

- Online refunds: a credit memo created from an MPS invoice with **Refund** asks MPS to pay its total back — in
  full or in parts, as often as needed — and is saved only when MPS accepts the refund; otherwise Magento shows
  why (key without the `payments:refund` permission, amount over what is left, payment not paid yet, …) and
  nothing is recorded. The MPS refund id is the refund transaction's id; the order comment says whether it is
  refunded or being processed, and when it is sent: MPS holds every refund 4 hours, cancelable in MPS until then.
- Each credit memo is sent with an `Idempotency-Key`, so submitting it again after MPS did not answer never
  refunds twice.
- Refunds need the `payments:refund` permission on the API key: a test key always has it, a live key only when
  MPS granted it.
- Amounts are rounded to the cent before being converted to cents.

## 0.2.0

- Connects with an MPS API key: one value to paste, stored encrypted, sent with every call — no token to
  fetch. A test key starts with `mps_test_`. Saving proves the key with a read MPS answers 404 to.
- A shop set up with a client ID and secret goes on working unchanged; they are used while the API key
  is empty.

## 0.1.0

- First release for Magento Open Source and Adobe Commerce 2.4.7 – 2.4.9 on PHP 8.1 – 8.5: MPS as a
  payment method on the MPS-hosted checkout, settled from the payment as MPS reports it — on return, on
  notification, and by scheduled reads for six hours. Dutch, French and English.
- Notifications are acted on only with a webhook signing secret configured and a matching signature;
  without a secret they are ignored and orders settle by the return and the scheduled reads.
- A notification finds its order by order number (`merchantReference`) and is taken only when the MPS
  payment reference stored on that order matches.
- A payment read back in another currency or for another order number moves nothing.
- The MPS payment reference is shown with the payment on the order.
- Licensed under OSL-3.0 (`LICENSE` in the module).
