"""Webhook signature verification.

Matches the server scheme (docs/04 §8.3): ``X-Signature: v1,sha256=<hex>`` where
hex = HMAC-SHA256(secret, "{timestamp}.{rawBody}"). Verify against the RAW body —
re-serialized JSON breaks the HMAC.
"""
from __future__ import annotations

import hashlib
import hmac
import time
from dataclasses import dataclass
from typing import Optional, Union

SIGNATURE_SCHEME = "v1"
DEFAULT_TOLERANCE_SECONDS = 300


@dataclass(frozen=True)
class VerifyResult:
    valid: bool
    reason: Optional[str] = None  # "format" | "timestamp" | "signature"


def verify_webhook(
    raw_body: str,
    signature: Optional[str],
    timestamp: Union[int, str, None],
    secret: str,
    tolerance_seconds: int = DEFAULT_TOLERANCE_SECONDS,
    now_seconds: Optional[int] = None,
) -> VerifyResult:
    """Constant-time verification with a replay window."""
    prefix = f"{SIGNATURE_SCHEME},sha256="
    if not signature or not signature.startswith(prefix):
        return VerifyResult(False, "format")

    try:
        ts = int(timestamp) if timestamp is not None else None
    except (TypeError, ValueError):
        ts = None
    now = now_seconds if now_seconds is not None else int(time.time())
    if ts is None or abs(now - ts) > tolerance_seconds:
        return VerifyResult(False, "timestamp")

    mac = hmac.new(secret.encode("utf-8"), f"{ts}.{raw_body}".encode("utf-8"), hashlib.sha256).hexdigest()
    expected = f"{prefix}{mac}"
    if not hmac.compare_digest(signature, expected):
        return VerifyResult(False, "signature")
    return VerifyResult(True)
