"""Tests for SyncService and the POST /invoices/sync endpoint."""
from unittest.mock import MagicMock, patch

import pytest
from httpx import AsyncClient
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.models.customer import Customer
from app.models.integration import Integration
from app.models.invoice import CollectionStatus, Invoice
from app.models.organization import Organization
from app.models.user import User
from app.services.sync_service import SyncService


# ---------------------------------------------------------------------------
# Helpers – mock LexofficeService
# ---------------------------------------------------------------------------


def _make_lex_mock(vouchers: list[dict], details: dict[str, dict], contacts: dict[str, dict]):
    """Build a MagicMock for LexofficeService."""
    mock = MagicMock()
    mock.get_open_invoices_paginated.return_value = iter(vouchers)
    mock.get_invoice_detail.side_effect = lambda vid: details.get(vid, {})
    mock.get_contact.side_effect = lambda cid: contacts.get(cid, {})
    # Context manager support
    mock.__enter__ = MagicMock(return_value=mock)
    mock.__exit__ = MagicMock(return_value=False)
    return mock


def _voucher(vid: str, number: str, status: str = "open") -> dict:
    return {"id": vid, "voucherNumber": number, "voucherStatus": status}


def _detail(
    vid: str,
    number: str,
    amount: float = 100.0,
    status: str = "open",
    contact_id: str | None = None,
    contact_name: str = "Test Kunde",
) -> dict:
    address: dict = {"name": contact_name}
    if contact_id:
        address["contactId"] = contact_id
    return {
        "id": vid,
        "voucherNumber": number,
        "voucherStatus": status,
        "address": address,
        "totalPrice": {"totalGrossAmount": amount, "currency": "EUR"},
        "dueDate": "2026-04-01",
    }


def _contact(cid: str, name: str, number: str = "10001") -> dict:
    return {
        "id": cid,
        "company": {"name": name},
        "roles": {"customer": {"number": number}},
    }


# ---------------------------------------------------------------------------
# SyncService direct tests
# ---------------------------------------------------------------------------


async def test_sync_creates_new_invoices(
    db: AsyncSession,
    test_user: User,
    test_org: Organization,
    test_integration: Integration,
):
    vid = "lex-inv-001"
    lex = _make_lex_mock(
        vouchers=[_voucher(vid, "RE-001")],
        details={vid: _detail(vid, "RE-001", amount=200.0)},
        contacts={},
    )

    result = await SyncService.sync_invoices(test_org.id, lex, db)

    assert result.new_count == 1
    assert result.synced_count == 1

    invoices = (
        await db.execute(select(Invoice).where(Invoice.tenant_id == test_org.id))
    ).scalars().all()
    assert len(invoices) == 1
    assert invoices[0].voucher_number == "RE-001"
    assert invoices[0].collection_status == CollectionStatus.OPEN


async def test_sync_updates_existing_invoice(
    db: AsyncSession,
    test_user: User,
    test_org: Organization,
    test_integration: Integration,
):
    vid = "lex-inv-002"
    lex1 = _make_lex_mock(
        vouchers=[_voucher(vid, "RE-002")],
        details={vid: _detail(vid, "RE-002", amount=100.0)},
        contacts={},
    )
    await SyncService.sync_invoices(test_org.id, lex1, db)

    # Second sync with updated amount
    lex2 = _make_lex_mock(
        vouchers=[_voucher(vid, "RE-002")],
        details={vid: _detail(vid, "RE-002", amount=150.0)},
        contacts={},
    )
    result2 = await SyncService.sync_invoices(test_org.id, lex2, db)

    assert result2.updated_count == 1
    assert result2.new_count == 0

    invoice = (
        await db.execute(select(Invoice).where(Invoice.lexoffice_invoice_id == vid))
    ).scalar_one()
    assert float(invoice.total_gross_amount) == 150.0


