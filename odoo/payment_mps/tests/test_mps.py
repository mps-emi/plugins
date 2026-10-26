import json
import logging
import time
from datetime import timedelta
from unittest.mock import MagicMock, patch

from odoo import fields
from odoo.exceptions import ValidationError
from odoo.tests import tagged
from odoo.tools import mute_logger

from odoo.addons.payment.tests.http_common import PaymentHttpCommon
from odoo.addons.payment_mps import utils
from odoo.addons.payment_mps.controllers.main import MpsController
from odoo.addons.payment_mps.tests.common import (
    PAYMENT_REFERENCE,
    SEND_API_REQUEST,
    MpsCommon,
    token_endpoint,
    token_response,
)


@tagged("post_install", "-at_install")
class TestProvider(MpsCommon):
    def test_only_euro_is_supported(self):
        self.assertEqual(self.mps._get_supported_currencies().mapped("name"), ["EUR"])

    def test_refunds_are_not_supported(self):
        self.assertEqual(self.mps.support_refund, "none")

    def test_a_test_key_is_recognised_by_its_prefix(self):
        self.assertFalse(self.mps.mps_is_test_key)
        with token_endpoint():
            self.mps.mps_client_id = "test_BE0000000000"
        self.assertTrue(self.mps.mps_is_test_key)

    def test_saving_credentials_checks_them_with_mps(self):
        with token_endpoint(token_response(token="fresh")) as post:
            self.mps.mps_client_secret = "other"
        post.assert_called_once()
        self.assertEqual(post.call_args.kwargs["data"]["client_secret"], "other")
        self.assertEqual(post.call_args.kwargs["data"]["scope"], "internet:create internet:read")
        self.assertEqual(post.call_args.args[0], "https://gateway.example.com/connect/token")

    def test_refused_credentials_are_not_saved(self):
        with token_endpoint(token_response(status=400)), self.assertRaises(ValidationError):
            self.mps.mps_client_secret = "wrong"

    def test_saving_other_fields_does_not_call_mps(self):
        with token_endpoint() as post:
            self.mps.mps_webhook_secret = "whsec_x"
        post.assert_not_called()

    def test_a_token_is_reused_while_it_lasts(self):
        with token_endpoint() as post:
            self.mps._mps_token()
            self.mps._mps_token()
        post.assert_not_called()  # The one fetched when the credentials were saved

    def test_an_expired_token_is_replaced(self):
        self.mps.mps_token_expiry = fields.Datetime.now() - timedelta(seconds=1)
        with token_endpoint(token_response(token="fresh")) as post:
            self.assertEqual(self.mps._mps_token(), "fresh")
        post.assert_called_once()

    def test_a_token_is_kept_a_minute_less_than_it_lives(self):
        self.mps.mps_token_expiry = False
        before = fields.Datetime.now()
        with token_endpoint(token_response(expires_in=900)):
            self.mps._mps_token()
        self.assertLessEqual(self.mps.mps_token_expiry, before + timedelta(seconds=840 + 1))

    def test_a_rejected_token_is_replaced_once_and_the_call_retried(self):
        rejected = MagicMock(status_code=401, ok=False, text="", reason="Unauthorized", url="u")
        rejected.raise_for_status.side_effect = __import__("requests").exceptions.HTTPError()
        accepted = MagicMock(status_code=200, ok=True, text="{}", reason="OK", url="u")
        accepted.json.return_value = {"data": {"state": "pending"}}
        with (
            patch("odoo.addons.payment.models.payment_provider.requests.request", side_effect=[rejected, accepted]) as request,
            token_endpoint(token_response(token="fresh")) as post,
        ):
            data = self.mps._send_api_request("GET", f"/api/payments/internet/{PAYMENT_REFERENCE}")
        self.assertEqual(data, {"state": "pending"})
        post.assert_called_once()
        self.assertEqual(request.call_count, 2)
        self.assertEqual(request.call_args.kwargs["headers"]["Authorization"], "Bearer fresh")

    def test_the_log_never_carries_the_checkout_url(self):
        response = MagicMock(status_code=200, ok=True, reason="OK", url="https://gateway.example.com/x")
        response.text = json.dumps({"data": {"paymentReference": PAYMENT_REFERENCE, "checkoutUrl": "https://pay/secret"}})
        with self.assertLogs("odoo.addons.payment_mps", level=logging.INFO) as logs:
            self.mps._log_request("POST", "https://gateway.example.com/x", {"amount": 1, "successUrl": "https://shop/?ref=r"})
            self.mps._log_response(response)
        output = "\n".join(logs.output)
        self.assertIn(PAYMENT_REFERENCE, output)
        self.assertNotIn("https://pay/secret", output)
        self.assertNotIn("https://shop/?ref=r", output)


