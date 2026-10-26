"""The addon's pure rules: what MPS signs, and which callback URLs MPS can reach."""

import hashlib
import hmac
import ipaddress
from urllib.parse import urlsplit

# A notification older (or newer) than this is refused, so a captured one cannot be replayed later.
SIGNATURE_TOLERANCE_SECONDS = 300

# Names that never resolve on the public internet.
LOCAL_SUFFIXES = (".localhost", ".local", ".test", ".internal", ".lan")


def sign(secret, timestamp, body):
    """HMAC-SHA256 over "{timestamp}.{raw body}", hex.

    :param str secret: The signing secret.
    :param str timestamp: X-Mps-Timestamp, Unix seconds.
    :param bytes body: The raw request body, exactly as received.
    :rtype: str
    """
    return hmac.new(secret.encode(), timestamp.encode() + b"." + body, hashlib.sha256).hexdigest()


def is_valid_signature(secret, body, timestamp, header, now):
    """Check X-Mps-Signature. During a secret rotation the header carries two comma-separated
    signatures; either one matching is valid.

    :param str secret: The signing secret.
    :param bytes body: The raw request body, exactly as received.
    :param str timestamp: X-Mps-Timestamp, Unix seconds.
    :param str header: X-Mps-Signature, `v1=<hex>` values.
    :param int now: The current Unix time.
    :rtype: bool
    """
    if not timestamp or not (timestamp.isascii() and timestamp.isdigit()):
        return False
    if abs(now - int(timestamp)) > SIGNATURE_TOLERANCE_SECONDS:
        return False

    expected = sign(secret, timestamp, body)
    for candidate in (header or "").split(","):
        candidate = candidate.strip()
        if candidate.startswith("v1=") and hmac.compare_digest(expected, candidate[3:]):
            return True
    return False


def can_receive_webhooks(url):
    """Whether MPS could call this URL back. MPS refuses a callback that is not HTTPS on a publicly
    routable host, and refuses the whole payment with it, so a shop on localhost or a private address
    sends none and is settled by the return and the scheduled reads instead.

    :param str url: The webhook URL.
    :rtype: bool
    """
    parts = urlsplit(url)
    host = (parts.hostname or "").lower()
    if parts.scheme != "https" or not host:
        return False

    try:
        address = ipaddress.ip_address(host)
    except ValueError:
        # Not an IP address but a host name: public when it has a dot and no local suffix.
        return "." in host and not host.endswith(LOCAL_SUFFIXES)
    return address.is_global
