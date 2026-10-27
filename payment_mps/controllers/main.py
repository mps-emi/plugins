import json
import time

from werkzeug.exceptions import ServiceUnavailable

from odoo import http
from odoo.exceptions import ValidationError
from odoo.http import request

from odoo.addons.payment.logging import get_payment_logger
from odoo.addons.payment_mps import utils

_logger = get_payment_logger(__name__)


class MpsController(http.Controller):
    """Two things can tell Odoo to look at a payment again: the shopper returning and MPS's
    notification (the third, the scheduled reads, is a cron). None of them is trusted on its own:
    each one reads the payment from MPS and records what MPS says."""

    _return_url = "/payment/mps/return"
    _webhook_url = "/payment/mps/webhook"

    @http.route(
        _return_url,
        type="http",
        auth="public",
        methods=["GET"],
        csrf=False,
        save_session=False,
    )
    def mps_return_from_checkout(self, ref=None, result=None, **_kwargs):
        """The shopper is back from the hosted checkout. Which URL they came back on (`result`) is a
        hint, never proof.

        The route is flagged with `save_session=False` so that a browser that drops the session
        cookie on the way back does not get a new session; `/payment/status` finds the old one.

        :param str ref: The transaction reference, embedded in the return URL.
        :param str result: `success` or `failure`.
        """
        tx_sudo = self._find_transaction([("reference", "=", ref)]) if ref else None
        _logger.info("Shopper returned for %s on the %s URL.", ref, result)
        if tx_sudo and tx_sudo.provider_reference:
            try:
                tx_sudo._record(tx_sudo._mps_read_payment())
            except ValidationError as error:
                # The scheduled reads pick it up; the status page shows the transaction as it is.
                _logger.warning("Could not read %s on return: %s", tx_sudo.reference, error)
        return request.redirect("/payment/status")

    @http.route(
        _webhook_url,
        type="http",
        auth="public",
        methods=["POST"],
        csrf=False,
        save_session=False,
    )
    def mps_webhook(self, **_kwargs):
        """MPS's notification that a payment arrived. Answered 200 whenever it was understood,
        including for a payment this database does not know, so MPS does not keep retrying something
        that will never be acted on. Without a signing secret it is ignored: the return and the
        scheduled reads settle the transaction instead.

        :raise ServiceUnavailable: When MPS cannot be read, so that MPS delivers it again.
        """
        if not request.env["payment.provider"].sudo().search_count(
            [("code", "=", "mps"), ("mps_webhook_secret", "!=", False)], limit=1
        ):
            _logger.info("Webhook ignored: no signing secret configured.")
            return request.make_response("")

        body = request.httprequest.get_data()
        headers = request.httprequest.headers
        try:
            event = json.loads(body)
        except ValueError:
            event = None
        payment_reference = event.get("paymentReference") if isinstance(event, dict) else None

        tx_sudo = (
            self._find_transaction([("provider_reference", "=", payment_reference)])
            if isinstance(payment_reference, str) and payment_reference
            else None
        )
        _logger.info(
            "Notification %s for payment %s: %s",
            headers.get("X-Mps-Delivery-Id", "-"),
            payment_reference or "-",
            f"transaction {tx_sudo.reference}" if tx_sudo else "no transaction of this database",
        )
        if not tx_sudo:
            return request.make_response("")

        secret = tx_sudo.provider_id.mps_webhook_secret
        if not secret:
            _logger.info("Webhook ignored: no signing secret configured.")
            return request.make_response("")
        if not utils.is_valid_signature(
            secret,
            body,
            headers.get("X-Mps-Timestamp"),
            headers.get("X-Mps-Signature"),
            int(time.time()),
        ):
            _logger.warning("Notification refused: signature or timestamp does not match.")
            return request.make_response("", status=401)

        try:
            # The notification only says "look"; what the payment is comes from reading it.
            tx_sudo._record(tx_sudo._mps_read_payment())
        except ValidationError as error:
            _logger.warning("Could not read %s on notification: %s", tx_sudo.reference, error)
            raise ServiceUnavailable from error
        return request.make_response("")

    @staticmethod
    def _find_transaction(domain):
        return (
            request.env["payment.transaction"]
            .sudo()
            .search([("provider_code", "=", "mps"), *domain], limit=1)
        )
