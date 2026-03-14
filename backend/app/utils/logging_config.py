import logging
import re
import sys

from pythonjsonlogger.jsonlogger import JsonFormatter


# ---------------------------------------------------------------------------
# IBAN masking
# ---------------------------------------------------------------------------

_IBAN_RE = re.compile(r"\b([A-Z]{2}\d{2}[A-Z0-9]{4})\d{6,}([A-Z0-9]{0,4})\b")


def mask_iban(value: str) -> str:
    """Replace middle digits of IBAN with asterisks."""
    return _IBAN_RE.sub(lambda m: f"{m.group(1)}****{m.group(2)}", value)


# ---------------------------------------------------------------------------
# Log record filter – strips sensitive fields
# ---------------------------------------------------------------------------

_SENSITIVE_KEYS = {"password", "api_key", "secret", "token", "iban", "authorization"}


class SensitiveDataFilter(logging.Filter):
    """Mask sensitive fields in log records before emission."""

    def filter(self, record: logging.LogRecord) -> bool:
        # Mask string message
        if isinstance(record.msg, str):
            record.msg = mask_iban(record.msg)

        # Mask extra dict fields
        for key in list(vars(record).keys()):
            if key.lower() in _SENSITIVE_KEYS:
                setattr(record, key, "***REDACTED***")

        return True


# ---------------------------------------------------------------------------
# Setup
# ---------------------------------------------------------------------------

def setup_logging(level: str = "INFO") -> None:
    """Configure root logger with JSON output and sensitive-data filtering."""
    numeric_level = getattr(logging, level.upper(), logging.INFO)

    handler = logging.StreamHandler(sys.stdout)
    formatter = JsonFormatter(
        fmt="%(asctime)s %(levelname)s %(name)s %(message)s",
        datefmt="%Y-%m-%dT%H:%M:%S",
    )
    handler.setFormatter(formatter)
    handler.addFilter(SensitiveDataFilter())

    root = logging.getLogger()
    root.setLevel(numeric_level)
    # Remove any existing handlers so we don't duplicate output
    root.handlers.clear()
    root.addHandler(handler)

    # Quieten noisy third-party loggers
    logging.getLogger("uvicorn.access").setLevel(logging.WARNING)
    logging.getLogger("sqlalchemy.engine").setLevel(logging.WARNING)