@tagged("post_install", "-at_install")
class TestTransaction(MpsCommon):
    def test_the_payment_is_opened_for_the_amount_in_cents(self):
        tx = self._create_transaction("redirect")
        payload = tx._mps_prepare_payment_payload()
        self.assertEqual(payload["amount"], 111111)
        self.assertEqual(payload["reference"], tx.reference)
        self.assertIn(f"{MpsController._return_url}?ref=", payload["successUrl"])
        self.assertIn("result=success", payload["successUrl"])
        self.assertIn("result=failure", payload["failureUrl"])

    def test_a_shop_mps_cannot_reach_sends_no_callback(self):
        self.env["ir.config_parameter"].sudo().set_str("web.base.url", "http://localhost:8069")
        payload = self._create_transaction("redirect")._mps_prepare_payment_payload()
        self.assertNotIn("callbackUrl", payload)

    def test_a_public_https_shop_sends_its_callback(self):
        self.env["ir.config_parameter"].sudo().set_str("web.base.url", "https://shop.example.com")
        payload = self._create_transaction("redirect")._mps_prepare_payment_payload()
        self.assertEqual(payload["callbackUrl"], f"https://shop.example.com{MpsController._webhook_url}")

    def test_opening_redirects_to_the_checkout_and_keeps_the_payment_reference(self):
        tx = self._create_transaction("redirect")
        opened = {"paymentReference": PAYMENT_REFERENCE, "checkoutUrl": "https://checkout.example.com/p/abc?l=nl"}
        with patch(SEND_API_REQUEST, return_value=opened) as send:
            processing_values = tx._get_processing_values()
        self.assertEqual(send.call_args.args, ("POST", "/api/payments/internet/create"))
        form = self._extract_values_from_html_form(processing_values["redirect_form_html"])
        self.assertEqual(form["action"], "https://checkout.example.com/p/abc?l=nl")
        self.assertEqual(form["inputs"], {"l": "nl"})
        self.assertEqual(tx.provider_reference, PAYMENT_REFERENCE)

    @mute_logger("odoo.addons.payment_mps.models.payment_transaction")
    def test_a_payment_mps_refuses_to_open_puts_the_transaction_in_error(self):
        tx = self._create_transaction("redirect")
        with patch(SEND_API_REQUEST, side_effect=ValidationError("refused")):
            tx._get_processing_values()
        self.assertEqual(tx.state, "error")

    def _processed(self, payment):
        self._disable_process_patcher()
        tx = self._opened_transaction()
        tx.with_context(payment_safe_write=True)._process(payment)
        return tx

    def test_executed_for_the_full_amount_is_done(self):
        self.assertEqual(self._processed(self._payment("executed")).state, "done")

    def test_executed_for_less_stays_pending_with_a_note(self):
        tx = self._processed(self._payment("executed", amount=50000))
        self.assertEqual(tx.state, "pending")
        self.assertIn("500.00", tx.state_message)

    def test_failed_is_cancelled(self):
        self.assertEqual(self._processed(self._payment("failed")).state, "cancel")

    def test_abandoned_is_cancelled(self):
        self.assertEqual(self._processed(self._payment("abandoned")).state, "cancel")

    def test_pending_is_pending(self):
        self.assertEqual(self._processed(self._payment("pending")).state, "pending")

    def test_an_unknown_state_is_pending_never_paid(self):
        self.assertEqual(self._processed(self._payment("settled-somehow")).state, "pending")

    def test_executed_in_another_currency_is_an_error(self):
        self.assertEqual(self._processed(self._payment("executed", currency="USD")).state, "error")


