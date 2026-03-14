# CRITICAL: set env vars BEFORE any app module is imported so that
# pydantic-settings picks them up when Settings() is instantiated.
import os

os.environ.setdefault("DATABASE_URL", "sqlite+aiosqlite:///:memory:")
# 32 zero bytes, URL-safe base64 – valid Fernet key for tests
os.environ.setdefault("ENCRYPTION_KEY", "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=")
os.environ.setdefault("JWT_SECRET_KEY", "test-jwt-secret-key-minimum-length-ok-pytest")
os.environ.setdefault("JWT_ACCESS_TOKEN_EXPIRE_MINUTES", "30")
os.environ.setdefault("JWT_REFRESH_TOKEN_EXPIRE_DAYS", "7")
os.environ.setdefault("LOG_LEVEL", "WARNING")
os.environ.setdefault("STRIPE_WEBHOOK_SECRET", "")
os.environ.setdefault("CORS_ORIGINS", '["http://localhost:5173"]')
os.environ.setdefault("LEXOFFICE_API_URL", "https://api.lexoffice.io/v1")

import uuid
from typing import AsyncGenerator
from unittest.mock import patch

import pytest
import pytest_asyncio
from httpx import ASGITransport, AsyncClient
from sqlalchemy.ext.asyncio import AsyncSession, async_sessionmaker, create_async_engine
from sqlalchemy.pool import StaticPool

from app.database import get_db
from app.main import app
from app.models import Base
from app.models.customer import Customer
from app.models.customer_iban import CustomerIban
from app.models.integration import Integration
from app.models.invoice import CollectionStatus, Invoice
from app.models.user import User
from app.utils.security import create_access_token, hash_password

# ---------------------------------------------------------------------------
# Background-sync stub (prevents asyncio.create_task from touching real DB)
# ---------------------------------------------------------------------------


async def _noop_sync_loop() -> None:
    pass


# ---------------------------------------------------------------------------
# Per-test in-memory SQLite database
# ---------------------------------------------------------------------------


@pytest_asyncio.fixture
async def db() -> AsyncGenerator[AsyncSession, None]:
    """Fresh SQLite in-memory DB for every test, rolled back on teardown."""
    engine = create_async_engine(
        "sqlite+aiosqlite:///:memory:",
        connect_args={"check_same_thread": False},
        poolclass=StaticPool,
    )
    async with engine.begin() as conn:
        await conn.run_sync(Base.metadata.create_all)

    session_factory = async_sessionmaker(engine, class_=AsyncSession, expire_on_commit=False)
    async with session_factory() as session:
        yield session
        await session.rollback()

    await engine.dispose()


# ---------------------------------------------------------------------------
# HTTP test client
# ---------------------------------------------------------------------------


@pytest_asyncio.fixture
async def client(db: AsyncSession) -> AsyncGenerator[AsyncClient, None]:
    """AsyncClient backed by the FastAPI app.

    - ``get_db`` is overridden to use the test session.
    - The background sync loop is replaced with a no-op.
    - The webhook router's direct ``get_db`` call is also patched.
    """

    async def _override_get_db():
        yield db

    app.dependency_overrides[get_db] = _override_get_db

    with (
        patch("app.main.background_sync_loop", new=_noop_sync_loop),
        patch("app.routers.webhooks.get_db", new=_override_get_db),
    ):
        async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as ac:
            yield ac

    app.dependency_overrides.clear()


# ---------------------------------------------------------------------------
# Reset module-level rate-limit state between tests
# ---------------------------------------------------------------------------


@pytest.fixture(autouse=True)
def reset_rate_limit_state():
    """Clear in-memory dictionaries that persist between tests."""
    import app.routers.invoices as inv_router
    from app.middleware.rate_limiter import _auth_limiter, _global_limiter, _submit_limiter

    inv_router._last_manual_sync.clear()
    _auth_limiter._buckets.clear()
    _submit_limiter._buckets.clear()
    _global_limiter._buckets.clear()
    yield
    inv_router._last_manual_sync.clear()
    _auth_limiter._buckets.clear()
    _submit_limiter._buckets.clear()
    _global_limiter._buckets.clear()


# ---------------------------------------------------------------------------
# User / Integration fixtures
# ---------------------------------------------------------------------------


