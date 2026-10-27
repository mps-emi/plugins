# The gateway the addon calls for tokens and payments, unless the provider names another.
DEFAULT_GATEWAY_URL = "https://gateway.mps-emi.com"

# What a token for this addon may do: open internet payments and read them back.
SCOPES = "internet:create internet:read"

# A client ID with this prefix is a test key: works 24 hours, MPS test cards only, charges nothing.
TEST_KEY_PREFIX = "test_"

# A token is dropped this long before it expires, so one is never handed out in its last seconds.
TOKEN_EXPIRY_MARGIN_SECONDS = 60

# Minutes after a payment was opened at which a still-open transaction is read again. These reads are
# what settle a shop that cannot receive notifications when the shopper closes the tab.
CHECK_DELAYS_MINUTES = (10, 30, 60, 360)

# Never written to the log: checkoutUrl pays the payment for whoever holds it, the return URLs carry the
# transaction reference, and the rest are credentials.
SENSITIVE_KEYS = {
    "access_token",
    "callbackUrl",
    "checkoutUrl",
    "client_secret",
    "failureUrl",
    "successUrl",
}
