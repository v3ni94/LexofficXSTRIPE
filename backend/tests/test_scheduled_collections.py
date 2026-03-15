"""Tests for scheduled collection functionality."""
import json
from datetime import date, timedelta
from unittest.mock import MagicMock, patch

import pytest
from sqlalchemy.ext.asyncio import AsyncSession

from app.models.integration import Integration
from app.models.invoice import CollectionStatus, Invoice
from app.models.organization import Organization
from app.models.payment_collection import PaymentCollection
from app.models.user import User
from app.services.collection_service import CollectionError, CollectionService


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------


def _stripe_mocks():
    mock_customer = MagicMock()
    mock_customer.id = "cus_test_abc"
    mock_pm = MagicMock()
    mock_pm.id = "pm_test_xyz"
    mock_pi = MagicMock()
    mock_pi.id = "pi_test_789"

    mock_svc = MagicMock()
    mock_svc.find_or_create_customer.return_value = mock_customer
    mock_svc.create_sepa_payment_method.return_value = mock_pm
    mock_svc.attach_payment_method.return_value = None
    mock_svc.create_payment_intent.return_value = mock_pi
    return mock_svc


def _next_weekday() -> date:
    """Return the next weekday (Mon-Fri) that is at least 1 day in the future."""
    d = date.today() + timedelta(days=1)
    while d.weekday() >= 5:
        d += timedelta(days=1)
    return d


def _next_weekend() -> date:
    """Return the next Saturday."""
    d = date.today() + timedelta(days=1)
    while d.weekday() != 5:
        d += timedelta(days=1)
    return d


# ---------------------------------------------------------------------------
# Scheduled submission
# ---------------------------------------------------------------------------


async def test_submit_scheduled_collection(
    db: AsyncSession,
    test_user: User,
    test_org: Organization,
    test_integration: Integration,
    create_customer,
    create_invoice,
    create_iban,
):
    """Scheduled collection should NOT call Stripe, set is_scheduled=True."""
    customer = await create_customer(test_org.id, customer_number="20001")
    invoice = await create_invoice(test_org.id, customer.id, amount=200.00)
    await create_iban(test_org.id, customer.id)

    future_date = _next_weekday()

    # No Stripe mock needed — it should not be called
    collection = await CollectionService.submit_collection(
        tenant_id=test_org.id,
        invoice_id=invoice.id,
        db=db,
        scheduled_date=future_date,
    )

    assert collection.is_scheduled is True
    assert collection.scheduled_date == future_date
    assert collection.scheduled_submitted is False
    assert collection.stripe_status == "scheduled"
    assert collection.stripe_payment_intent_id is None

    # Invoice status should be SCHEDULED
    await db.refresh(invoice)
    assert invoice.collection_status == CollectionStatus.SCHEDULED


async def test_submit_scheduled_rejects_past_date(
    db: AsyncSession,
    test_user: User,
    test_org: Organization,
    test_integration: Integration,
    create_customer,
    create_invoice,
    create_iban,
):
    customer = await create_customer(test_org.id, customer_number="20002")
    invoice = await create_invoice(test_org.id, customer.id)
    await create_iban(test_org.id, customer.id)

    yesterday = date.today() - timedelta(days=1)

    with pytest.raises(CollectionError, match="Zukunft"):
        await CollectionService.submit_collection(
            test_org.id, invoice.id, db, scheduled_date=yesterday
        )


async def test_submit_scheduled_rejects_today(
    db: AsyncSession,
    test_user: User,
    test_org: Organization,
    test_integration: Integration,
    create_customer,
    create_invoice,
    create_iban,
):
    customer = await create_customer(test_org.id, customer_number="20003")
    invoice = await create_invoice(test_org.id, customer.id)
    await create_iban(test_org.id, customer.id)

    with pytest.raises(CollectionError, match="Zukunft"):
        await CollectionService.submit_collection(
            test_org.id, invoice.id, db, scheduled_date=date.today()
        )


async def test_submit_scheduled_rejects_weekend(
    db: AsyncSession,
    test_user: User,
    test_org: Organization,
    test_integration: Integration,
    create_customer,
    create_invoice,
    create_iban,
):
    customer = await create_customer(test_org.id, customer_number="20004")
    invoice = await create_invoice(test_org.id, customer.id)
    await create_iban(test_org.id, customer.id)

    weekend = _next_weekend()

    with pytest.raises(CollectionError, match="Werktagen"):
        await CollectionService.submit_collection(
            test_org.id, invoice.id, db, scheduled_date=weekend
        )


