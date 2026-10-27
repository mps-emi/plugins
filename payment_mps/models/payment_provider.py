from datetime import timedelta

import requests

from odoo import api, fields, models
from odoo.exceptions import ValidationError

from odoo.addons.payment.logging import get_payment_logger
from odoo.addons.payment_mps import const

_logger = get_payment_logger(__name__, sensitive_keys=const.SENSITIVE_KEYS)

# Changing any of these makes the cached token belong to other credentials.
CREDENTIAL_FIELDS = ("mps_client_id", "mps_client_secret", "mps_gateway_url")


class MpsTokenRejected(ValidationError):
    """The gateway answered 401: the token expired between being cached and being used."""


class PaymentProvider(models.Model):
    _inherit = "payment.provider"

    code = fields.Selection(selection_add=[("mps", "MPS")], ondelete={"mps": "set default"})
    mps_client_id = fields.Char(
        string="Client ID",
        help="From the MPS platform, under Online › Get started. A test key starts with test_.",
        required_if_provider="mps",
        copy=False,
    )
    mps_client_secret = fields.Char(
        string="Client Secret",
        required_if_provider="mps",
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
    # The token cache: the token endpoint is rate limited far more tightly than the payment endpoints.
    mps_access_token = fields.Char(string="MPS Access Token", copy=False, groups="base.group_system")
    mps_token_expiry = fields.Datetime(
        string="MPS Token Expiry", copy=False, groups="base.group_system"
    )

    # === COMPUTE METHODS === #

    @api.depends("mps_client_id")
    def _compute_mps_is_test_key(self):
        for provider in self:
            provider.mps_is_test_key = (provider.mps_client_id or "").startswith(
                const.TEST_KEY_PREFIX
            )

    def _get_supported_currencies(self):
        """Override of `payment` to return the supported currencies."""
        supported_currencies = super()._get_supported_currencies()
        if self.code == "mps":
            supported_currencies = supported_currencies.filtered(lambda c: c.name == "EUR")
        return supported_currencies

    # === CRUD METHODS === #

    def write(self, vals):
        """Override of `payment` to prove new credentials with a token request, as they are saved."""
        credentials_changed = any(field in vals for field in CREDENTIAL_FIELDS)
        if credentials_changed:
            vals = {**vals, "mps_access_token": False, "mps_token_expiry": False}

        result = super().write(vals)

        if credentials_changed:
            for provider in self.sudo().filtered(
                lambda p: p.code == "mps" and p.mps_client_id and p.mps_client_secret
            ):
                provider._mps_token()
        return result

    def _get_default_payment_method_codes(self):
        """Override of `payment` to return the default payment method codes."""
        self.ensure_one()
        if self.code != "mps":
            return super()._get_default_payment_method_codes()
        return {"mps"}

    # === BUSINESS METHODS === #

    def _mps_token(self):
        """A token for these credentials, from the cache while it lasts.

        :return: The access token.
        :rtype: str
        :raise ValidationError: When MPS cannot be reached or refuses the credentials.
        """
        self.ensure_one()
        provider = self.sudo()
        if (
            provider.mps_access_token
            and provider.mps_token_expiry
            and provider.mps_token_expiry > fields.Datetime.now()
        ):
            return provider.mps_access_token

        try:
            response = requests.post(
                f"{provider._mps_base_url()}/connect/token",
                data={
                    "grant_type": "client_credentials",
                    "client_id": provider.mps_client_id,
                    "client_secret": provider.mps_client_secret,
                    "scope": const.SCOPES,
                },
                timeout=10,
            )
        except (requests.exceptions.ConnectionError, requests.exceptions.Timeout):
            raise ValidationError(self.env._("Could not reach MPS.")) from None

        try:
            content = response.json()
        except requests.exceptions.JSONDecodeError:
            content = {}
        token = content.get("access_token") if response.status_code == 200 else None
        if not token:
            _logger.warning(
                "Token request for %s answered %s: %s",
                provider.mps_client_id,
                response.status_code,
                response.text,
            )
            raise ValidationError(
                self.env._("MPS did not accept these credentials (%s).", response.status_code)
            )

        lifetime = int(content.get("expires_in") or 900)
        _logger.info("New token for %s, valid %s s.", provider.mps_client_id, lifetime)
        provider.write({
            "mps_access_token": token,
            "mps_token_expiry": fields.Datetime.now()
            + timedelta(seconds=max(60, lifetime - const.TOKEN_EXPIRY_MARGIN_SECONDS)),
        })
        return token

    def _mps_forget_token(self):
        self.sudo().write({"mps_access_token": False, "mps_token_expiry": False})

    def _mps_base_url(self):
        return (self.mps_gateway_url or const.DEFAULT_GATEWAY_URL).rstrip("/")

    # === REQUEST HELPERS === #

    def _send_api_request(self, method, endpoint, **kwargs):
        """Override of `payment` to replace a token MPS rejected, once, and try again."""
        if self.code != "mps":
            return super()._send_api_request(method, endpoint, **kwargs)

        try:
            return super()._send_api_request(method, endpoint, **kwargs)
        except MpsTokenRejected:
            self._mps_forget_token()
            return super()._send_api_request(method, endpoint, **kwargs)

    def _build_request_url(self, endpoint, **kwargs):
        """Override of `payment` to build the request URL."""
        if self.code != "mps":
            return super()._build_request_url(endpoint, **kwargs)
        return f"{self._mps_base_url()}{endpoint}"

    def _build_request_headers(self, *args, **kwargs):
        """Override of `payment` to build the request headers."""
        if self.code != "mps":
            return super()._build_request_headers(*args, **kwargs)
        return {"Accept": "application/json", "Authorization": f"Bearer {self._mps_token()}"}

    def _parse_response_content(self, response, **kwargs):
        """Override of `payment` to unwrap the gateway's `data` envelope."""
        if self.code != "mps":
            return super()._parse_response_content(response, **kwargs)
        return response.json().get("data") or {}

    def _parse_response_error(self, response):
        """Override of `payment` to tell a rejected token apart from a refused request."""
        if self.code != "mps":
            return super()._parse_response_error(response)
        if response.status_code == 401:
            raise MpsTokenRejected(self.env._("MPS rejected the token."))
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