@tagged("post_install", "-at_install")
class TestScheduledChecks(MpsCommon):
    def _aged(self, tx, minutes):
        self.env.cr.execute(
            "UPDATE payment_transaction SET create_date = %s WHERE id = %s",
            (fields.Datetime.now() - timedelta(minutes=minutes), tx.id),
        )
        tx.invalidate_recordset(["create_date"])
        return tx

    def _check(self):
        with patch(SEND_API_REQUEST, return_value=self._payment("pending")) as send:
            self.env["payment.transaction"]._cron_mps_check_payments()
        return send

    def test_a_payment_opened_just_now_is_not_read_yet(self):
        self._aged(self._opened_transaction(state="pending"), 5)
        self._check().assert_not_called()

    def test_a_payment_opened_ten_minutes_ago_is_read(self):
        tx = self._aged(self._opened_transaction(state="pending"), 11)
        send = self._check()
        self.assertEqual(send.call_args.args, ("GET", f"/api/payments/internet/{PAYMENT_REFERENCE}"))
        self.assertEqual(tx.mps_check_count, 1)
        self.assertTrue(self.env["payment.data"].search([("transaction_id", "=", tx.id)]))

    def test_each_delay_reads_once(self):
        tx = self._aged(self._opened_transaction(state="pending"), 11)
        self._check()
        self._check().assert_not_called()
        self.assertEqual(tx.mps_check_count, 1)

    def test_a_late_run_reads_once_for_every_delay_it_passed(self):
        tx = self._aged(self._opened_transaction(state="pending"), 90)
        self.assertEqual(self._check().call_count, 1)
        self.assertEqual(tx.mps_check_count, 3)

    def test_after_the_last_delay_it_stops(self):
        tx = self._aged(self._opened_transaction(state="pending"), 400)
        self._check()
        self.assertEqual(tx.mps_check_count, 4)
        self._check().assert_not_called()

    def test_a_settled_transaction_is_not_read(self):
        self._aged(self._opened_transaction(state="done"), 11)
        self._check().assert_not_called()

    @mute_logger("odoo.addons.payment_mps.models.payment_transaction")
    def test_mps_unreachable_does_not_stop_the_others(self):
        first = self._aged(self._opened_transaction(state="pending", reference="first"), 11)
        second = self._aged(self._opened_transaction(state="pending", reference="second"), 11)
        with patch(SEND_API_REQUEST, side_effect=[ValidationError("down"), self._payment("pending")]):
            self.env["payment.transaction"]._cron_mps_check_payments()
        self.assertEqual((first | second).mapped("mps_check_count"), [1, 1])


