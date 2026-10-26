from odoo.tests import BaseCase, tagged

from odoo.addons.payment_mps import utils

# The worked example from the public docs (internet-payments.mdx, "A notification you can test against").
SECRET = "whsec_EXAMPLE_not_a_real_secret_000000000000"
TIMESTAMP = "1791892905"
SIGNATURE = "v1=bbd5ffb9283e5095b326e21ccf9f102756bd36ffe30a8fba22e38cd984ae7b3a"
BODY = (
    b'{"eventType":"payment.received","paymentId":"98dcf1df-2e04-4684-84cd-d47ec8c23c80",'
    b'"paymentReference":"98dcf1df-2e04-4684-84cd-d47ec8c23c80","merchantReference":"order-1042",'
    b'"amount":100,"currency":"EUR","status":"completed","channel":"internet",'
    b'"valutaDate":"2026-10-13T12:01:42.658852Z","occurredAt":"2026-10-13T12:01:44.0678776Z"}'
)
NOW = 1791892905


@tagged("post_install", "-at_install")
class TestSignature(BaseCase):
    def test_the_documented_example_is_valid(self):
        self.assertTrue(utils.is_valid_signature(SECRET, BODY, TIMESTAMP, SIGNATURE, NOW))

    def test_valid_when_it_is_the_second_of_two_during_a_rotation(self):
        header = "v1=" + "0" * 64 + ", " + SIGNATURE
        self.assertTrue(utils.is_valid_signature(SECRET, BODY, TIMESTAMP, header, NOW))

    def test_a_re_encoded_body_is_refused(self):
        self.assertFalse(utils.is_valid_signature(SECRET, BODY + b"\n", TIMESTAMP, SIGNATURE, NOW))

    def test_a_wrong_secret_is_refused(self):
        self.assertFalse(utils.is_valid_signature("whsec_other", BODY, TIMESTAMP, SIGNATURE, NOW))

    def test_older_than_five_minutes_is_refused(self):
        self.assertFalse(utils.is_valid_signature(SECRET, BODY, TIMESTAMP, SIGNATURE, NOW + 301))

    def test_five_minutes_old_is_accepted(self):
        self.assertTrue(utils.is_valid_signature(SECRET, BODY, TIMESTAMP, SIGNATURE, NOW + 300))

    def test_a_non_numeric_timestamp_is_refused(self):
        self.assertFalse(utils.is_valid_signature(SECRET, BODY, "abc", SIGNATURE, NOW))

    def test_a_missing_header_is_refused(self):
        self.assertFalse(utils.is_valid_signature(SECRET, BODY, TIMESTAMP, "", NOW))
        self.assertFalse(utils.is_valid_signature(SECRET, BODY, None, None, NOW))

    def test_an_unprefixed_value_is_refused(self):
        self.assertFalse(utils.is_valid_signature(SECRET, BODY, TIMESTAMP, SIGNATURE[3:], NOW))


@tagged("post_install", "-at_install")
class TestCallbackUrl(BaseCase):
    def test_a_public_https_host_is_sent(self):
        self.assertTrue(utils.can_receive_webhooks("https://shop.example.com/payment/mps/webhook"))

    def test_a_public_ip_is_sent(self):
        self.assertTrue(utils.can_receive_webhooks("https://8.8.8.8/payment/mps/webhook"))

    def test_http_is_not_sent(self):
        self.assertFalse(utils.can_receive_webhooks("http://shop.example.com/payment/mps/webhook"))

    def test_localhost_is_not_sent(self):
        self.assertFalse(utils.can_receive_webhooks("https://localhost:8443/payment/mps/webhook"))

    def test_a_dotless_name_is_not_sent(self):
        self.assertFalse(utils.can_receive_webhooks("https://odoo:8069/payment/mps/webhook"))

    def test_reserved_suffixes_are_not_sent(self):
        for host in ("shop.localhost", "shop.local", "shop.test", "shop.internal", "shop.lan"):
            with self.subTest(host=host):
                self.assertFalse(utils.can_receive_webhooks(f"https://{host}/payment/mps/webhook"))

    def test_a_private_ip_is_not_sent(self):
        self.assertFalse(utils.can_receive_webhooks("https://192.168.1.20/payment/mps/webhook"))

    def test_loopback_is_not_sent(self):
        self.assertFalse(utils.can_receive_webhooks("https://127.0.0.1/payment/mps/webhook"))

    def test_ipv6_loopback_is_not_sent(self):
        self.assertFalse(utils.can_receive_webhooks("https://[::1]/payment/mps/webhook"))
