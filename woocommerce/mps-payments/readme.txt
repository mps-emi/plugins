=== MPS Payments for WooCommerce ===
Contributors: mobilepaymentsolutions
Tags: payments, woocommerce, card, belgium, mps
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Take payments in your WooCommerce shop on the secure checkout page MPS hosts.

== Description ==

Shoppers choose MPS at checkout, pay on the MPS payment page and come back to your shop. Card details never reach your site.

* Works with the block checkout and the classic checkout.
* Try it with a test key from the MPS platform: it accepts only the MPS
  test cards. No card is charged and nothing is paid out.
* Orders are confirmed by reading the payment from MPS, never from the return page alone.
* No webhook address to register. With the webhook signing secret pasted and your shop reachable over
  HTTPS, MPS notifies it the moment a payment arrives; otherwise the plugin checks the payment itself.
* Refund from the order screen, in full or in part: WooCommerce's refund button sends the refund to
  MPS. Your API key needs the payments:refund scope for this. A test key always holds it; a live key
  holds it only when MPS granted it, so ask MPS when you request the key.

This payment method sends the order amount, order number and your shop's return addresses to MPS
(Mobile Payment Solutions) to open each payment.

== Installation ==

1. Plugins › Add New › Upload Plugin, choose the zip, then Activate.
2. WooCommerce › Settings › Payments › MPS Payments.
3. Paste the API key and the webhook signing secret from the MPS platform (Online › Get started), tick
   Enable, Save.

Saving checks the key with MPS straight away and tells you whether it worked.

The shop currency must be EUR.

== Frequently Asked Questions ==

= How do I test? =

Use a test key: it starts with mps_test_. It accepts only the MPS test
cards: no card is charged and nothing is paid out. Orders still move to paid or failed exactly as they
will live. Replace it with your live key when you are ready.

= How do I refund? =

Open the order, click Refund, enter the amount and click Refund via MPS Payments. MPS holds every
refund for 4 hours, during which you can cancel it in MPS, then sends it. The order note names the
MPS refund and says when it is sent. Your API key needs the payments:refund scope: a test key always
has it, a live key only when MPS granted it to that key.

With the webhook signing secret pasted, MPS notifies the shop when the refund is paid out (a note on
the order), or when it failed or was canceled: then nothing was paid back, so the plugin removes the
WooCommerce refund, says so in a note, and the amount can be refunded again. Items restocked with the
refund are not taken out of stock again; adjust the stock yourself if needed.

= Do I need to set up a webhook? =

No address to register: the plugin sends its own notification address with every payment. Paste
the webhook signing secret that is shown with your key in the MPS platform under Webhook signing
secret. Without it the plugin ignores notifications and settles orders by its own checks and the
shopper's return, a few minutes later rather than at once. A new key comes with a new secret, so
paste it again when you switch keys.

== Changelog ==


= 1.0.0 =
* First major release, and the first released since 0.1.0: it also brings 0.2.0 – 0.3.2, which were never
  released on their own: refunds from the order screen (in full or in parts, held 4 hours by MPS and
  cancelable until then), the API key, and an Idempotency-Key on every payment and refund. What a shop on
  0.1.0 has to do: paste an API key, since a client ID and secret no longer connect.
* Breaking for a shop without an API key: the plugin connects with the API key only. The client ID
  and secret of a shop set up before MPS issued API keys no longer connect, and the update deletes
  them, and the tokens bought with them, from the shop. Such a shop is not offered at checkout until
  an API key is pasted; the admin screens say so. Get the API key and the webhook signing secret
  from the MPS platform (Online › Get started).
* Refunds use MPS's refund address under the payment (/api/payments/{paymentReference}/refunds);
  the former address is gone. Nothing changes in WooCommerce.

= 0.3.2 =
* The plugin's own checks of an open payment now run 10 minutes, 30 minutes, 1 hour and 6 hours
  after it was opened, as documented. Each waited the full delay after the previous check, so they
  ran at 10 minutes, 40 minutes, 1 hour 40 and 7 hours 40.

= 0.3.1 =
* Opening a payment sends an Idempotency-Key, one per checkout attempt: a double-submitted checkout,
  or placing the order again after MPS did not answer, gets the payment MPS already opened instead of
  a second one. Paying again after a failed or abandoned payment, or with a changed total, opens a new
  payment.

= 0.3.0 =
* Refunds from WooCommerce: the order screen's refund button refunds the payment through MPS, in full
  or in part. The order note names the MPS refund. MPS holds every refund for 4 hours before sending
  it; it can be canceled in MPS until then, and the note says until when.
* Refunds need the payments:refund scope on the API key: a test key always holds it, a live key only
  when MPS granted it. Without it the refund is refused with a message saying so.
* With the webhook signing secret pasted, MPS tells the shop how each refund ended: paid out is noted
  on the order; failed or canceled removes the WooCommerce refund, puts a fully refunded order back to
  its status before, and the amount can be refunded again.

= 0.2.0 =
* Connects with an MPS API key: one value to paste, sent with every call, no token to fetch. A test
  key starts with mps_test_.
* A shop set up with a client ID and secret goes on working unchanged; they are used while the API key
  is empty.

= 0.1.0 =
* First release: payments on the MPS-hosted checkout, block and classic checkout, Dutch, French and
  English.
* Payment notifications are acted on only when they carry a valid signature; without a webhook
  signing secret they are ignored and orders are settled by the plugin's own checks.
* Tested with WordPress 7.0 and 7.1 and WooCommerce 11.2.