async def test_submit_scheduled_rejects_too_far_future(
    db: AsyncSession,
    test_user: User,
    test_org: Organization,
    test_integration: Integration,
    create_customer,
    create_invoice,
    create_iban,
):
    customer = await create_customer(test_org.id, customer_number="20005")
    invoice = await create_invoice(test_org.id, customer.id)
    await create_iban(test_org.id, customer.id)

    far_future = date.today() + timedelta(days=400)
    # Adjust to weekday
    while far_future.weekday() >= 5:
        far_future += timedelta(days=1)

    with pytest.raises(CollectionError, match="365 Tage"):
        await CollectionService.submit_collection(
            test_org.id, invoice.id, db, scheduled_date=far_future
        )


# ---------------------------------------------------------------------------
# Cancel scheduled
# ---------------------------------------------------------------------------


async def test_cancel_scheduled_collection(
    db: AsyncSession,
    test_user: User,
    test_org: Organization,
    test_integration: Integration,
    create_customer,
    create_invoice,
    create_iban,
):
    customer = await create_customer(test_org.id, customer_number="20010")
    invoice = await create_invoice(test_org.id, customer.id, amount=100.00)
    await create_iban(test_org.id, customer.id)

    future_date = _next_weekday()
    collection = await CollectionService.submit_collection(
        test_org.id, invoice.id, db, scheduled_date=future_date
    )

    cancelled = await CollectionService.cancel_scheduled_collection(
        test_org.id, collection.id, db
    )

    assert cancelled.stripe_status == "cancelled"

    # Invoice should be back to OPEN
    await db.refresh(invoice)
    assert invoice.collection_status == CollectionStatus.OPEN


async def test_cancel_non_scheduled_fails(
    db: AsyncSession,
    test_user: User,
    test_org: Organization,
    test_integration: Integration,
    create_customer,
    create_invoice,
    create_iban,
):
    customer = await create_customer(test_org.id, customer_number="20011")
    invoice = await create_invoice(test_org.id, customer.id, amount=100.00)
    await create_iban(test_org.id, customer.id)

    mock_svc = _stripe_mocks()
    with patch("app.services.collection_service.StripeService", return_value=mock_svc):
        collection = await CollectionService.submit_collection(
            test_org.id, invoice.id, db
        )

    with pytest.raises(CollectionError, match="terminierte"):
        await CollectionService.cancel_scheduled_collection(
            test_org.id, collection.id, db
        )


# ---------------------------------------------------------------------------
# Reschedule
# ---------------------------------------------------------------------------


async def test_reschedule_collection(
    db: AsyncSession,
    test_user: User,
    test_org: Organization,
    test_integration: Integration,
    create_customer,
    create_invoice,
    create_iban,
):
    customer = await create_customer(test_org.id, customer_number="20020")
    invoice = await create_invoice(test_org.id, customer.id, amount=100.00)
    await create_iban(test_org.id, customer.id)

    original_date = _next_weekday()
    collection = await CollectionService.submit_collection(
        test_org.id, invoice.id, db, scheduled_date=original_date
    )

    # Pick a different weekday
    new_date = original_date + timedelta(days=7)
    while new_date.weekday() >= 5:
        new_date += timedelta(days=1)

    rescheduled = await CollectionService.reschedule_collection(
        test_org.id, collection.id, new_date, db
    )

    assert rescheduled.scheduled_date == new_date
    assert rescheduled.is_scheduled is True


async def test_reschedule_already_submitted_fails(
    db: AsyncSession,
    test_user: User,
    test_org: Organization,
    test_integration: Integration,
    create_customer,
    create_invoice,
    create_iban,
):
    customer = await create_customer(test_org.id, customer_number="20021")
    invoice = await create_invoice(test_org.id, customer.id, amount=100.00)
    await create_iban(test_org.id, customer.id)

    future_date = _next_weekday()
    collection = await CollectionService.submit_collection(
        test_org.id, invoice.id, db, scheduled_date=future_date
    )

    # Simulate that it was already submitted to Stripe
    collection.scheduled_submitted = True
    await db.flush()

    new_date = future_date + timedelta(days=7)
    while new_date.weekday() >= 5:
        new_date += timedelta(days=1)

    with pytest.raises(CollectionError, match="bereits bei Stripe"):
        await CollectionService.reschedule_collection(
            test_org.id, collection.id, new_date, db
        )
