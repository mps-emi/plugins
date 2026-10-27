from datetime import timedelta
from urllib.parse import quote, urlencode

from odoo import api, fields, models
from odoo.exceptions import ValidationError
from odoo.tools import urls

from odoo.addons.payment import utils as payment_utils
from odoo.addons.payment.logging import get_payment_logger
from odoo.addons.payment_mps import const, utils
from odoo.addons.payment_mps.controllers.main import MpsController

_logger = get_payment_logger(__name__, sensitive_keys=const.SENSITIVE_KEYS)


class PaymentTransaction(models.Model):
    _inherit = "payment.transaction"

    mps_check_count = fields.Integer(
        string="MPS Scheduled Reads",
        help="How many of the scheduled reads after opening the payment have been made.",
        default=0,
        copy=False,
    )

    # === OUTBOUND === #

    def _get_specific_rendering_values(self, processing_values):
        """Override of `payment` to open the MPS payment and send the shopper to its checkout."""
        if self.provider_code != "mps":
            return super()._get_specific_rendering_values(processing_values)

        try:
            opened = self._send_api_request(
                "POST", "/api/payments/internet/create", json=self._mps_prepare_payment_payload()
            )
        except ValidationError as error:
            _logger.warning("MPS could not open a payment for %s: %s", self.reference, error)
            self._set_error(str(error))
            return {}

        if not opened.get("paymentReference") or not opened.get("checkoutUrl"):
            self._set_error(self.env._("MPS did not return a payment to send the shopper to."))
            return {}

        # Every later read, return and notification is matched on it.
        self.provider_reference = opened["paymentReference"]

        # checkoutUrl pays this payment for whoever holds it: hand it to the browser and nowhere else.
        checkout_url = opened["checkoutUrl"]
        return {
            "api_url": checkout_url,
            "http_method": "get",
            "url_params": payment_utils.extract_url_params(checkout_url),
        }

    def _mps_prepare_payment_payload(self):
        """The body of `POST /api/payments/internet/create`.

        Which wallet and which shop name the shopper sees come from the credentials, never from here.
        """
        base_url = self.provider_id.get_base_url()
        return_url = urls.urljoin(base_url, MpsController._return_url)

        payload = {
            "amount": payment_utils.to_minor_currency_units(self.amount, self.currency_id),
            "reference": self.reference,
            "successUrl": f"{return_url}?{urlencode({'ref': self.reference, 'result': 'success'})}",
            "failureUrl": f"{return_url}?{urlencode({'ref': self.reference, 'result': 'failure'})}",
        }
        callback_url = urls.urljoin(base_url, MpsController._webhook_url)
        if utils.can_receive_webhooks(callback_url):
            payload["callbackUrl"] = callback_url
        return payload

    def _mps_read_payment(self):
        """Read the payment back: the only authority on whether it was paid.

        :return: paymentReference, state, amount (cents), currency and reference.
        :rtype: dict
        :raise ValidationError: When MPS cannot be reached or refuses.
        """
        self.ensure_one()
        return self._send_api_request(
            "GET", f"/api/payments/internet/{quote(self.provider_reference, safe='')}"
        )

    # === PROCESSING === #

    def _apply_updates(self, payment_data):
        """Override of `payment` to move the transaction on the payment's state."""
        if self.provider_code != "mps":
            super()._apply_updates(payment_data)
            return

        state = payment_data.get("state")
        if state == "executed":
            approved = payment_data.get("amount") or 0
            requested = payment_utils.to_minor_currency_units(self.amount, self.currency_id)
            if approved < requested:
                # A card issuer can approve part of an amount; someone must look before delivering.
                message = self.env._(
                    "MPS approved %(approved)s of %(requested)s %(currency)s: a partial approval."
                    " Check before delivering.",
                    approved=f"{approved / 100:.2f}",
                    requested=f"{requested / 100:.2f}",
                    currency=payment_data.get("currency") or self.currency_id.name,
                )
                if self.state == "pending":
                    self.state_message = message
                self._set_pending(state_message=message)
            else:
                self._set_done()
        elif state == "failed":
            self._set_canceled(state_message=self.env._("The payment failed at MPS."))
        elif state == "abandoned":
            self._set_canceled(
                state_message=self.env._("The shopper left the MPS checkout without paying.")
            )
        else:
            # Pending, or a state this addon does not know: an unknown state never means paid.
            self._set_pending()

    def _extract_amount_data(self, payment_data):
        """Override of `payment` to extract the amount and currency from the payment data."""
        if self.provider_code != "mps":
            return super()._extract_amount_data(payment_data)
        return {
            "amount": (payment_data.get("amount") or 0) / 100,
            "currency_code": payment_data.get("currency"),
        }

    # === SCHEDULED READS === #

    @api.model
    def _cron_mps_check_payments(self):
        """Read open MPS transactions again at each of `const.CHECK_DELAYS_MINUTES` after they were
        opened. A run that comes late reads once for all the delays it passed."""
        now = fields.Datetime.now()
        txs = self.search([
            ("provider_code", "=", "mps"),
            ("state", "in", ("draft", "pending")),
            ("provider_reference", "!=", False),
            ("mps_check_count", "<", len(const.CHECK_DELAYS_MINUTES)),
        ])
        for tx in txs.with_context(payment_safe_write=True):
            age = now - tx.create_date
            due = sum(1 for minutes in const.CHECK_DELAYS_MINUTES if age >= timedelta(minutes=minutes))
            if due <= tx.mps_check_count:
                continue

            tx.mps_check_count = due
            try:
                tx._record(tx._mps_read_payment())
            except ValidationError as error:
                _logger.warning("Scheduled read of %s failed: %s", tx.reference, error)
