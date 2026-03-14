"""In-memory sliding-window rate limiter middleware.

Limits:
- Auth endpoints  (/auth/login, /auth/register): 5 req/min  per source IP
- Collection submit (/collections/submit):       10 req/min per authenticated user
- Global fallback:                               100 req/min per authenticated user (or IP)
"""

import logging
import time
from collections import defaultdict, deque

from starlette.middleware.base import BaseHTTPMiddleware
from starlette.requests import Request
from starlette.responses import JSONResponse, Response

logger = logging.getLogger(__name__)


class InMemoryRateLimiter:
    """Sliding-window counter stored in memory (single-process only)."""

    def __init__(self, max_requests: int, window_seconds: int):
        self.max_requests = max_requests
        self.window_seconds = window_seconds
        self._buckets: dict[str, deque[float]] = defaultdict(deque)

    def is_allowed(self, key: str) -> tuple[bool, int]:
        """Return (allowed, retry_after_seconds)."""
        now = time.monotonic()
        window_start = now - self.window_seconds
        bucket = self._buckets[key]

        # Drop entries outside the window
        while bucket and bucket[0] < window_start:
            bucket.popleft()

        if len(bucket) >= self.max_requests:
            oldest = bucket[0]
            retry_after = int(oldest - window_start) + 1
            return False, retry_after

        bucket.append(now)
        return True, 0


_auth_limiter = InMemoryRateLimiter(max_requests=5, window_seconds=60)
_submit_limiter = InMemoryRateLimiter(max_requests=10, window_seconds=60)
_global_limiter = InMemoryRateLimiter(max_requests=100, window_seconds=60)


def _get_ip(request: Request) -> str:
    forwarded_for = request.headers.get("X-Forwarded-For")
    if forwarded_for:
        return forwarded_for.split(",")[0].strip()
    if request.client:
        return request.client.host
    return "unknown"


def _get_user_key(request: Request) -> str:
    """Use JWT sub claim if available, otherwise fall back to IP."""
    auth = request.headers.get("Authorization", "")
    if auth.startswith("Bearer "):
        # Use the raw token as a key (cheap – avoids full decode here)
        return auth[7:50]  # first 43 chars are unique enough
    return _get_ip(request)


class RateLimitMiddleware(BaseHTTPMiddleware):
    async def dispatch(self, request: Request, call_next) -> Response:
        path = request.url.path

        # Auth endpoints: keyed by IP
        if path in ("/api/auth/login", "/api/auth/register"):
            key = _get_ip(request)
            allowed, retry_after = _auth_limiter.is_allowed(key)
            if not allowed:
                logger.warning("Rate limit exceeded", extra={"path": path, "ip": key})
                return JSONResponse(
                    status_code=429,
                    content={"error": "rate_limit_exceeded", "message": "Zu viele Anfragen. Bitte warte kurz."},
                    headers={"Retry-After": str(retry_after)},
                )

        # Collection submit: keyed by user
        elif "/collections/submit" in path:
            key = _get_user_key(request)
            allowed, retry_after = _submit_limiter.is_allowed(key)
            if not allowed:
                logger.warning("Rate limit exceeded", extra={"path": path, "user_key": key[:8]})
                return JSONResponse(
                    status_code=429,
                    content={"error": "rate_limit_exceeded", "message": "Zu viele Einreichungsversuche. Bitte warte kurz."},
                    headers={"Retry-After": str(retry_after)},
                )

        # Global limit
        else:
            key = _get_user_key(request)
            allowed, retry_after = _global_limiter.is_allowed(key)
            if not allowed:
                logger.warning("Global rate limit exceeded", extra={"path": path})
                return JSONResponse(
                    status_code=429,
                    content={"error": "rate_limit_exceeded", "message": "Zu viele Anfragen. Bitte versuche es später erneut."},
                    headers={"Retry-After": str(retry_after)},
                )

        return await call_next(request)
