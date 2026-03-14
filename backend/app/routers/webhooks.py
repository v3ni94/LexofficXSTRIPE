import json
import logging
from datetime import datetime, timezone

import stripe
from fastapi import APIRouter, Request, Response
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.database import get_db
from app.models.integration import Integration
from app.models.invoice import CollectionStatus, Invoice
from app.models.payment_collection import PaymentCollection

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/webhooks", tags=["webhooks"])


async def _handle_payment_intent_processing(
    payment_intent: dict,
    db: AsyncSession,
    tenant_id: str,
) -> None:
    collection = (
        await db.execute(
            select(PaymentCollection).where(
                PaymentCollection.stripe_payment_intent_id == payment_intent["id"],
                PaymentCollection.tenant_id == tenant_id,
            )
        )
    ).scalar_one_or_none()

    if not collection:
        logger.warning("PaymentCollection nicht gefunden für PI %s", payment_intent["id"])
        return

    collection.stripe_status = "processing"
    logger.info(
        "PaymentIntent %s: processing (Collection %s)",
        payment_intent["id"],
        collection.id,
    )


async def _handle_payment_intent_succeeded(
    payment_intent: dict,
    db: AsyncSession,
    tenant_id: str,
) -> None:
    collection = (
        await db.execute(
            select(PaymentCollection).where(
                PaymentCollection.stripe_payment_intent_id == payment_intent["id"],
                PaymentCollection.tenant_id == tenant_id,
            )
        )
    ).scalar_one_or_none()

    if not collection:
        logger.warning("PaymentCollection nicht gefunden für PI %s", payment_intent["id"])
        return

    collection.stripe_status = "succeeded"
    collection.completed_at = datetime.now(timezone.utc)

    # Update linked invoice
    invoice = (
        await db.execute(
            select(Invoice).where(Invoice.id == collection.invoice_id)
        )
    ).scalar_one_or_none()

    if invoice:
        invoice.collection_status = CollectionStatus.COLLECTED
        logger.info(
            "Lastschrift für %s erfolgreich eingezogen",
            invoice.voucher_number,
        )


async def _handle_payment_intent_failed(
    payment_intent: dict,
    db: AsyncSession,
    tenant_id: str,
) -> None:
    collection = (
        await db.execute(
            select(PaymentCollection).where(
                PaymentCollection.stripe_payment_intent_id == payment_intent["id"],
                PaymentCollection.tenant_id == tenant_id,
            )
        )
    ).scalar_one_or_none()

    if not collection:
        logger.warning("PaymentCollection nicht gefunden für PI %s", payment_intent["id"])
        return

    last_error = payment_intent.get("last_payment_error") or {}
    reason = last_error.get("message", "Unbekannter Fehler")

    collection.stripe_status = "failed"
    collection.failure_reason = reason
    collection.completed_at = datetime.now(timezone.utc)

    invoice = (
        await db.execute(
            select(Invoice).where(Invoice.id == collection.invoice_id)
        )
    ).scalar_one_or_none()

    if invoice:
        invoice.collection_status = CollectionStatus.FAILED
        logger.warning(
            "Lastschrift für %s fehlgeschlagen: %s",
            invoice.voucher_number,
            reason,
        )


async def _handle_charge_dispute_created(
    dispute: dict,
    db: AsyncSession,
    tenant_id: str,
) -> None:
    payment_intent_id = dispute.get("payment_intent")
    if not payment_intent_id:
        return

    collection = (
        await db.execute(
            select(PaymentCollection).where(
                PaymentCollection.stripe_payment_intent_id == payment_intent_id,
                PaymentCollection.tenant_id == tenant_id,
            )
        )
    ).scalar_one_or_none()

    if not collection:
        logger.warning("PaymentCollection nicht gefunden für PI %s (Dispute)", payment_intent_id)
        return

    reason = "SEPA-Lastschrift wurde vom Kunden widerrufen"
    collection.stripe_status = "disputed"
    collection.failure_reason = reason
    collection.completed_at = datetime.now(timezone.utc)

    invoice = (
        await db.execute(
            select(Invoice).where(Invoice.id == collection.invoice_id)
        )
    ).scalar_one_or_none()

    if invoice:
        invoice.collection_status = CollectionStatus.FAILED
        logger.warning(
            "SEPA-Rücklastschrift für %s: %s",
            invoice.voucher_number,
            reason,
        )


