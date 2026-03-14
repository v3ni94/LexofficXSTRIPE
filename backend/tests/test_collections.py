"""Tests for collection submission – service layer and HTTP endpoint."""
from unittest.mock import AsyncMock, MagicMock, patch

import pytest
from httpx import AsyncClient
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.models.customer import Customer
from app.models.customer_iban import CustomerIban
from app.models.integration import Integration
from app.models.invoice import CollectionStatus, Invoice
from app.models.payment_collection import PaymentCollection
from app.models.user import User
from app.services.collection_service import CollectionError, CollectionService


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------


def _stripe_mocks():
    """Return (StripeService mock instance, patch context)."""
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


# ---------------------------------------------------------------------------
# Service-level tests (call CollectionService directly with the test DB)
# ---------------------------------------------------------------------------


async def test_submit_collection_success(
    db: AsyncSession,
    test_user: User,
    test_integration: Integration,
    create_customer,
    create_invoice,
    create_iban,
):
    customer = await create_customer(test_user.id, customer_number="10099")
    invoice = await create_invoice(test_user.id, customer.id, amount=150.00)
    await create_iban(test_user.id, customer.id)

    mock_svc = _stripe_mocks()
    with patch("app.services.collection_service.StripeService", return_value=mock_svc):
        collection = await CollectionService.submit_collection(
            tenant_id=test_user.id,
            invoice_id=invoice.id,
            db=db,
        )

    assert collection.stripe_payment_intent_id == "pi_test_789"
    assert collection.stripe_status == "processing"
    assert collection.amount_cents == 15000  # 150.00 * 100

    # Invoice status updated
    await db.refresh(invoice)
    assert invoice.collection_status == CollectionStatus.IN_COLLECTION


async def test_submit_collection_invoice_not_open(
    db: AsyncSession,
    test_user: User,
    test_integration: Integration,
    create_customer,
    create_invoice,
    create_iban,
):
    customer = await create_customer(test_user.id, customer_number="10100")
    invoice = await create_invoice(
        test_user.id, customer.id, lexoffice_status="paid"
    )
    await create_iban(test_user.id, customer.id)

    with pytest.raises(CollectionError, match="Lexoffice-Status"):
        await CollectionService.submit_collection(test_user.id, invoice.id, db)


async def test_submit_collection_already_in_collection(
    db: AsyncSession,
    test_user: User,
    test_integration: Integration,
    create_customer,
    create_invoice,
    create_iban,
):
    customer = await create_customer(test_user.id, customer_number="10101")
    invoice = await create_invoice(
        test_user.id,
        customer.id,
        collection_status=CollectionStatus.IN_COLLECTION,
    )
    await create_iban(test_user.id, customer.id)

    with pytest.raises(CollectionError, match="bereits im Einzugsverfahren"):
        await CollectionService.submit_collection(test_user.id, invoice.id, db)


async def test_submit_collection_no_iban(
    db: AsyncSession,
    test_user: User,
    test_integration: Integration,
    create_customer,
    create_invoice,
):
    customer = await create_customer(test_user.id, customer_number="10102")
    invoice = await create_invoice(test_user.id, customer.id)
    # No IBAN created

    with pytest.raises(CollectionError, match="IBAN"):
        await CollectionService.submit_collection(test_user.id, invoice.id, db)


async def test_submit_collection_no_customer(
    db: AsyncSession,
    test_user: User,
    test_integration: Integration,
    create_invoice,
):
    # Invoice without a linked customer
    invoice = await create_invoice(test_user.id, customer_id=None)

    with pytest.raises(CollectionError, match="Kunden"):
        await CollectionService.submit_collection(test_user.id, invoice.id, db)


async def test_submit_collection_stripe_not_connected(
    db: AsyncSession,
    test_user: User,
    create_customer,
    create_invoice,
    create_iban,
):
    # Integration exists but Stripe NOT connected (no test_integration fixture)
    customer = await create_customer(test_user.id, customer_number="10103")
    invoice = await create_invoice(test_user.id, customer.id)
    await create_iban(test_user.id, customer.id)

    with pytest.raises(CollectionError, match="Stripe"):
        await CollectionService.submit_collection(test_user.id, invoice.id, db)


# ---------------------------------------------------------------------------
# Batch collection
# ---------------------------------------------------------------------------


async def test_submit_batch_partial_failure(
    db: AsyncSession,
    test_user: User,
    test_integration: Integration,
    create_customer,
    create_invoice,
    create_iban,
):
    """3 valid invoices + 1 without IBAN → 3 successful, 1 failed."""
    customer = await create_customer(test_user.id, customer_number="10200")
    await create_iban(test_user.id, customer.id)

    invoices = []
    for i in range(3):
        inv = await create_invoice(
            test_user.id, customer.id, voucher_number=f"RE-BATCH-{i}", amount=50.00
        )
        invoices.append(inv)

    # 4th invoice has no customer → will fail
    bad_invoice = await create_invoice(test_user.id, customer_id=None, voucher_number="RE-BAD")
    invoice_ids = [inv.id for inv in invoices] + [bad_invoice.id]

    mock_svc = _stripe_mocks()
    with patch("app.services.collection_service.StripeService", return_value=mock_svc):
        result = await CollectionService.submit_batch_collection(
            tenant_id=test_user.id,
            invoice_ids=invoice_ids,
            db=db,
        )

    assert len(result["successful"]) == 3
    assert len(result["failed"]) == 1
    assert result["failed"][0]["invoice_id"] == bad_invoice.id


# ---------------------------------------------------------------------------
# HTTP endpoint test (CollectionService mocked to bypass thread/event-loop issues)
# ---------------------------------------------------------------------------


async def test_submit_endpoint_success(
    client: AsyncClient,
    test_user: User,
    auth_headers,
):
    mock_collection = MagicMock()
    mock_collection.id = "col-http-test"
    mock_collection.stripe_status = "processing"
    mock_collection.stripe_payment_intent_id = "pi_http_test"

    with patch(
        "app.services.collection_service.CollectionService.submit_collection",
        new_callable=AsyncMock,
        return_value=mock_collection,
    ):
        resp = await client.post(
            "/collections/submit",
            json={"invoice_id": "any-invoice-id"},
            headers=auth_headers(test_user.id),
        )

    assert resp.status_code == 200
    data = resp.json()
    assert data["collection_id"] == "col-http-test"
    assert data["stripe_payment_intent_id"] == "pi_http_test"


async def test_submit_endpoint_collection_error(
    client: AsyncClient,
    test_user: User,
    auth_headers,
):
    with patch(
        "app.services.collection_service.CollectionService.submit_collection",
        new_callable=AsyncMock,
        side_effect=CollectionError("Keine IBAN hinterlegt"),
    ):
        resp = await client.post(
            "/collections/submit",
            json={"invoice_id": "bad-invoice"},
            headers=auth_headers(test_user.id),
        )

    assert resp.status_code == 400
    assert "IBAN" in resp.json()["detail"]


async def test_submit_endpoint_requires_auth(client: AsyncClient):
    resp = await client.post("/collections/submit", json={"invoice_id": "x"})
    assert resp.status_code == 401
