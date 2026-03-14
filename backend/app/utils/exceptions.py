class LexofficeAuthError(Exception):
    """Raised when the Lexoffice API key is invalid or expired."""


class LexofficeRateLimitError(Exception):
    """Raised when the Lexoffice rate limit is exceeded after retries."""


class LexofficeApiError(Exception):
    """Raised on unexpected Lexoffice API errors."""

    def __init__(self, message: str = "Lexoffice API error", status_code: int = 0):
        self.status_code = status_code
        super().__init__(message)


class StripeAPIError(Exception):
    """Raised on Stripe API errors."""


class InvalidIBANError(Exception):
    """Raised when an IBAN fails validation."""
