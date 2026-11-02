# The gateway the addon calls, unless the provider names another.
DEFAULT_GATEWAY_URL = "https://gateway.mps-emi.com"

# Sent with every refund made from Odoo: a merchant refunds from Odoo when the shopper asks.
REFUND_REASON = "requested_by_customer"

# An API key with this prefix is a test key: MPS test cards only, charges nothing. A live
# one starts mps_live_.
TEST_API_KEY_PREFIX = "mps_test_"

# A payment reference no payment has, read to prove an API key as it is saved: MPS answers 404 to a key it
# accepts, 401 or 403 to one it does not.
CREDENTIAL_CHECK_REFERENCE = "mps-credential-check"

# Minutes after a payment was opened at which a still-open transaction is read again. These reads are
# what settle a shop that cannot receive notifications when the shopper closes the tab.
CHECK_DELAYS_MINUTES = (10, 30, 60, 360)

# Never written to the log: checkoutUrl pays the payment for whoever holds it, and the return URLs carry
# the transaction reference.
SENSITIVE_KEYS = {
    "callbackUrl",
    "checkoutUrl",
    "failureUrl",
    "successUrl",
}
