# ---------------------------------------------------------------------------
# Base
# ---------------------------------------------------------------------------

class AppError(Exception):
    """Base class for all application-level errors."""

    def __init__(self, message: str = "An error occurred", details: dict | None = None):
        self.message = message
        self.details = details or {}
        super().__init__(message)


# ---------------------------------------------------------------------------
# Lexoffice errors
# ---------------------------------------------------------------------------

class LexofficeError(AppError):
    """Base class for Lexoffice-related errors."""


class LexofficeAuthError(LexofficeError):
    """Raised when the Lexoffice API key is invalid or expired."""

    def __init__(self, message: str = "Lexoffice API key is invalid or expired"):
        super().__init__(message)


class LexofficeRateLimitError(LexofficeError):
    """Raised when the Lexoffice rate limit is exceeded after retries."""

    def __init__(self, message: str = "Lexoffice rate limit exceeded"):
        super().__init__(message)


class LexofficeApiError(LexofficeError):
    """Raised on unexpected Lexoffice API errors."""

    def __init__(self, message: str = "Lexoffice API error", status_code: int = 0):
        self.status_code = status_code
        super().__init__(message, {"status_code": status_code})


# ---------------------------------------------------------------------------
# Stripe errors
# ---------------------------------------------------------------------------

class StripeError(AppError):
    """Base class for Stripe-related errors."""


class StripeAuthError(StripeError):
    """Raised when Stripe credentials are invalid."""

    def __init__(self, message: str = "Stripe credentials are invalid"):
        super().__init__(message)


class StripePaymentError(StripeError):
    """Raised when a Stripe payment operation fails."""

    def __init__(self, message: str = "Stripe payment failed", decline_code: str | None = None):
        details = {}
        if decline_code:
            details["decline_code"] = decline_code
        super().__init__(message, details)


# Backward-compatible alias
StripeAPIError = StripeError


# ---------------------------------------------------------------------------
# Validation errors
# ---------------------------------------------------------------------------

class ValidationError(AppError):
    """Base class for domain validation errors."""


class IbanError(ValidationError):
    """Raised when an IBAN fails validation."""

    def __init__(self, message: str = "Invalid IBAN"):
        super().__init__(message)


class MandateError(ValidationError):
    """Raised when a SEPA mandate is missing or invalid."""

    def __init__(self, message: str = "SEPA mandate error"):
        super().__init__(message)


# Backward-compatible alias
InvalidIBANError = IbanError


# ---------------------------------------------------------------------------
# Tenant errors
# ---------------------------------------------------------------------------

class TenantError(AppError):
    """Base class for tenant-related errors."""


class TenantNotFoundError(TenantError):
    """Raised when a tenant does not exist."""

    def __init__(self, tenant_id: str | None = None):
        msg = f"Tenant not found: {tenant_id}" if tenant_id else "Tenant not found"
        super().__init__(msg, {"tenant_id": tenant_id} if tenant_id else {})


class TenantNotAuthorizedError(TenantError):
    """Raised when a tenant is not authorized for an action."""

    def __init__(self, message: str = "Tenant not authorized"):
        super().__init__(message)