@pytest_asyncio.fixture
async def test_user(db: AsyncSession) -> User:
    """Tenant A – primary test user."""
    user = User(
        email="tenant@example.com",
        hashed_password=hash_password("SecurePass123"),
        company_name="Test GmbH",
    )
    db.add(user)
    await db.flush()
    db.add(Integration(tenant_id=user.id))
    await db.flush()
    await db.refresh(user)
    return user


@pytest_asyncio.fixture
async def test_user2(db: AsyncSession) -> User:
    """Tenant B – second user for isolation tests."""
    user = User(
        email="other@example.com",
        hashed_password=hash_password("SecurePass123"),
        company_name="Other GmbH",
    )
    db.add(user)
    await db.flush()
    db.add(Integration(tenant_id=user.id))
    await db.flush()
    await db.refresh(user)
    return user


@pytest_asyncio.fixture
async def test_integration(db: AsyncSession, test_user: User) -> Integration:
    """Connect both Lexoffice and Stripe for test_user."""
    from sqlalchemy import select

    result = await db.execute(select(Integration).where(Integration.tenant_id == test_user.id))
    integ = result.scalar_one()
    integ.lexoffice_api_key = "lex-key-abc123"
    integ.lexoffice_connected = True
    integ.stripe_secret_key = "sk_test_abc123"
    integ.stripe_webhook_secret = "whsec_test_secret_xyz"
    integ.stripe_connected = True
    await db.flush()
    return integ


# ---------------------------------------------------------------------------
# Factory fixtures
# ---------------------------------------------------------------------------


@pytest_asyncio.fixture
async def create_customer(db: AsyncSession):
    async def _make(
        tenant_id: str,
        *,
        name: str = "Erika Mustermann",
        customer_number: str = "10001",
        is_walk_in: bool = False,
        email: str | None = "erika@example.com",
        lexoffice_contact_id: str | None = None,
    ) -> Customer:
        c = Customer(
            tenant_id=tenant_id,
            customer_number=customer_number,
            name=name,
            email=email,
            is_walk_in=is_walk_in,
            lexoffice_contact_id=lexoffice_contact_id or str(uuid.uuid4()),
        )
        db.add(c)
        await db.flush()
        await db.refresh(c)
        return c

    return _make


@pytest_asyncio.fixture
async def create_invoice(db: AsyncSession):
    async def _make(
        tenant_id: str,
        customer_id: str | None = None,
        *,
        voucher_number: str = "RE-2026-0001",
        amount: float = 250.00,
        lexoffice_status: str = "open",
        collection_status: CollectionStatus = CollectionStatus.OPEN,
        lexoffice_invoice_id: str | None = None,
    ) -> Invoice:
        inv = Invoice(
            tenant_id=tenant_id,
            lexoffice_invoice_id=lexoffice_invoice_id or str(uuid.uuid4()),
            voucher_number=voucher_number,
            customer_id=customer_id,
            contact_name="Erika Mustermann",
            total_gross_amount=amount,
            currency="EUR",
            lexoffice_status=lexoffice_status,
            collection_status=collection_status,
        )
        db.add(inv)
        await db.flush()
        await db.refresh(inv)
        return inv

    return _make


@pytest_asyncio.fixture
async def create_iban(db: AsyncSession):
    async def _make(
        tenant_id: str,
        customer_id: str,
        *,
        iban: str = "DE89370400440532013000",
        account_holder_name: str = "Erika Mustermann",
        is_active: bool = True,
    ) -> CustomerIban:
        record = CustomerIban(
            tenant_id=tenant_id,
            customer_id=customer_id,
            iban=iban,
            account_holder_name=account_holder_name,
            is_active=is_active,
        )
        db.add(record)
        await db.flush()
        await db.refresh(record)
        return record

    return _make


# ---------------------------------------------------------------------------
# Auth helper – available as a fixture so it's easy to request
# ---------------------------------------------------------------------------


@pytest.fixture
def auth_headers():
    """Returns a callable: auth_headers(user_id) -> dict."""

    def _make(user_id: str) -> dict[str, str]:
        return {"Authorization": f"Bearer {create_access_token(user_id)}"}

    return _make