async def test_sync_marks_paid_invoice_as_collected(
    db: AsyncSession,
    test_user: User,
    test_org: Organization,
    test_integration: Integration,
):
    """An invoice that disappears from the open list and is re-checked as 'paid'
    must be updated to COLLECTED."""
    vid = "lex-inv-003"
    # First sync: invoice appears as open
    lex1 = _make_lex_mock(
        vouchers=[_voucher(vid, "RE-003")],
        details={vid: _detail(vid, "RE-003", status="open")},
        contacts={},
    )
    await SyncService.sync_invoices(test_org.id, lex1, db)

    # Second sync: invoice no longer in open list, re-check returns "paid"
    lex2 = _make_lex_mock(
        vouchers=[],  # invoice not in open list anymore
        details={vid: _detail(vid, "RE-003", status="paid")},
        contacts={},
    )
    await SyncService.sync_invoices(test_org.id, lex2, db)

    invoice = (
        await db.execute(select(Invoice).where(Invoice.lexoffice_invoice_id == vid))
    ).scalar_one()
    assert invoice.collection_status == CollectionStatus.COLLECTED
    assert invoice.lexoffice_status == "paid"


async def test_sync_creates_customer_from_contact(
    db: AsyncSession,
    test_user: User,
    test_org: Organization,
    test_integration: Integration,
):
    vid = "lex-inv-004"
    cid = "lex-contact-001"
    lex = _make_lex_mock(
        vouchers=[_voucher(vid, "RE-004")],
        details={vid: _detail(vid, "RE-004", contact_id=cid, contact_name="Mustermann GmbH")},
        contacts={cid: _contact(cid, "Mustermann GmbH", number="20001")},
    )

    await SyncService.sync_invoices(test_org.id, lex, db)

    customers = (
        await db.execute(select(Customer).where(Customer.tenant_id == test_org.id))
    ).scalars().all()
    assert len(customers) == 1
    assert customers[0].name == "Mustermann GmbH"
    assert customers[0].customer_number == "20001"

    # Invoice linked to customer
    invoice = (
        await db.execute(select(Invoice).where(Invoice.lexoffice_invoice_id == vid))
    ).scalar_one()
    assert invoice.customer_id == customers[0].id


async def test_sync_updates_last_sync_timestamp(
    db: AsyncSession,
    test_user: User,
    test_org: Organization,
    test_integration: Integration,
):
    lex = _make_lex_mock(vouchers=[], details={}, contacts={})
    await SyncService.sync_invoices(test_org.id, lex, db)

    result = await db.execute(
        select(Integration).where(Integration.tenant_id == test_org.id)
    )
    integ = result.scalar_one()
    assert integ.lexoffice_last_sync is not None


# ---------------------------------------------------------------------------
# HTTP endpoint: rate limiting
# ---------------------------------------------------------------------------


async def test_sync_endpoint_rate_limit(
    client: AsyncClient,
    test_user: User,
    test_integration: Integration,
    auth_headers,
):
    """Second manual sync within 60s must return HTTP 429."""
    mock_result = MagicMock()
    mock_result.synced_count = 2
    mock_result.new_count = 1
    mock_result.updated_count = 1
    mock_result.removed_count = 0

    with patch("app.routers.invoices._run_sync", return_value=mock_result):
        resp1 = await client.post("/invoices/sync", headers=auth_headers(test_user.id))
        assert resp1.status_code == 200

        resp2 = await client.post("/invoices/sync", headers=auth_headers(test_user.id))
        assert resp2.status_code == 429
        assert "Retry-After" in resp2.headers


async def test_sync_endpoint_lexoffice_not_connected(
    client: AsyncClient,
    test_user: User,
    auth_headers,
):
    """Without a connected Lexoffice integration, sync returns 400."""
    # test_user has an integration but it is not connected (no test_integration fixture)
    resp = await client.post("/invoices/sync", headers=auth_headers(test_user.id))
    assert resp.status_code == 400


async def test_sync_endpoint_requires_auth(client: AsyncClient):
    resp = await client.post("/invoices/sync")
    assert resp.status_code == 401
