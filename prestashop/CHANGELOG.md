# Changelog

`build.sh` refuses to build a version without an entry here. The version is `$this->version` in
`mpspayments/mpspayments.php`.

## 0.1.0

- First release for PrestaShop 8 and 9: MPS as a payment option on the MPS-hosted checkout. The order
  is made once MPS says the cart was paid — on return, on notification, or by the scheduled checks for six
  hours — so an unpaid attempt leaves the cart as it was. Dutch, French and English.
- Before its first publication: the payment table is `{prefix}mpspayments_payment` (was `{prefix}mps_payment`).
  0.1.0 was never published, so there is no upgrade script.
- Webhooks are ignored (answered 200) when no signing secret is configured; MPS signs every notification.
- Test keys are described as they are: MPS test cards only, nothing charged, 24 hours.
- The order confirmation page shows the order reference and that the payment was received.
- No order is made for a deleted customer, a disabled module, or a cart the module is no longer offered
  for; it is logged instead.
- The gateway URL must be an absolute https URL. Install needs the PHP curl extension.
- Webhook and scheduled checks work in maintenance mode. PrestaShop 8.0 to 9.x. AFL-3.0.
