import asyncio
import logging
from contextlib import asynccontextmanager

from fastapi import FastAPI, Request
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse

from app.config import settings
from app.database import engine
from app.middleware.rate_limiter import RateLimitMiddleware
from app.middleware.request_logger import RequestLoggerMiddleware
from app.routers import auth, collections, customers, dashboard, integrations, invoices, webhooks
from app.tasks.sync_task import background_sync_loop
from app.utils.exceptions import (
    AppError,
    IbanError,
    LexofficeApiError,
    LexofficeAuthError,
    LexofficeRateLimitError,
    MandateError,
    StripeAuthError,
    StripePaymentError,
    TenantNotAuthorizedError,
    TenantNotFoundError,
    ValidationError,
)
from app.utils.logging_config import setup_logging

setup_logging(settings.LOG_LEVEL)
logger = logging.getLogger(__name__)


@asynccontextmanager
async def lifespan(app: FastAPI):
    task = asyncio.create_task(background_sync_loop())
    logger.info("Hintergrund-Sync-Task erstellt")
    try:
        yield
    finally:
        task.cancel()
        try:
            await task
        except asyncio.CancelledError:
            pass
        await engine.dispose()


app = FastAPI(
    title="LexSEPA API",
    version="0.1.0",
    lifespan=lifespan,
    root_path="/api",
)

# ---------------------------------------------------------------------------
# Middleware (order matters – outermost first)
# ---------------------------------------------------------------------------

app.add_middleware(
    CORSMiddleware,
    allow_origins=settings.CORS_ORIGINS,
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)
app.add_middleware(RateLimitMiddleware)
app.add_middleware(RequestLoggerMiddleware)

# ---------------------------------------------------------------------------
# Exception handlers
# ---------------------------------------------------------------------------

def _error_response(status: int, error: str, message: str, details: dict | None = None) -> JSONResponse:
    return JSONResponse(
        status_code=status,
        content={"error": error, "message": message, "details": details or {}},
    )


@app.exception_handler(LexofficeAuthError)
async def lexoffice_auth_handler(request: Request, exc: LexofficeAuthError):
    logger.warning("Lexoffice auth error", extra={"path": request.url.path})
    return _error_response(401, "lexoffice_auth_error", str(exc))


@app.exception_handler(LexofficeRateLimitError)
async def lexoffice_rate_limit_handler(request: Request, exc: LexofficeRateLimitError):
    logger.warning("Lexoffice rate limit", extra={"path": request.url.path})
    return _error_response(429, "lexoffice_rate_limit", str(exc))


@app.exception_handler(LexofficeApiError)
async def lexoffice_api_handler(request: Request, exc: LexofficeApiError):
    logger.error("Lexoffice API error", extra={"status_code": exc.status_code})
    return _error_response(502, "lexoffice_api_error", str(exc), exc.details)


@app.exception_handler(StripeAuthError)
async def stripe_auth_handler(request: Request, exc: StripeAuthError):
    logger.warning("Stripe auth error")
    return _error_response(401, "stripe_auth_error", str(exc))


@app.exception_handler(StripePaymentError)
async def stripe_payment_handler(request: Request, exc: StripePaymentError):
    logger.warning("Stripe payment error", extra={"details": exc.details})
    return _error_response(422, "stripe_payment_error", str(exc), exc.details)


@app.exception_handler(IbanError)
async def iban_error_handler(request: Request, exc: IbanError):
    return _error_response(422, "iban_error", str(exc))


@app.exception_handler(MandateError)
async def mandate_error_handler(request: Request, exc: MandateError):
    return _error_response(422, "mandate_error", str(exc))


@app.exception_handler(ValidationError)
async def validation_error_handler(request: Request, exc: ValidationError):
    return _error_response(422, "validation_error", str(exc), exc.details)


@app.exception_handler(TenantNotFoundError)
async def tenant_not_found_handler(request: Request, exc: TenantNotFoundError):
    return _error_response(404, "tenant_not_found", str(exc))


@app.exception_handler(TenantNotAuthorizedError)
async def tenant_not_authorized_handler(request: Request, exc: TenantNotAuthorizedError):
    return _error_response(403, "tenant_not_authorized", str(exc))


@app.exception_handler(AppError)
async def app_error_handler(request: Request, exc: AppError):
    logger.error("Application error", extra={"message": exc.message, "details": exc.details})
    return _error_response(500, "app_error", exc.message, exc.details)


@app.exception_handler(Exception)
async def unhandled_exception_handler(request: Request, exc: Exception):
    logger.exception("Unhandled exception", extra={"path": request.url.path})
    return _error_response(500, "internal_error", "Ein interner Fehler ist aufgetreten.")


# ---------------------------------------------------------------------------
# Routers
# ---------------------------------------------------------------------------

app.include_router(auth.router)
app.include_router(integrations.router)
app.include_router(invoices.router)
app.include_router(customers.router)
app.include_router(collections.router)
app.include_router(dashboard.router)
app.include_router(webhooks.router)


@app.get("/health")
async def health_check():
    return {"status": "ok"}
