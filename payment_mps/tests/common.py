import json
from contextlib import contextmanager
from unittest.mock import MagicMock, patch

import requests

from odoo.addons.payment.tests.common import PaymentCommon

API_KEY_CHECK_GET = "odoo.addons.payment_mps.models.payment_provider.requests.get"
API_REQUEST = "odoo.addons.payment.models.payment_provider.requests.request"
API_KEY = "mps_test_abcdef123456_c2VjcmV0X3dpdGhfdW5kZXJzY29yZXM"
SEND_API_REQUEST = "odoo.addons.payment.models.payment_provider.PaymentProvider._send_api_request"
PAYMENT_REFERENCE = "98dcf1df-2e04-4684-84cd-d47ec8c23c80"
REFUND_ID = "0b7d9c41-7f0e-4b8a-9d3c-2a1e5f6b7c8d"


def gateway_response(status, body):
    """A gateway answer as `requests.request` returns it."""
    response = MagicMock(status_code=status, ok=status < 400, text=json.dumps(body), reason="", url="u")
    response.json.return_value = body
    if status >= 400:
        response.raise_for_status.side_effect = requests.exceptions.HTTPError()
    return response


@contextmanager
def api_key_check(status=404):
    """The read that proves an API key as it is saved: MPS answers 404 to a key it accepts."""
    with patch(API_KEY_CHECK_GET, return_value=MagicMock(status_code=status, text="")) as get:
        yield get


class MpsCommon(PaymentCommon):
    @classmethod
    def setUpClass(cls):
        super().setUpClass()

        with api_key_check():
            cls.mps = cls._prepare_provider(
                "mps",
                update_values={
                    "mps_api_key": API_KEY,
                    "mps_gateway_url": "https://gateway.example.com",
                },
            )
        cls.provider = cls.mps
        cls.currency = cls.currency_euro
        cls.payment_method = cls.mps.payment_method_ids[:1]
        cls.payment_method_id = cls.payment_method.id

    def _payment(self, state, amount=111111, currency="EUR", reference=None):
        """A payment as `GET /api/payments/internet/{paymentReference}` answers it, unwrapped."""
        return {
            "paymentReference": PAYMENT_REFERENCE,
            "state": state,
            "amount": amount,
            "currency": currency,
            "reference": reference or self.reference,
        }

    def _refund(self, status, amount=111111, cancelable_until=None, failure_reason=None):
        """A refund as `POST /api/payments/{paymentReference}/refunds` and
        `GET /api/payments/{paymentReference}/refunds/{refundId}` answer it, unwrapped."""
        return {
            "refundId": REFUND_ID,
            "paymentReference": PAYMENT_REFERENCE,
            "status": status,
            "amount": amount,
            "currency": "EUR",
            "reason": "requested_by_customer",
            "failureReason": failure_reason,
            "cancelableUntil": cancelable_until,
            "createdAt": "2026-10-30T09:14:03Z",
        }

    def _opened_transaction(self, **values):
        """A redirect transaction whose MPS payment has been opened."""
        return self._create_transaction("redirect", provider_reference=PAYMENT_REFERENCE, **values)
