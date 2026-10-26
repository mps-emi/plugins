# Changelog

`build.sh` refuses to build a version without an entry here. The version is the one in
`mps-payments/composer.json`.

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
