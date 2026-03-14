import logging
import time
from collections.abc import Generator

import httpx

from app.utils.exceptions import (
    LexofficeApiError,
    LexofficeAuthError,
    LexofficeRateLimitError,
)

logger = logging.getLogger(__name__)

BASE_URL = "https://api.lexoffice.io/v1"
MIN_REQUEST_INTERVAL = 0.6  # seconds – keeps us under 2 req/s


class LexofficeService:
    def __init__(self, api_key: str) -> None:
        self._api_key = api_key
        self._last_request_time: float = 0.0
        self._client: httpx.Client | None = None

    # ------------------------------------------------------------------
    # Context manager for connection reuse
    # ------------------------------------------------------------------

    def __enter__(self):
        self._client = httpx.Client(
            base_url=BASE_URL,
            headers={"Authorization": f"Bearer {self._api_key}"},
            timeout=15.0,
        )
        return self

    def __exit__(self, *exc):
        if self._client:
            self._client.close()
            self._client = None

    @property
    def client(self) -> httpx.Client:
        if self._client is None:
            self._client = httpx.Client(
                base_url=BASE_URL,
                headers={"Authorization": f"Bearer {self._api_key}"},
                timeout=15.0,
            )
        return self._client

    # ------------------------------------------------------------------
    # Core request method with throttling + retries
    # ------------------------------------------------------------------

    def _throttle(self) -> None:
        elapsed = time.monotonic() - self._last_request_time
        if elapsed < MIN_REQUEST_INTERVAL:
            time.sleep(MIN_REQUEST_INTERVAL - elapsed)

    def _request(
        self,
        method: str,
        endpoint: str,
        params: dict | None = None,
    ) -> dict:
        max_retries_429 = 3
        max_retries_5xx = 2

        retries_429 = 0
        retries_5xx = 0

        while True:
            self._throttle()
            self._last_request_time = time.monotonic()

            try:
                resp = self.client.request(method, endpoint, params=params)
            except httpx.RequestError as e:
                raise LexofficeApiError(f"Verbindungsfehler: {e}") from e

            if resp.status_code == 200:
                return resp.json()

            if resp.status_code == 401:
                raise LexofficeAuthError(
                    "Lexoffice API-Key ungueltig oder abgelaufen"
                )

            if resp.status_code == 429:
                retries_429 += 1
                if retries_429 > max_retries_429:
                    raise LexofficeRateLimitError(
                        "Lexoffice Rate-Limit nach 3 Versuchen ueberschritten"
                    )
                wait = 2 ** retries_429  # 2, 4, 8 seconds
                logger.warning("Lexoffice 429 – warte %ss (Versuch %s)", wait, retries_429)
                time.sleep(wait)
                continue

            if resp.status_code in (500, 502, 503):
                retries_5xx += 1
                if retries_5xx > max_retries_5xx:
                    raise LexofficeApiError(
                        f"Lexoffice Serverfehler {resp.status_code} nach Retries",
                        status_code=resp.status_code,
                    )
                wait = 2 ** retries_5xx
                logger.warning(
                    "Lexoffice %s – warte %ss (Versuch %s)",
                    resp.status_code, wait, retries_5xx,
                )
                time.sleep(wait)
                continue

            raise LexofficeApiError(
                f"Unerwarteter Lexoffice-Status {resp.status_code}: {resp.text[:200]}",
                status_code=resp.status_code,
            )

    # ------------------------------------------------------------------
    # API methods
    # ------------------------------------------------------------------

    def get_profile(self) -> dict:
        """GET /profile – connection test."""
        return self._request("GET", "/profile")

    def get_open_invoices_paginated(self) -> Generator[dict, None, None]:
        """Yield all open + overdue invoice vouchers across all pages."""
        # Lexoffice voucherlist only accepts one status at a time for
        # certain statuses. We query "open" and "overdue" separately.
        for voucher_status in ("open", "overdue"):
            page = 0
            while True:
                data = self._request(
                    "GET",
                    "/voucherlist",
                    params={
                        "voucherType": "invoice",
                        "voucherStatus": voucher_status,
                        "size": 100,
                        "page": page,
                    },
                )
                content = data.get("content", [])
                for voucher in content:
                    yield voucher

                total_pages = data.get("totalPages", 1)
                page += 1
                if page >= total_pages:
                    break

    def get_invoice_detail(self, invoice_id: str) -> dict:
        """GET /invoices/{id} – full invoice data."""
        return self._request("GET", f"/invoices/{invoice_id}")

    def get_contact(self, contact_id: str) -> dict:
        """GET /contacts/{id} – contact/customer data."""
        return self._request("GET", f"/contacts/{contact_id}")