@router.post("/stripe")
async def stripe_webhook(request: Request) -> Response:
    """
    Stripe webhook endpoint. No auth header required — Stripe signs with webhook secret.
    Multi-tenant: extract tenant_id from PaymentIntent metadata, then verify signature.
    Always returns HTTP 200 to prevent Stripe retries.
    """
    raw_body = await request.body()
    stripe_signature = request.headers.get("Stripe-Signature", "")

    if not stripe_signature:
        logger.warning("Stripe-Webhook: fehlende Signatur")
        return Response(status_code=200)

    # Step 1: Parse raw JSON to extract tenant_id without verifying yet
    try:
        raw_event = json.loads(raw_body)
    except json.JSONDecodeError:
        logger.warning("Stripe-Webhook: ungültiges JSON")
        return Response(status_code=200)

    # Step 2: Extract tenant_id from PaymentIntent or Charge metadata
    event_obj = raw_event.get("data", {}).get("object", {})
    event_type = raw_event.get("type", "")

    # For dispute events, look up payment_intent separately (no metadata on dispute object)
    tenant_id: str | None = None
    if event_type == "charge.dispute.created":
        # The dispute object has a payment_intent field; we'll resolve tenant after DB lookup
        # Try metadata on the dispute's charge
        tenant_id = event_obj.get("metadata", {}).get("tenant_id")
    else:
        tenant_id = event_obj.get("metadata", {}).get("tenant_id")

    if not tenant_id:
        logger.warning("Stripe-Webhook: tenant_id nicht in Metadaten gefunden (type=%s)", event_type)
        return Response(status_code=200)

    # Step 3: Load integration and verify signature
    async for db in get_db():
        try:
            integration = (
                await db.execute(
                    select(Integration).where(Integration.tenant_id == tenant_id)
                )
            ).scalar_one_or_none()

            if not integration or not integration.stripe_connected:
                logger.warning("Stripe-Webhook: keine aktive Stripe-Integration für tenant %s", tenant_id)
                return Response(status_code=200)

            webhook_secret = integration.stripe_webhook_secret
            if not webhook_secret:
                logger.warning("Stripe-Webhook: kein Webhook-Secret für tenant %s", tenant_id)
                return Response(status_code=200)

            # Verify signature using the tenant's webhook secret
            try:
                event = stripe.Webhook.construct_event(
                    payload=raw_body,
                    sig_header=stripe_signature,
                    secret=webhook_secret,
                )
            except stripe.error.SignatureVerificationError:
                logger.warning("Stripe-Webhook: Signaturprüfung fehlgeschlagen für tenant %s", tenant_id)
                return Response(status_code=200)

            # Step 4: Handle events
            obj = event["data"]["object"]

            if event_type == "payment_intent.processing":
                await _handle_payment_intent_processing(obj, db, tenant_id)

            elif event_type == "payment_intent.succeeded":
                await _handle_payment_intent_succeeded(obj, db, tenant_id)

            elif event_type == "payment_intent.payment_failed":
                await _handle_payment_intent_failed(obj, db, tenant_id)

            elif event_type == "charge.dispute.created":
                await _handle_charge_dispute_created(obj, db, tenant_id)

            else:
                logger.debug("Stripe-Webhook: unbekannter Event-Typ %s – ignoriert", event_type)

            await db.commit()

        except Exception as exc:
            logger.exception("Stripe-Webhook: unerwarteter Fehler: %s", exc)
            # Always return 200 to prevent Stripe retries
            return Response(status_code=200)

    return Response(status_code=200)
