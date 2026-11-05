import importlib.util
import json
import logging
import time
from datetime import timedelta
from unittest.mock import MagicMock, patch

from odoo import fields
from odoo.exceptions import UserError, ValidationError
from odoo.tests import tagged
from odoo.tools import file_path, mute_logger

from odoo.addons.payment.tests.http_common import PaymentHttpCommon
from odoo.addons.payment_mps import utils
from odoo.addons.payment_mps.controllers.main import MpsController
from odoo.addons.payment_mps.tests.common import (
    API_KEY,
    API_REQUEST,
    PAYMENT_REFERENCE,
    REFUND_ID,
    SEND_API_REQUEST,
    MpsCommon,
    api_key_check,
    gateway_response,
)

# Never sent any more: the token request a client ID and secret made.
TOKEN_REQUEST = "requests.post"


@tagged("post_install", "-at_install")
class TestProvider(MpsCommon):
    def test_only_euro_is_supported(self):
        self.assertEqual(self.mps._get_supported_currencies().mapped("name"), ["EUR"])

    def test_full_and_partial_refunds_are_supported(self):
        self.assertEqual(self.mps.support_refund, "partial")
        # Odoo offers the Refund button only when the payment method supports refunds too.
        self.assertEqual(self.payment_method.support_refund, "partial")

    def test_a_paid_transaction_s_payment_offers_the_refund(self):
        if "payment_id" not in self.env["payment.transaction"]._fields:
            self.skipTest("The Refund button is account_payment's; test.sh installs it.")
        tx = self._opened_transaction(state="done")
        payment = tx.with_context(payment_safe_write=True)._create_payment()
        self.assertEqual(payment.amount_available_for_refund, tx.amount)

    def test_an_idempotency_key_travels_as_a_header(self):
        with patch(API_REQUEST, return_value=gateway_response(201, {"data": {}})) as request:
            self.mps._send_api_request("POST", f"/api/payments/{PAYMENT_REFERENCE}/refunds", json={}, idempotency_key="R-1")
            self.mps._send_api_request("GET", f"/api/payments/internet/{PAYMENT_REFERENCE}")
        first, second = request.call_args_list
        self.assertEqual(first.kwargs["headers"]["Idempotency-Key"], "R-1")
        self.assertNotIn("Idempotency-Key", second.kwargs["headers"])

    def test_an_api_key_starting_mps_test_is_a_test_key(self):
        self.assertTrue(self.mps.mps_is_test_key)

    def test_a_live_api_key_is_not_a_test_key(self):
        with api_key_check():
            self.mps.mps_api_key = "mps_live_abcdef123456_secret"
        self.assertFalse(self.mps.mps_is_test_key)

    def test_there_are_no_client_credentials_or_token_any_more(self):
        for field in ("mps_client_id", "mps_client_secret", "mps_access_token", "mps_token_expiry"):
            with self.subTest(field=field):
                self.assertNotIn(field, self.mps._fields)

    def test_saving_an_api_key_checks_it_with_mps_without_a_token_request(self):
        with api_key_check() as get, patch(TOKEN_REQUEST) as post:
            self.mps.mps_api_key = "mps_live_abcdef123456_secret"
        get.assert_called_once()
        self.assertEqual(get.call_args.args[0], "https://gateway.example.com/api/payments/internet/mps-credential-check")
        self.assertEqual(get.call_args.kwargs["headers"]["Authorization"], "Bearer mps_live_abcdef123456_secret")
        post.assert_not_called()

    def test_a_refused_api_key_is_not_saved(self):
        for status in (401, 403):
            with self.subTest(status=status), api_key_check(status), self.assertRaises(ValidationError):
                self.mps.mps_api_key = "mps_live_abcdef123456_wrong"

    def test_changing_the_gateway_checks_the_api_key_there(self):
        with api_key_check() as get:
            self.mps.mps_gateway_url = "https://gateway.mps-emi.dev"
        self.assertEqual(get.call_args.args[0], "https://gateway.mps-emi.dev/api/payments/internet/mps-credential-check")

    def test_saving_other_fields_does_not_call_mps(self):
        with api_key_check() as get:
            self.mps.mps_webhook_secret = "whsec_x"
        get.assert_not_called()

    def test_the_api_key_is_the_bearer_without_a_token_request(self):
        accepted = MagicMock(status_code=200, ok=True, text="{}", reason="OK", url="u")
        accepted.json.return_value = {"data": {"state": "pending"}}
        with patch(API_REQUEST, return_value=accepted) as request, patch(TOKEN_REQUEST) as post:
            self.mps._send_api_request("GET", f"/api/payments/internet/{PAYMENT_REFERENCE}")
        self.assertEqual(request.call_args.kwargs["headers"]["Authorization"], f"Bearer {API_KEY}")
        post.assert_not_called()

    def test_a_rejected_api_key_is_not_retried(self):
        rejected = MagicMock(status_code=401, ok=False, text="", reason="Unauthorized", url="u")
        rejected.raise_for_status.side_effect = __import__("requests").exceptions.HTTPError()
        with (
            patch(API_REQUEST, return_value=rejected) as request,
            patch(TOKEN_REQUEST) as post,
            self.assertRaises(ValidationError) as refused,
        ):
            self.mps._send_api_request("GET", f"/api/payments/internet/{PAYMENT_REFERENCE}")
        self.assertIn("API key", str(refused.exception))
        self.assertEqual(request.call_count, 1)
        post.assert_not_called()

    def test_a_live_provider_needs_an_api_key(self):
        self.mps.is_live = True
        with self.assertRaises(ValidationError) as refused:
            self.mps.mps_api_key = False
        self.assertIn("API key", str(refused.exception))
        self.assertEqual(self.mps.mps_api_key, API_KEY)

    def test_a_provider_without_an_api_key_cannot_be_enabled_or_published(self):
        self.mps.write({"is_live": False, "is_published": False, "mps_api_key": False})
        for values in ({"is_live": True}, {"is_published": True}):
            with self.subTest(values=values), self.assertRaises(ValidationError) as refused:
                self.mps.write(values)
            self.assertIn("API key", str(refused.exception))
            self.assertIn("Online › API keys", str(refused.exception))
        with api_key_check():
            self.mps.write({"mps_api_key": API_KEY, "is_live": True, "is_published": True})
        self.assertTrue(self.mps.is_live and self.mps.is_published)

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

    def test_the_transaction_reference_is_the_idempotency_key_of_the_payment(self):
        tx = self._create_transaction("redirect")
        opened = {"paymentReference": PAYMENT_REFERENCE, "checkoutUrl": "https://checkout.example.com/p/abc"}
        with patch(SEND_API_REQUEST, return_value=opened) as send:
            tx._get_processing_values()
        self.assertEqual(send.call_args.kwargs["idempotency_key"], tx.reference)
        self.assertEqual(send.call_args.kwargs["reference"], tx.reference)

    def test_opening_sent_again_carries_the_same_key_and_body(self):
        tx = self._create_transaction("redirect")
        opened = gateway_response(201, {"data": {"paymentReference": PAYMENT_REFERENCE, "checkoutUrl": "https://c/p"}})
        with patch(API_REQUEST, side_effect=[opened, opened]) as request:
            tx._get_processing_values()
            tx._get_processing_values()
        first, second = request.call_args_list
        self.assertEqual(first.kwargs["headers"]["Idempotency-Key"], tx.reference)
        self.assertEqual(second.kwargs["headers"]["Idempotency-Key"], tx.reference)
        self.assertEqual(first.kwargs["json"], second.kwargs["json"])
        self.assertEqual(tx.provider_reference, PAYMENT_REFERENCE)

    def test_paying_again_after_a_failed_payment_opens_with_a_new_key(self):
        self._disable_process_patcher()
        failed = self._opened_transaction()
        failed.with_context(payment_safe_write=True)._process(self._payment("failed"))
        # What Odoo does when the shopper pays again: a new transaction, a new reference.
        again = self._create_transaction(
            "redirect", reference=self.env["payment.transaction"]._compute_reference("mps", prefix=self.reference)
        )
        opened = {"paymentReference": PAYMENT_REFERENCE, "checkoutUrl": "https://checkout.example.com/p/abc"}
        with patch(SEND_API_REQUEST, return_value=opened) as send:
            again._get_processing_values()
        self.assertEqual(failed.state, "cancel")
        self.assertNotEqual(again.reference, failed.reference)
        self.assertEqual(send.call_args.kwargs["idempotency_key"], again.reference)

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
class TestRefunds(MpsCommon):
    def setUp(self):
        super().setUp()
        self._disable_process_patcher()
        self.paid = self._opened_transaction(state="done")

    def _refunded(self, answer, amount=None):
        """Refund the paid transaction, MPS answering `answer`, and apply what it answered."""
        with patch(SEND_API_REQUEST, return_value=answer) as send:
            refund_tx = self.paid._refund(amount_to_refund=amount)
        self._run_processing()
        return refund_tx, send

    def _refused(self, status, body=None):
        """Refund the paid transaction, the gateway answering `status`."""
        response = gateway_response(status, body if body is not None else {})
        with patch(API_REQUEST, return_value=response), self.assertRaises(UserError) as refused:
            self.paid._refund(amount_to_refund=5.0)
        self.assertNotIsInstance(refused.exception, ValidationError)
        return str(refused.exception)

    def test_the_refund_is_sent_under_its_payment_with_the_amount_in_cents(self):
        refund_tx, send = self._refunded(self._refund("refunded", amount=1234), amount=12.34)
        self.assertEqual(send.call_args.args, ("POST", f"/api/payments/{PAYMENT_REFERENCE}/refunds"))
        self.assertEqual(send.call_args.kwargs["json"], {"amount": 1234, "reason": "requested_by_customer"})
        self.assertEqual(refund_tx.amount, -12.34)

    def test_the_payment_reference_is_one_path_segment(self):
        self.paid.with_context(payment_safe_write=True).provider_reference = "test_a/b c?d"
        _refund_tx, send = self._refunded(self._refund("pending", amount=1234), amount=12.34)
        self.assertEqual(send.call_args.args, ("POST", "/api/payments/test_a%2Fb%20c%3Fd/refunds"))

    def test_the_refund_transaction_reference_is_the_idempotency_key(self):
        refund_tx, send = self._refunded(self._refund("refunded", amount=1234), amount=12.34)
        self.assertEqual(send.call_args.kwargs["idempotency_key"], refund_tx.reference)
        self.assertEqual(send.call_args.kwargs["reference"], refund_tx.reference)

    def test_amounts_that_are_not_exact_in_binary_become_exact_cents(self):
        _refund_tx, send = self._refunded(self._refund("refunded", amount=29), amount=0.29)
        self.assertEqual(send.call_args.kwargs["json"]["amount"], 29)

    def test_a_full_refund_sends_the_whole_amount(self):
        refund_tx, send = self._refunded(self._refund("refunded"))
        self.assertEqual(send.call_args.kwargs["json"]["amount"], 111111)
        self.assertEqual(refund_tx.state, "done")

    def test_refunded_is_done_and_keeps_the_refund_id(self):
        refund_tx, _send = self._refunded(self._refund("refunded", amount=1234), amount=12.34)
        self.assertEqual(refund_tx.state, "done")
        self.assertEqual(refund_tx.provider_reference, REFUND_ID)
        self.assertEqual(refund_tx.operation, "refund")

    def test_a_sent_refund_mps_has_not_decided_is_pending(self):
        refund_tx, _send = self._refunded(self._refund("pending", amount=1234), amount=12.34)
        self.assertEqual(refund_tx.state, "pending")
        self.assertEqual(refund_tx.provider_reference, REFUND_ID)
        self.assertTrue(refund_tx.state_message)
        self.assertNotIn("cancel", refund_tx.state_message)

    def test_a_held_refund_says_until_when_it_can_be_canceled(self):
        refund_tx, _send = self._refunded(
            self._refund("pending", amount=1234, cancelable_until="2026-10-30T13:14:03Z"), amount=12.34
        )
        self.assertEqual(refund_tx.state, "pending")
        self.assertIn("2026-10-30 13:14", refund_tx.state_message)
        self.assertIn("cancel", refund_tx.state_message)
        # Every refund is held, card and bank transfer alike: the message names neither.
        self.assertNotIn("bank", refund_tx.state_message)

    def test_failed_is_an_error(self):
        refund_tx, _send = self._refunded(
            self._refund("failed", amount=1234, failure_reason="declined"), amount=12.34
        )
        self.assertEqual(refund_tx.state, "error")
        self.assertIn("declined", refund_tx.state_message)

    def test_canceled_is_cancelled(self):
        refund_tx, _send = self._refunded(self._refund("canceled", amount=1234), amount=12.34)
        self.assertEqual(refund_tx.state, "cancel")

    def test_an_unknown_status_is_pending_never_refunded(self):
        refund_tx, _send = self._refunded(self._refund("reversed-somehow", amount=1234), amount=12.34)
        self.assertEqual(refund_tx.state, "pending")

    @mute_logger("odoo.addons.payment_mps.models.payment_provider")
    def test_400_names_what_mps_refused(self):
        message = self._refused(400, {"errors": {"amount": ["At most 12.34 EUR can still be refunded."]}})
        self.assertIn("At most 12.34 EUR can still be refunded.", message)

    @mute_logger("odoo.addons.payment_mps.models.payment_provider")
    def test_403_says_the_key_needs_the_refund_permission(self):
        with api_key_check():
            self.mps.mps_api_key = "mps_live_abcdef123456_secret"
        message = self._refused(403)
        self.assertIn("payments:refund", message)
        self.assertIn("test key", message)

    @mute_logger("odoo.addons.payment_mps.models.payment_provider")
    def test_404_says_mps_does_not_know_the_payment_for_this_key(self):
        self.assertIn("test key", self._refused(404))

    @mute_logger("odoo.addons.payment_mps.models.payment_provider")
    def test_409_says_to_try_again(self):
        self.assertIn("again", self._refused(409))

    @mute_logger("odoo.addons.payment_mps.models.payment_provider")
    def test_mps_failing_puts_the_refund_in_error(self):
        with patch(API_REQUEST, return_value=gateway_response(500, {})):
            refund_tx = self.paid._refund(amount_to_refund=5.0)
        self.assertEqual(refund_tx.state, "error")

    def test_a_held_refund_is_read_again_and_settled(self):
        refund_tx, _send = self._refunded(
            self._refund("pending", amount=1234, cancelable_until="2026-10-30T13:14:03Z"), amount=12.34
        )
        self.env.cr.execute(
            "UPDATE payment_transaction SET create_date = %s WHERE id = %s",
            (fields.Datetime.now() - timedelta(minutes=11), refund_tx.id),
        )
        refund_tx.invalidate_recordset(["create_date"])
        with patch(SEND_API_REQUEST, return_value=self._refund("refunded", amount=1234)) as send:
            self.env["payment.transaction"]._cron_mps_check_payments()
        self.assertEqual(send.call_args.args, ("GET", f"/api/payments/{PAYMENT_REFERENCE}/refunds/{REFUND_ID}"))
        self._run_processing()
        self.assertEqual(refund_tx.state, "done")

    def test_a_refund_is_read_under_the_payment_it_refunds_encoded(self):
        self.paid.with_context(payment_safe_write=True).provider_reference = "test_a/b"
        refund_tx, _send = self._refunded(self._refund("pending", amount=1234), amount=12.34)
        with patch(SEND_API_REQUEST, return_value=self._refund("pending", amount=1234)) as send:
            refund_tx._mps_read()
        self.assertEqual(send.call_args.args, ("GET", f"/api/payments/test_a%2Fb/refunds/{REFUND_ID}"))

    @mute_logger("odoo.addons.payment_mps.models.payment_transaction")
    def test_a_refund_without_its_payment_reference_is_not_read(self):
        refund_tx = self._create_transaction(
            "redirect", reference="R-orphan", operation="refund", amount=-12.34, state="pending",
            provider_reference=REFUND_ID,
        )
        self.env.cr.execute(
            "UPDATE payment_transaction SET create_date = %s WHERE id = %s",
            (fields.Datetime.now() - timedelta(minutes=11), refund_tx.id),
        )
        refund_tx.invalidate_recordset(["create_date"])
        with patch(SEND_API_REQUEST) as send:
            with self.assertRaises(ValidationError):
                refund_tx._mps_read()
            self.env["payment.transaction"]._cron_mps_check_payments()
        send.assert_not_called()


