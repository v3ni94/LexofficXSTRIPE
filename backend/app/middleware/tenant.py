from starlette.middleware.base import BaseHTTPMiddleware, RequestResponseEndpoint
from starlette.requests import Request
from starlette.responses import Response


class TenantMiddleware(BaseHTTPMiddleware):
    """Extracts tenant (user) context from JWT and attaches it to request state."""

    async def dispatch(self, request: Request, call_next: RequestResponseEndpoint) -> Response:
        # Tenant isolation is handled via the get_current_user dependency
        # which extracts user_id from the JWT token.
        # This middleware can be extended for additional tenant-level logic.
        response = await call_next(request)
        return response
