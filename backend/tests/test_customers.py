"""Tests for /customers/* endpoints."""
import pytest
from httpx import AsyncClient
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.models.customer import Customer
from app.models.customer_iban import CustomerIban
from app.models.iban_history import IbanAction, IbanHistory
from app.models.organization import Organization
from app.models.user import User


# ---------------------------------------------------------------------------
# GET /customers
# ---------------------------------------------------------------------------


async def test_list_customers_empty(client: AsyncClient, test_user: User, auth_headers):
    resp = await client.get("/customers", headers=auth_headers(test_user.id))
    assert resp.status_code == 200
    data = resp.json()
    assert data["total"] == 0
    assert data["items"] == []


async def test_list_customers_returns_own_only(
    client: AsyncClient,
    test_user: User,
    test_org: Organization,
    test_user2: User,
    test_org2: Organization,
    create_customer,
    auth_headers,
):
    await create_customer(test_org.id, name="User A Customer")
    await create_customer(test_org2.id, name="User B Customer")

    resp = await client.get("/customers", headers=auth_headers(test_user.id))
    assert resp.status_code == 200
    data = resp.json()
    assert data["total"] == 1
    assert data["items"][0]["name"] == "User A Customer"


async def test_list_customers_search(
    client: AsyncClient,
    test_user: User,
    test_org: Organization,
    create_customer,
    auth_headers,
):
    await create_customer(test_org.id, name="Alpha GmbH", customer_number="10001")
    await create_customer(test_org.id, name="Beta GmbH", customer_number="10002")

    resp = await client.get("/customers?search=Alpha", headers=auth_headers(test_user.id))
    assert resp.status_code == 200
    assert resp.json()["total"] == 1
    assert resp.json()["items"][0]["name"] == "Alpha GmbH"


# ---------------------------------------------------------------------------
# GET /customers/{id}
# ---------------------------------------------------------------------------


async def test_get_customer_detail(
    client: AsyncClient,
    test_user: User,
    test_org: Organization,
    create_customer,
    create_iban,
    auth_headers,
):
    customer = await create_customer(test_org.id, name="Detail Test")
    await create_iban(test_org.id, customer.id, iban="DE89370400440532013000")

    resp = await client.get(f"/customers/{customer.id}", headers=auth_headers(test_user.id))
    assert resp.status_code == 200
    data = resp.json()
    assert data["name"] == "Detail Test"
    assert len(data["ibans"]) == 1
    assert "iban_masked" in data["ibans"][0]
    # Masked IBAN must not expose middle digits
    assert data["ibans"][0]["iban_masked"].count("*") > 0


async def test_get_customer_not_found(
    client: AsyncClient,
    test_user: User,
    auth_headers,
):
    resp = await client.get("/customers/nonexistent-id", headers=auth_headers(test_user.id))
    assert resp.status_code == 404


# ---------------------------------------------------------------------------
# PUT /customers/{id}/iban – update (Stammkunde)
# ---------------------------------------------------------------------------


async def test_update_iban_deactivates_old(
    client: AsyncClient,
    test_user: User,
    test_org: Organization,
    db: AsyncSession,
    create_customer,
    create_iban,
    auth_headers,
):
    customer = await create_customer(test_org.id)
    old_iban = await create_iban(test_org.id, customer.id, iban="DE89370400440532013000")

    resp = await client.put(
        f"/customers/{customer.id}/iban",
        json={
            "iban": "AT611904300234573201",
            "account_holder_name": "Erika Neu",
            "change_reason": "Kontowechsel",
        },
        headers=auth_headers(test_user.id),
    )
    assert resp.status_code == 200

    # Old IBAN must be deactivated
    await db.refresh(old_iban)
    assert old_iban.is_active is False

    # New IBAN must be active
    result = await db.execute(
        select(CustomerIban).where(
            CustomerIban.customer_id == customer.id,
            CustomerIban.is_active.is_(True),
        )
    )
    new_ibans = result.scalars().all()
    assert len(new_ibans) == 1
    assert new_ibans[0].iban == "AT611904300234573201"


