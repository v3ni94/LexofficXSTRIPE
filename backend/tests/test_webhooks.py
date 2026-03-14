"""Tests for POST /webhooks/stripe.

Design note: the webhook endpoint always returns HTTP 200, even on signature
failure, to prevent Stripe retries. Tests verify DB state changes rather than
status codes for the "invalid signature" case.
"""
import json
from unittest.mock import MagicMock, patch

import pytest
import stripe
from httpx import AsyncClient
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.models.invoice import CollectionStatus, Invoice
from app.models.payment_collection import PaymentCollection
from app.models.user import User


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------


def _build_event(event_type: str, obj: dict, tenant_id: str) -> dict:
    """Construct a minimal Stripe-shaped event dict."""
    obj.setdefault("metadata", {})["tenant_id"] = tenant_id
    return {
        "id": "evt_test_123",
        "type": event_type,
        "data": {"object": obj},
    }


async def _create_collection(db: AsyncSession, tenant_id: str, pi_id: str, invoice: Invoice):
    """Insert a PaymentCollection row for webhook handler lookups."""
    from app.models.customer import Customer
    from app.models.customer_iban import CustomerIban
    from app.models.sepa_mandate import SepaMandate
    from datetime import date, datetime, timezone

    customer = Customer(tenant_id=tenant_id, customer_number="W001", name="WH Test")
    db.add(customer)
    await db.flush()

    iban = CustomerIban(
        tenant_id=tenant_id,
        customer_id=customer.id,
        iban="DE89370400440532013000",
        account_holder_name="WH Test",
        is_active=True,
    )
    db.add(iban)
    await db.flush()

    mandate = SepaMandate(
        tenant_id=tenant_id,
        customer_id=customer.id,
        customer_iban_id=iban.id,
        mandate_reference="HVMWHTEST",
        mandate_date=date.today(),
        is_active=True,
    )
    db.add(mandate)
    await db.flush()

    collection = PaymentCollection(
        tenant_id=tenant_id,
        invoice_id=invoice.id,
        mandate_id=mandate.id,
        customer_iban_id=iban.id,
        amount_cents=10000,
        currency="EUR",
        stripe_payment_intent_id=pi_id,
        stripe_status="processing",
        submitted_at=datetime.now(timezone.utc),
    )
    db.add(collection)
    await db.flush()
    await db.refresh(collection)
    return collection


# ---------------------------------------------------------------------------
# payment_intent.succeeded
# ---------------------------------------------------------------------------


async def test_webhook_payment_intent_succeeded(
    client: AsyncClient,
    db: AsyncSession,
    test_user: User,
    test_integration,
    create_invoice,
):
    invoice = await create_invoice(
        test_user.id,
        collection_status=CollectionStatus.IN_COLLECTION,
        lexoffice_status="open",
    )
    pi_id = "pi_test_succeeded_001"
    await _create_collection(db, test_user.id, pi_id, invoice)

    event = _build_event(
        "payment_intent.succeeded",
        {"id": pi_id, "metadata": {}},
        test_user.id,
    )

    with patch("stripe.Webhook.construct_event", return_value=event):
        resp = await client.post(
            "/webhooks/stripe",
            content=json.dumps(event),
            headers={"Content-Type": "application/json", "Stripe-Signature": "t=1,v1=sig"},
        )

    assert resp.status_code == 200

    # Collection status updated
    col = (
        await db.execute(
            select(PaymentCollection).where(
                PaymentCollection.stripe_payment_intent_id == pi_id
            )
        )
    ).scalar_one()
    assert col.stripe_status == "succeeded"
    assert col.completed_at is not None

    # Invoice status updated
    await db.refresh(invoice)
    assert invoice.collection_status == CollectionStatus.COLLECTED


# ---------------------------------------------------------------------------
# payment_intent.payment_failed
# ---------------------------------------------------------------------------


