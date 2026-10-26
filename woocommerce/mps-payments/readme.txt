=== MPS Payments for WooCommerce ===
Contributors: mobilepaymentsolutions
Tags: payments, woocommerce, card, belgium, mps
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Take payments in your WooCommerce shop on the secure checkout page MPS hosts.

== Description ==

Shoppers choose MPS at checkout, pay on the MPS payment page and come back to your shop. Card details never reach your site.

* Works with the block checkout and the classic checkout.
* Try it with a test key from the MPS platform: it works for 24 hours and accepts only the MPS
  test cards. No card is charged and nothing is paid out.
* Orders are confirmed by reading the payment from MPS, never from the return page alone.
* No webhook address to register. With the webhook signing secret pasted and your shop reachable over
  HTTPS, MPS notifies it the moment a payment arrives; otherwise the plugin checks the payment itself.

Refunds are not available from WooCommerce yet. Refund a payment through MPS.

This payment method sends the order amount, order number and your shop's return addresses to MPS
(Mobile Payment Solutions) to open each payment.

== Installation ==

1. Plugins › Add New › Upload Plugin, choose the zip, then Activate.
2. WooCommerce › Settings › Payments › MPS Payments.
3. Paste the client ID, secret and webhook signing secret from the MPS platform (Online › Get started),
   tick Enable, Save.

Saving checks the key with MPS straight away and tells you whether it worked.

The shop currency must be EUR.

== Frequently Asked Questions ==

= How do I test? =

Use a test key: its client ID starts with test_. It works for 24 hours and accepts only the MPS test
cards: no card is charged and nothing is paid out. Orders still move to paid or failed exactly as they
will live. Replace it with your live key when you are ready.

= Do I need to set up a webhook? =

No address to register: the plugin sends its own notification address with every payment. Paste
the webhook signing secret that is shown with your key in the MPS platform under Webhook signing
secret. Without it the plugin ignores notifications and settles orders by its own checks and the
shopper's return, a few minutes later rather than at once. A new key comes with a new secret, so
paste it again when you switch keys.

== Changelog ==

= 0.1.0 =
* First release: payments on the MPS-hosted checkout, block and classic checkout, Dutch, French and
  English.
* Payment notifications are acted on only when they carry a valid signature; without a webhook
  signing secret they are ignored and orders are settled by the plugin's own checks.
* Tested with WordPress 7.0 and 7.1 and WooCommerce 11.2.