@tagged("post_install", "-at_install")
class TestMigration(MpsCommon):
    """migrations/20.0.2.0.0, run on a database upgraded from a version that had client credentials."""

    OLD_COLUMNS = ("mps_client_id", "mps_client_secret", "mps_access_token", "mps_token_expiry")

    def _migrate(self):
        path = file_path("payment_mps/migrations/20.0.2.0.0/pre-migrate.py")
        spec = importlib.util.spec_from_file_location("payment_mps_pre_migrate_20_0_2_0_0", path)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        self.env.flush_all()
        module.migrate(self.env.cr, "20.0.1.2.1")
        self.env.invalidate_all()

    def _as_before(self, api_key, live):
        """The provider as an older version left it: client credentials, a cached token."""
        self.env.flush_all()
        self.env.cr.execute(
            "ALTER TABLE payment_provider ADD COLUMN mps_client_id varchar, ADD COLUMN mps_client_secret"
            " varchar, ADD COLUMN mps_access_token varchar, ADD COLUMN mps_token_expiry timestamp"
        )
        self.env.cr.execute(
            "UPDATE payment_provider SET mps_client_id = 'BE0000000000', mps_client_secret = 'secret',"
            " mps_access_token = 'token', mps_token_expiry = now() + interval '10 minutes',"
            " mps_api_key = %s, is_live = %s, is_published = %s WHERE id = %s",
            (api_key, live, live, self.mps.id),
        )

    def _old_values(self):
        self.env.cr.execute(
            "SELECT mps_client_id, mps_client_secret, mps_access_token, mps_token_expiry"
            " FROM payment_provider WHERE id = %s",
            (self.mps.id,),
        )
        return self.env.cr.fetchone()

    def test_client_credentials_and_the_token_are_erased(self):
        self._as_before(api_key=API_KEY, live=True)
        self._migrate()
        self.assertEqual(self._old_values(), (None, None, None, None))

    def test_a_provider_with_an_api_key_stays_live(self):
        self._as_before(api_key=API_KEY, live=True)
        self._migrate()
        self.assertTrue(self.mps.is_live and self.mps.is_published)
        self.assertEqual(self.mps.mps_api_key, API_KEY)

    @mute_logger("odoo.addons.payment_mps.migrations.20.0.2.0.0.pre-migrate", "payment_mps_pre_migrate_20_0_2_0_0")
    def test_a_provider_with_only_client_credentials_is_taken_off_line(self):
        self._as_before(api_key=None, live=True)
        self._migrate()
        self.assertEqual(self._old_values(), (None, None, None, None))
        self.assertFalse(self.mps.is_live)
        self.assertFalse(self.mps.is_published)
        with self.assertRaises(ValidationError) as refused:
            self.mps.is_published = True
        self.assertIn("API key", str(refused.exception))

    def test_the_payment_method_installed_without_refunds_gets_them(self):
        self.payment_method.support_refund = "none"
        self._migrate()
        self.assertEqual(self.payment_method.support_refund, "partial")

    def test_a_database_without_the_old_columns_is_left_alone(self):
        self.mps.write({"is_live": True, "is_published": True})
        self._migrate()
        self.assertTrue(self.mps.is_live and self.mps.is_published)


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
