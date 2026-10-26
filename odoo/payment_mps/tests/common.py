from contextlib import contextmanager
from unittest.mock import MagicMock, patch

from odoo.addons.payment.tests.common import PaymentCommon

TOKEN_POST = "odoo.addons.payment_mps.models.payment_provider.requests.post"
SEND_API_REQUEST = "odoo.addons.payment.models.payment_provider.PaymentProvider._send_api_request"
PAYMENT_REFERENCE = "98dcf1df-2e04-4684-84cd-d47ec8c23c80"


def token_response(status=200, token="token-1", expires_in=900):
    response = MagicMock(status_code=status, text="{}")
    response.json.return_value = {"access_token": token, "expires_in": expires_in} if status == 200 else {}
    return response


@contextmanager
def token_endpoint(*responses):
    """The gateway's token endpoint, answering the given responses in turn (a 200 by default)."""
    with patch(TOKEN_POST, side_effect=list(responses) or [token_response()]) as post:
        yield post


class MpsCommon(PaymentCommon):
    @classmethod
    def setUpClass(cls):
        super().setUpClass()

        with token_endpoint():
            cls.mps = cls._prepare_provider(
                "mps",
                update_values={
                    "mps_client_id": "BE0000000000",
                    "mps_client_secret": "secret",
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

    def _opened_transaction(self, **values):
        """A redirect transaction whose MPS payment has been opened."""
        return self._create_transaction("redirect", provider_reference=PAYMENT_REFERENCE, **values)