async def test_webhook_payment_intent_failed(
    client: AsyncClient,
    db: AsyncSession,
    test_user: User,
    test_integration,
    create_invoice,
):
    invoice = await create_invoice(
        test_user.id,
        collection_status=CollectionStatus.IN_COLLECTION,
        lexoffice_status="open",
    )
    pi_id = "pi_test_failed_001"
    await _create_collection(db, test_user.id, pi_id, invoice)

    event = _build_event(
        "payment_intent.payment_failed",
        {
            "id": pi_id,
            "metadata": {},
            "last_payment_error": {"message": "Insufficient funds"},
        },
        test_user.id,
    )

    with patch("stripe.Webhook.construct_event", return_value=event):
        resp = await client.post(
            "/webhooks/stripe",
            content=json.dumps(event),
            headers={"Content-Type": "application/json", "Stripe-Signature": "t=1,v1=sig"},
        )

    assert resp.status_code == 200

    col = (
        await db.execute(
            select(PaymentCollection).where(
                PaymentCollection.stripe_payment_intent_id == pi_id
            )
        )
    ).scalar_one()
    assert col.stripe_status == "failed"
    assert col.failure_reason == "Insufficient funds"
    assert col.completed_at is not None

    await db.refresh(invoice)
    assert invoice.collection_status == CollectionStatus.FAILED


# ---------------------------------------------------------------------------
# Missing / invalid Stripe-Signature header
# ---------------------------------------------------------------------------


async def test_webhook_missing_signature_returns_200(client: AsyncClient):
    """Stripe-Signature absent → 200 with no error (graceful ignore)."""
    resp = await client.post(
        "/webhooks/stripe",
        content=json.dumps({"type": "payment_intent.succeeded", "data": {"object": {}}}),
        headers={"Content-Type": "application/json"},
        # No Stripe-Signature header
    )
    assert resp.status_code == 200


async def test_webhook_invalid_signature_returns_200_no_db_change(
    client: AsyncClient,
    db: AsyncSession,
    test_user: User,
    test_integration,
    create_invoice,
):
    """Invalid signature → endpoint returns 200 but does NOT update DB."""
    invoice = await create_invoice(
        test_user.id,
        collection_status=CollectionStatus.IN_COLLECTION,
        lexoffice_status="open",
    )
    pi_id = "pi_test_badsig_001"
    await _create_collection(db, test_user.id, pi_id, invoice)

    event_payload = _build_event(
        "payment_intent.succeeded",
        {"id": pi_id, "metadata": {}},
        test_user.id,
    )

    # Signature verification raises an error
    with patch(
        "stripe.Webhook.construct_event",
        side_effect=stripe.error.SignatureVerificationError("bad sig", "sig_header"),
    ):
        resp = await client.post(
            "/webhooks/stripe",
            content=json.dumps(event_payload),
            headers={"Content-Type": "application/json", "Stripe-Signature": "t=1,v1=badsig"},
        )

    assert resp.status_code == 200

    # Collection must NOT have been updated
    col = (
        await db.execute(
            select(PaymentCollection).where(
                PaymentCollection.stripe_payment_intent_id == pi_id
            )
        )
    ).scalar_one()
    assert col.stripe_status == "processing"  # unchanged


# ---------------------------------------------------------------------------
# Unknown event type
# ---------------------------------------------------------------------------


async def test_webhook_unknown_event_type_returns_200(
    client: AsyncClient,
    test_user: User,
    test_integration,
):
    event = _build_event(
        "customer.subscription.created",
        {"id": "sub_123", "metadata": {}},
        test_user.id,
    )

    with patch("stripe.Webhook.construct_event", return_value=event):
        resp = await client.post(
            "/webhooks/stripe",
            content=json.dumps(event),
            headers={"Content-Type": "application/json", "Stripe-Signature": "t=1,v1=sig"},
        )

    assert resp.status_code == 200


# ---------------------------------------------------------------------------
# Missing tenant_id in metadata
# ---------------------------------------------------------------------------


async def test_webhook_missing_tenant_id_returns_200(client: AsyncClient):
    event = {
        "id": "evt_no_tenant",
        "type": "payment_intent.succeeded",
        "data": {"object": {"id": "pi_xyz", "metadata": {}}},  # no tenant_id
    }

    resp = await client.post(
        "/webhooks/stripe",
        content=json.dumps(event),
        headers={"Content-Type": "application/json", "Stripe-Signature": "t=1,v1=sig"},
    )
    assert resp.status_code == 200
