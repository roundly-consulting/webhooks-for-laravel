import base64
import hashlib
import hmac
import re
import time


def verify_webhook(secret, msg_id, timestamp, signature, raw_body, now=None):
    # "whsec_" + base64 → the decoded bytes; any other secret is used as its own bytes.
    key = base64.b64decode(secret[6:]) if secret.startswith("whsec_") else secret.encode()
    now = time.time() if now is None else now

    if not re.fullmatch(r"\d{1,12}", timestamp) or abs(now - int(timestamp)) > 300:
        return False

    signed = f"{msg_id}.{timestamp}.".encode() + raw_body
    expected = base64.b64encode(hmac.new(key, signed, hashlib.sha256).digest()).decode()

    # Several "v1,<base64>" entries during a secret rotation: any one may match.
    for entry in signature.split(" "):
        version, _, mac = entry.partition(",")
        if version == "v1" and hmac.compare_digest(expected, mac):
            return True

    return False
