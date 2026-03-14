"""Tests for MandateService – mandate reference generation logic."""
from datetime import date, datetime, timezone

import pytest
from sqlalchemy.ext.asyncio import AsyncSession

from app.models.customer import Customer
from app.models.customer_iban import CustomerIban
from app.models.sepa_mandate import SepaMandate
from app.models.user import User
from app.services.mandate_service import MandateService


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------


async def _make_customer_with_iban(
    db: AsyncSession,
    tenant_id: str,
    customer_number: str,
    is_walk_in: bool = False,
) -> tuple[Customer, CustomerIban]:
    customer = Customer(
        tenant_id=tenant_id,
        customer_number=customer_number,
        name="Test Customer",
        is_walk_in=is_walk_in,
    )
    db.add(customer)
    await db.flush()

    iban = CustomerIban(
        tenant_id=tenant_id,
        customer_id=customer.id,
        iban="DE89370400440532013000",
        account_holder_name="Test Customer",
        is_active=True,
    )
    db.add(iban)
    await db.flush()
    await db.refresh(customer)
    await db.refresh(iban)
    return customer, iban


# ---------------------------------------------------------------------------
# Stammkunde: HVM + customer_number
# ---------------------------------------------------------------------------


async def test_stammkunde_mandate_reference_format(db: AsyncSession, test_user: User):
    customer, iban = await _make_customer_with_iban(db, test_user.id, "10045")

    mandate = await MandateService.get_or_create_mandate(
        tenant_id=test_user.id,
        customer_id=customer.id,
        customer_iban_id=iban.id,
        db=db,
    )

    assert mandate.mandate_reference == "HVM10045"
    assert mandate.is_active is True


async def test_stammkunde_mandate_reuse(db: AsyncSession, test_user: User):
    """Second call must return the SAME mandate, not create a new one."""
    customer, iban = await _make_customer_with_iban(db, test_user.id, "10045")

    mandate1 = await MandateService.get_or_create_mandate(
        tenant_id=test_user.id,
        customer_id=customer.id,
        customer_iban_id=iban.id,
        db=db,
    )
    mandate2 = await MandateService.get_or_create_mandate(
        tenant_id=test_user.id,
        customer_id=customer.id,
        customer_iban_id=iban.id,
        db=db,
    )

    assert mandate1.id == mandate2.id


# ---------------------------------------------------------------------------
# Laufkunde: HVM + YYYYMMDD + zero-padded counter
# ---------------------------------------------------------------------------


async def test_laufkunde_mandate_reference_format(db: AsyncSession, test_user: User):
    customer, iban = await _make_customer_with_iban(
        db, test_user.id, "WALK001", is_walk_in=True
    )

    today = date.today().strftime("%Y%m%d")
    mandate = await MandateService.get_or_create_mandate(
        tenant_id=test_user.id,
        customer_id=customer.id,
        customer_iban_id=iban.id,
        db=db,
    )

    assert mandate.mandate_reference.startswith(f"HVM{today}")
    # Counter suffix must be present (3 digits minimum)
    suffix = mandate.mandate_reference[len(f"HVM{today}"):]
    assert suffix.isdigit()


async def test_laufkunde_counter_increments(db: AsyncSession, test_user: User):
    """Each new walk-in mandate for the same date gets a higher counter."""
    today = date.today().strftime("%Y%m%d")

    # Create two different walk-in customers
    customer1, iban1 = await _make_customer_with_iban(
        db, test_user.id, "WALK001", is_walk_in=True
    )
    customer2, iban2 = await _make_customer_with_iban(
        db, test_user.id, "WALK002", is_walk_in=True
    )

    mandate1 = await MandateService.get_or_create_mandate(
        tenant_id=test_user.id,
        customer_id=customer1.id,
        customer_iban_id=iban1.id,
        db=db,
    )
    mandate2 = await MandateService.get_or_create_mandate(
        tenant_id=test_user.id,
        customer_id=customer2.id,
        customer_iban_id=iban2.id,
        db=db,
    )

    # Both must start with today's date prefix
    prefix = f"HVM{today}"
    assert mandate1.mandate_reference.startswith(prefix)
    assert mandate2.mandate_reference.startswith(prefix)

    # The counters must be different
    assert mandate1.mandate_reference != mandate2.mandate_reference

    counter1 = int(mandate1.mandate_reference[len(prefix):])
    counter2 = int(mandate2.mandate_reference[len(prefix):])
    assert counter2 == counter1 + 1


# ---------------------------------------------------------------------------
# Mandate IBAN update when IBAN changes
# ---------------------------------------------------------------------------


async def test_mandate_iban_updated_on_change(db: AsyncSession, test_user: User):
    """If customer's IBAN changes, the existing mandate is updated, not recreated."""
    customer, iban1 = await _make_customer_with_iban(db, test_user.id, "10001")

    mandate = await MandateService.get_or_create_mandate(
        tenant_id=test_user.id,
        customer_id=customer.id,
        customer_iban_id=iban1.id,
        db=db,
    )
    original_mandate_id = mandate.id

    # New IBAN record
    iban2 = CustomerIban(
        tenant_id=test_user.id,
        customer_id=customer.id,
        iban="AT611904300234573201",
        account_holder_name="Test",
        is_active=True,
    )
    db.add(iban2)
    await db.flush()
    await db.refresh(iban2)

    updated_mandate = await MandateService.get_or_create_mandate(
        tenant_id=test_user.id,
        customer_id=customer.id,
        customer_iban_id=iban2.id,
        db=db,
    )

    # Same mandate, updated IBAN reference
    assert updated_mandate.id == original_mandate_id
    assert updated_mandate.customer_iban_id == iban2.id
    # Stripe payment method cleared (needs re-creation)
    assert updated_mandate.stripe_payment_method_id is None
