"""Multi-tenant isolation tests.

Each test creates data for User A and User B, then asserts that User A
cannot read or modify User B's data and vice versa.
"""
from unittest.mock import AsyncMock, patch

import pytest
from httpx import AsyncClient
from sqlalchemy.ext.asyncio import AsyncSession

from app.models.customer import Customer
from app.models.invoice import Invoice
from app.models.user import User
from app.services.collection_service import CollectionError, CollectionService


# ---------------------------------------------------------------------------
# Invoice isolation
# ---------------------------------------------------------------------------


async def test_user_a_cannot_see_user_b_invoices(
    client: AsyncClient,
    test_user: User,
    test_user2: User,
    create_invoice,
    auth_headers,
):
    # User B has an invoice
    await create_invoice(test_user2.id, voucher_number="RE-B-001")

    # User A's invoice list must be empty
    resp = await client.get("/invoices", headers=auth_headers(test_user.id))
    assert resp.status_code == 200
    assert resp.json()["total"] == 0


async def test_user_a_cannot_get_user_b_invoice_by_id(
    client: AsyncClient,
    test_user: User,
    test_user2: User,
    create_invoice,
    auth_headers,
):
    invoice_b = await create_invoice(test_user2.id, voucher_number="RE-B-002")

    resp = await client.get(f"/invoices/{invoice_b.id}", headers=auth_headers(test_user.id))
    assert resp.status_code == 404


# ---------------------------------------------------------------------------
# Customer isolation
# ---------------------------------------------------------------------------


async def test_user_a_cannot_see_user_b_customers(
    client: AsyncClient,
    test_user: User,
    test_user2: User,
    create_customer,
    auth_headers,
):
    await create_customer(test_user2.id, name="User B Customer")

    resp = await client.get("/customers", headers=auth_headers(test_user.id))
    assert resp.status_code == 200
    assert resp.json()["total"] == 0


async def test_user_a_cannot_get_user_b_customer_by_id(
    client: AsyncClient,
    test_user: User,
    test_user2: User,
    create_customer,
    auth_headers,
):
    customer_b = await create_customer(test_user2.id, name="User B Customer")

    resp = await client.get(f"/customers/{customer_b.id}", headers=auth_headers(test_user.id))
    assert resp.status_code == 404


async def test_user_a_cannot_update_user_b_customer_iban(
    client: AsyncClient,
    test_user: User,
    test_user2: User,
    create_customer,
    auth_headers,
):
    customer_b = await create_customer(test_user2.id, name="User B Customer")

    resp = await client.put(
        f"/customers/{customer_b.id}/iban",
        json={"iban": "DE89370400440532013000", "account_holder_name": "Hacker"},
        headers=auth_headers(test_user.id),
    )
    assert resp.status_code == 404


# ---------------------------------------------------------------------------
# Collection isolation (service level)
# ---------------------------------------------------------------------------


async def test_user_a_cannot_submit_user_b_invoice(
    db: AsyncSession,
    test_user: User,
    test_user2: User,
    create_customer,
    create_invoice,
    create_iban,
):
    """CollectionService must reject an invoice that belongs to a different tenant."""
    customer_b = await create_customer(test_user2.id, name="User B")
    invoice_b = await create_invoice(test_user2.id, customer_b.id)
    await create_iban(test_user2.id, customer_b.id)

    # User A tries to submit User B's invoice using User A's tenant_id
    with pytest.raises(CollectionError, match="nicht gefunden"):
        await CollectionService.submit_collection(
            tenant_id=test_user.id,  # User A's tenant
            invoice_id=invoice_b.id,  # User B's invoice
            db=db,
        )


# ---------------------------------------------------------------------------
# Collection list isolation
# ---------------------------------------------------------------------------


async def test_user_a_collections_list_excludes_user_b(
    client: AsyncClient,
    test_user: User,
    test_user2: User,
    auth_headers,
):
    """GET /collections must only return the authenticated tenant's records."""
    resp = await client.get("/collections", headers=auth_headers(test_user.id))
    assert resp.status_code == 200
    # Even if User B has collections, User A's response is empty
    assert resp.json()["total"] == 0


# ---------------------------------------------------------------------------
# Dashboard isolation
# ---------------------------------------------------------------------------


async def test_dashboard_stats_scoped_to_tenant(
    client: AsyncClient,
    test_user: User,
    test_user2: User,
    create_invoice,
    auth_headers,
):
    """User B's invoices must not appear in User A's dashboard stats."""
    # User B has open invoices
    for i in range(3):
        await create_invoice(test_user2.id, voucher_number=f"RE-B-{i}")

    resp = await client.get("/dashboard/stats", headers=auth_headers(test_user.id))
    assert resp.status_code == 200
    data = resp.json()
    # User A has no invoices
    assert data["open_invoices_count"] == 0
