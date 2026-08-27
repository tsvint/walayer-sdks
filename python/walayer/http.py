"""HTTP transport for the WALayer client.

The transport is injectable so tests (and alternative HTTP stacks) can swap it
without touching client logic — the same pattern as the Node SDK's injectable
``fetch``. The default uses the standard library only, so the package has zero
runtime dependencies.
"""
from __future__ import annotations

import json
from urllib.parse import urlencode
import urllib.error
import urllib.request
from typing import Any, Callable, Dict, Optional, Tuple

from .errors import WALayerError

# (method, url, headers, body_bytes) -> (status, response_headers, text)
Transport = Callable[[str, str, Dict[str, str], Optional[bytes]], Tuple[int, Dict[str, str], str]]


def default_transport(
    method: str, url: str, headers: Dict[str, str], body: Optional[bytes]
) -> Tuple[int, Dict[str, str], str]:
    req = urllib.request.Request(url, data=body, method=method, headers=headers)
    try:
        with urllib.request.urlopen(req) as resp:  # noqa: S310 (trusted base_url)
            return resp.status, {k.lower(): v for k, v in resp.headers.items()}, resp.read().decode("utf-8")
    except urllib.error.HTTPError as exc:
        text = exc.read().decode("utf-8") if exc.fp else ""
        return exc.code, {k.lower(): v for k, v in exc.headers.items()}, text


class Http:
    """Thin JSON transport: adds the Bearer key, unwraps ``{data}``, and raises a
    typed :class:`WALayerError` on any non-2xx response."""

    def __init__(self, api_key: str, base_url: str = "https://api.walayer.com", transport: Optional[Transport] = None):
        if not api_key:
            raise ValueError("WALayer: api_key is required")
        self._api_key = api_key
        self._base_url = base_url.rstrip("/")
        self._transport = transport or default_transport

    @staticmethod
    def _qs(params: Optional[Dict[str, Any]]) -> str:
        """Build ?a=1&b=2, dropping unset values so callers can pass kwargs through."""
        if not params:
            return ""
        pairs = {k: v for k, v in params.items() if v is not None and v != ""}
        if not pairs:
            return ""
        return "?" + urlencode({k: ("true" if v is True else "false" if v is False else v)
                                for k, v in pairs.items()})

    def request(
        self,
        path: str,
        method: str = "GET",
        body: Any = None,
        extra_headers: Optional[Dict[str, str]] = None,
        params: Optional[Dict[str, Any]] = None,
        envelope: bool = False,
    ) -> Any:
        """
        `envelope=True` returns the whole response body rather than just `data`.

        List endpoints answer `{"data": [...], "next_cursor": ...}`; unwrapping to
        `data` silently discarded the cursor, so a caller could read the first
        page and had no way to ask for the second.
        """
        headers = {"authorization": f"Bearer {self._api_key}"}
        if extra_headers:
            headers.update(extra_headers)
        data: Optional[bytes] = None
        if body is not None:
            headers["content-type"] = "application/json"
            data = json.dumps(body).encode("utf-8")

        url = f"{self._base_url}{path}{self._qs(params)}"
        status, resp_headers, text = self._transport(method, url, headers, data)
        request_id = resp_headers.get("x-request-id")

        if status == 204:
            return None
        parsed = json.loads(text) if text else {}
        if status >= 400:
            err = parsed.get("error", {}) if isinstance(parsed, dict) else {}
            raise WALayerError(status, err.get("code", "UNKNOWN"), err.get("message", ""), err.get("detail"), request_id)
        if envelope:
            return parsed
        return parsed.get("data") if isinstance(parsed, dict) else parsed