@tagged("post_install", "-at_install")
class TestControllers(MpsCommon, PaymentHttpCommon):
    def _post_webhook(self, body, headers=None):
        return self.url_open(
            self._build_url(MpsController._webhook_url),
            data=body,
            headers={"Content-Type": "application/json", **(headers or {})},
            method="POST",
        )

    def _event(self, payment_reference=PAYMENT_REFERENCE):
        return json.dumps({"eventType": "payment.received", "paymentReference": payment_reference}).encode()

    def _signed(self, body, secret="whsec_test"):
        timestamp = str(int(time.time()))
        signature = utils.sign(secret, timestamp, body)
        return {"X-Mps-Timestamp": timestamp, "X-Mps-Signature": f"v1={signature}"}

    @mute_logger("odoo.addons.payment_mps.controllers.main")
    def test_a_notification_without_a_secret_is_acknowledged_and_ignored(self):
        self._opened_transaction()
        with (
            patch.object(MpsController, "_find_transaction") as find,
            patch(SEND_API_REQUEST) as send,
        ):
            response = self._post_webhook(self._event())
        self.assertEqual(response.status_code, 200)
        find.assert_not_called()
        send.assert_not_called()

    @mute_logger("odoo.addons.payment_mps.controllers.main")
    def test_a_notification_for_a_provider_without_a_secret_is_ignored(self):
        self.mps.copy().mps_webhook_secret = "whsec_other"
        self._opened_transaction()
        with patch(SEND_API_REQUEST) as send:
            response = self._post_webhook(self._event())
        self.assertEqual(response.status_code, 200)
        send.assert_not_called()

    @mute_logger("odoo.addons.payment_mps.controllers.main")
    def test_a_notification_is_answered_by_reading_the_payment(self):
        self._disable_process_patcher()
        self.mps.mps_webhook_secret = "whsec_test"
        tx = self._opened_transaction()
        body = self._event()
        with patch(SEND_API_REQUEST, return_value=self._payment("executed")) as send:
            response = self._post_webhook(body, self._signed(body))
        self.assertEqual(response.status_code, 200)
        self.assertEqual(send.call_args.args, ("GET", f"/api/payments/internet/{PAYMENT_REFERENCE}"))
        self._run_processing()
        self.assertEqual(tx.state, "done")

    @mute_logger("odoo.addons.payment_mps.controllers.main")
    def test_a_signed_notification_is_accepted(self):
        self.mps.mps_webhook_secret = "whsec_test"
        self._opened_transaction()
        body = self._event()
        with patch(SEND_API_REQUEST, return_value=self._payment("pending")) as send:
            response = self._post_webhook(body, self._signed(body))
        self.assertEqual(response.status_code, 200)
        send.assert_called_once()

    @mute_logger("odoo.addons.payment_mps.controllers.main")
    def test_a_badly_signed_notification_is_refused_without_reading(self):
        self.mps.mps_webhook_secret = "whsec_test"
        self._opened_transaction()
        body = self._event()
        with patch(SEND_API_REQUEST) as send:
            response = self._post_webhook(body, self._signed(body, secret="whsec_other"))
        self.assertEqual(response.status_code, 401)
        send.assert_not_called()

    @mute_logger("odoo.addons.payment_mps.controllers.main")
    def test_an_unsigned_notification_is_refused_when_a_secret_is_set(self):
        self.mps.mps_webhook_secret = "whsec_test"
        self._opened_transaction()
        with patch(SEND_API_REQUEST) as send:
            response = self._post_webhook(self._event())
        self.assertEqual(response.status_code, 401)
        send.assert_not_called()

    @mute_logger("odoo.addons.payment_mps.controllers.main")
    def test_a_payment_of_another_shop_is_acknowledged_and_ignored(self):
        self.mps.mps_webhook_secret = "whsec_test"
        body = self._event("00000000-0000-0000-0000-000000000000")
        with patch(SEND_API_REQUEST) as send:
            response = self._post_webhook(body, self._signed(body))
        self.assertEqual(response.status_code, 200)
        send.assert_not_called()

    @mute_logger("odoo.addons.payment_mps.controllers.main")
    def test_a_notification_mps_cannot_confirm_is_retried(self):
        self.mps.mps_webhook_secret = "whsec_test"
        self._opened_transaction()
        body = self._event()
        with patch(SEND_API_REQUEST, side_effect=ValidationError("down")):
            response = self._post_webhook(body, self._signed(body))
        self.assertEqual(response.status_code, 503)

    @mute_logger("odoo.addons.payment_mps.controllers.main")
    def test_the_return_reads_the_payment_and_shows_the_status_page(self):
        tx = self._opened_transaction()
        with patch(SEND_API_REQUEST, return_value=self._payment("executed")) as send:
            response = self._make_http_get_request(
                self._build_url(MpsController._return_url), params={"ref": tx.reference, "result": "success"}
            )
        send.assert_called_once()
        self.assertTrue(response.url.endswith("/payment/status"))

    @mute_logger("odoo.addons.payment_mps.controllers.main")
    def test_the_return_shows_the_status_page_when_mps_is_unreachable(self):
        tx = self._opened_transaction()
        with patch(SEND_API_REQUEST, side_effect=ValidationError("down")):
            response = self._make_http_get_request(
                self._build_url(MpsController._return_url), params={"ref": tx.reference}
            )
        self.assertTrue(response.url.endswith("/payment/status"))
