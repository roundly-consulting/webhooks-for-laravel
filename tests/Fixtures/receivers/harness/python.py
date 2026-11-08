# Reads {secret, id, timestamp, signature, body (base64), now} as JSON on stdin; prints valid / invalid.
import base64
import json
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), ".."))
from verify import verify_webhook  # noqa: E402

data = json.load(sys.stdin)
valid = verify_webhook(data["secret"], data["id"], data["timestamp"], data["signature"], base64.b64decode(data["body"]), data["now"])
sys.stdout.write("valid" if valid else "invalid")
