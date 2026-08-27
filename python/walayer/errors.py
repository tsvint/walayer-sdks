"""Error type for the WALayer client."""
from __future__ import annotations

from typing import Any, Optional


class WALayerError(Exception):
    """Raised for any non-2xx API response, carrying the WALayer error code."""

    def __init__(
        self,
        status: int,
        code: str,
        message: str,
        detail: Any = None,
        request_id: Optional[str] = None,
    ) -> None:
        super().__init__(f"{code}: {message}")
        self.status = status
        self.code = code
        self.detail = detail
        self.request_id = request_id