async def test_update_iban_creates_history_entries(
    client: AsyncClient,
    test_user: User,
    test_org: Organization,
    db: AsyncSession,
    create_customer,
    create_iban,
    auth_headers,
):
    customer = await create_customer(test_org.id)
    await create_iban(test_org.id, customer.id)

    await client.put(
        f"/customers/{customer.id}/iban",
        json={
            "iban": "AT611904300234573201",
            "account_holder_name": "Erika Neu",
            "change_reason": "Test",
        },
        headers=auth_headers(test_user.id),
    )

    # Should have: DEACTIVATED (old) + CREATED (new)
    iban_ids = (
        await db.execute(
            select(CustomerIban.id).where(CustomerIban.customer_id == customer.id)
        )
    ).scalars().all()

    history_rows = (
        await db.execute(
            select(IbanHistory).where(IbanHistory.customer_iban_id.in_(iban_ids))
        )
    ).scalars().all()

    actions = {h.action for h in history_rows}
    assert IbanAction.DEACTIVATED in actions
    assert IbanAction.CREATED in actions


async def test_update_iban_invalid_iban(
    client: AsyncClient,
    test_user: User,
    test_org: Organization,
    create_customer,
    auth_headers,
):
    customer = await create_customer(test_org.id)
    resp = await client.put(
        f"/customers/{customer.id}/iban",
        json={"iban": "INVALID", "account_holder_name": "Test"},
        headers=auth_headers(test_user.id),
    )
    assert resp.status_code == 422


# ---------------------------------------------------------------------------
# POST /customers/{id}/iban – walk-in customer
# ---------------------------------------------------------------------------


async def test_walkin_iban_allows_multiple_active(
    client: AsyncClient,
    test_user: User,
    test_org: Organization,
    db: AsyncSession,
    create_customer,
    create_invoice,
    auth_headers,
):
    """Walk-in customers may have multiple active IBANs (one per invoice)."""
    customer = await create_customer(
        test_org.id, is_walk_in=True, customer_number="WALK1"
    )
    invoice1 = await create_invoice(test_org.id, customer.id, voucher_number="RE-001")
    invoice2 = await create_invoice(test_org.id, customer.id, voucher_number="RE-002")

    for invoice, iban in [
        (invoice1, "DE89370400440532013000"),
        (invoice2, "AT611904300234573201"),
    ]:
        resp = await client.post(
            f"/customers/{customer.id}/iban",
            json={
                "invoice_id": invoice.id,
                "iban": iban,
                "account_holder_name": "Walk In",
            },
            headers=auth_headers(test_user.id),
        )
        assert resp.status_code == 200

    result = await db.execute(
        select(CustomerIban).where(
            CustomerIban.customer_id == customer.id,
            CustomerIban.is_active.is_(True),
        )
    )
    active = result.scalars().all()
    assert len(active) == 2


# ---------------------------------------------------------------------------
# GET /customers/{id}/iban-history
# ---------------------------------------------------------------------------


async def test_iban_history_returns_entries(
    client: AsyncClient,
    test_user: User,
    test_org: Organization,
    create_customer,
    create_iban,
    auth_headers,
):
    customer = await create_customer(test_org.id)
    await create_iban(test_org.id, customer.id)

    # Trigger a change to create history entries
    await client.put(
        f"/customers/{customer.id}/iban",
        json={"iban": "AT611904300234573201", "account_holder_name": "Neu", "change_reason": "Test"},
        headers=auth_headers(test_user.id),
    )

    resp = await client.get(
        f"/customers/{customer.id}/iban-history",
        headers=auth_headers(test_user.id),
    )
    assert resp.status_code == 200
    entries = resp.json()
    assert len(entries) >= 2
    actions = {e["action"] for e in entries}
    assert "created" in actions
    assert "deactivated" in actions
