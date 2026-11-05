import requests

from odoo import api, fields, models
from odoo.exceptions import ValidationError

from odoo.addons.payment.logging import get_payment_logger
from odoo.addons.payment_mps import const

_logger = get_payment_logger(__name__, sensitive_keys=const.SENSITIVE_KEYS)

# Changing either is checked with MPS.
CREDENTIAL_FIELDS = ("mps_api_key", "mps_gateway_url")


class MpsRequestRefused(ValidationError):
    """The gateway refused the request with a 4xx other than 401. A ValidationError like any refusal,
    with the status and the field errors kept for a caller that explains them."""

    def __init__(self, message, status, errors):
        super().__init__(message)
        self.status = status
        self.errors = errors


class PaymentProvider(models.Model):
    _inherit = "payment.provider"

    code = fields.Selection(selection_add=[("mps", "MPS")], ondelete={"mps": "set default"})
    # Sent as the bearer on every call; the only credential. A provider needs it to be live or
    # published (`_check_required_if_provider`).
    mps_api_key = fields.Char(
        string="API Key",
        help="From the MPS platform, under Online › API keys. A test key starts with mps_test_.",
        copy=False,
        groups="base.group_system",
    )
    mps_webhook_secret = fields.Char(
        string="Webhook Signing Secret",
        help="Shown with your key in the MPS platform. Payment notifications are only acted on when"
        " their signature matches; without a secret they are ignored and orders are settled by the"
        " module's own checks.",
        copy=False,
        groups="base.group_system",
    )
    mps_gateway_url = fields.Char(
        string="Gateway",
        default=const.DEFAULT_GATEWAY_URL,
        required_if_provider="mps",
    )
    mps_is_test_key = fields.Boolean(string="MPS Test Key", compute="_compute_mps_is_test_key")
    # Not a secret, so the form can say a key is missing to whoever opens it.
    mps_has_api_key = fields.Boolean(string="MPS API Key Set", compute="_compute_mps_has_api_key")

    # === COMPUTE METHODS === #

    @api.depends("mps_api_key")
    def _compute_mps_is_test_key(self):
        for provider in self:
            provider.mps_is_test_key = (provider.sudo().mps_api_key or "").startswith(
                const.TEST_API_KEY_PREFIX
            )

    @api.depends("mps_api_key")
    def _compute_mps_has_api_key(self):
        for provider in self:
            provider.mps_has_api_key = bool(provider.sudo().mps_api_key)

    def _compute_feature_support_fields(self):
        """Override of `payment` to enable the refunds, in full or in parts."""
        super()._compute_feature_support_fields()
        self.filtered(lambda p: p.code == "mps").update({"support_refund": "partial"})

    def _get_supported_currencies(self):
        """Override of `payment` to return the supported currencies."""
        supported_currencies = super()._get_supported_currencies()
        if self.code == "mps":
            supported_currencies = supported_currencies.filtered(lambda c: c.name == "EUR")
        return supported_currencies

    # === CRUD METHODS === #

    def write(self, vals):
        """Override of `payment` to prove a new API key with MPS, as it is saved."""
        result = super().write(vals)

        if any(field in vals for field in CREDENTIAL_FIELDS):
            for provider in self.sudo().filtered(lambda p: p.code == "mps" and p.mps_api_key):
                provider._mps_verify_credentials()
        return result

    def _check_required_if_provider(self):
        """Override of `payment` to require an API key of a provider that is live or published: it is
        the only credential MPS accepts from this addon."""
        super()._check_required_if_provider()
        if any(
            not provider.sudo().mps_api_key
            for provider in self.filtered(
                lambda p: p._get_code() == "mps" and (p.is_live or p.is_published)
            )
        ):
            raise ValidationError(
                self.env._(
                    "Fill in the API key from the MPS platform (Online › API keys) before enabling"
                    " or publishing MPS. MPS connects with an API key only; a client ID and secret are"
                    " no longer used."
                )
            )

    def _get_default_payment_method_codes(self):
        """Override of `payment` to return the default payment method codes."""
        self.ensure_one()
        if self.code != "mps":
            return super()._get_default_payment_method_codes()
        return {"mps"}

    # === BUSINESS METHODS === #

    def _mps_verify_credentials(self):
        """Prove the API key with MPS by reading a payment that does not exist: 404 means MPS accepted
        the key.

        :raise ValidationError: When MPS cannot be reached or refuses the key.
        """
        self.ensure_one()
        provider = self.sudo()
        try:
            response = requests.get(
                f"{provider._mps_base_url()}/api/payments/internet/{const.CREDENTIAL_CHECK_REFERENCE}",
                headers={"Accept": "application/json", "Authorization": f"Bearer {provider.mps_api_key}"},
                timeout=10,
            )
        except (requests.exceptions.ConnectionError, requests.exceptions.Timeout):
            raise ValidationError(self.env._("Could not reach MPS.")) from None

        if response.status_code != 404:
            _logger.warning("API key check answered %s: %s", response.status_code, response.text)
            raise ValidationError(
                self.env._("MPS did not accept these credentials (%s).", response.status_code)
            )

    def _mps_base_url(self):
        return (self.mps_gateway_url or const.DEFAULT_GATEWAY_URL).rstrip("/")

    # === REQUEST HELPERS === #

    def _build_request_url(self, endpoint, **kwargs):
        """Override of `payment` to build the request URL."""
        if self.code != "mps":
            return super()._build_request_url(endpoint, **kwargs)
        return f"{self._mps_base_url()}{endpoint}"

    def _build_request_headers(self, *args, idempotency_key=None, **kwargs):
        """Override of `payment` to build the request headers. With `idempotency_key`, MPS answers a
        repeat of the request with its first result instead of doing it again."""
        if self.code != "mps":
            return super()._build_request_headers(*args, **kwargs)
        headers = {"Accept": "application/json", "Authorization": f"Bearer {self.sudo().mps_api_key}"}
        if idempotency_key:
            headers["Idempotency-Key"] = idempotency_key
        return headers

    def _parse_response_content(self, response, **kwargs):
        """Override of `payment` to unwrap the gateway's `data` envelope."""
        if self.code != "mps":
            return super()._parse_response_content(response, **kwargs)
        return response.json().get("data") or {}

    def _parse_response_error(self, response):
        """Override of `payment` to tell a rejected API key apart from a refused request."""
        if self.code != "mps":
            return super()._parse_response_error(response)
        if response.status_code == 401:
            raise ValidationError(self.env._("MPS rejected the API key."))
        if 400 <= response.status_code < 500:
            try:
                content = response.json()
            except ValueError:
                content = None
            errors = content.get("errors") if isinstance(content, dict) else None
            raise MpsRequestRefused(
                self.env._("The payment provider rejected the request.\n%s", response.text),
                response.status_code,
                errors if isinstance(errors, dict) else {},
            )
        return response.text

    def _log_request(self, method, url, payload, *, reference=None):
        """Override of `payment` to log through a logger that masks the sensitive keys."""
        if self.code != "mps":
            return super()._log_request(method, url, payload, reference=reference)
        _logger.info(
            "Sending %(method)s %(url)s for %(subject)s. Payload: %(payload)s",
            {
                "method": method,
                "url": url,
                "subject": f"transaction {reference}" if reference else f"provider {self.id}",
                "payload": payload,
            },
        )

    def _log_response(self, response, *, reference=None):
        """Override of `payment` to log through a logger that masks the sensitive keys."""
        if self.code != "mps":
            return super()._log_response(response, reference=reference)
        (_logger.info if response.ok else _logger.warning)(
            "Received HTTP %(code)s from %(url)s for %(subject)s: %(data)s",
            {
                "code": response.status_code,
                "url": response.url,
                "subject": f"transaction {reference}" if reference else f"provider {self.id}",
                "data": response.text,
            },
        )
