"""Background task: submit scheduled SEPA collections that are due today."""
import json
import logging
from datetime import date, datetime, timezone

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.models.customer import Customer
from app.models.customer_iban import CustomerIban
from app.models.integration import Integration
from app.models.invoice import CollectionStatus, Invoice
from app.models.payment_collection import PaymentCollection
from app.models.sepa_mandate import SepaMandate
from app.services.invoice_keyword_service import InvoiceKeywordService
from app.services.stripe_service import StripeService

logger = logging.getLogger(__name__)


async def process_scheduled_collections(db: AsyncSession) -> dict:
    """Find and submit all scheduled collections due today or earlier."""
    today = date.today()

    due_collections = (
        await db.execute(
            select(PaymentCollection).where(
                PaymentCollection.is_scheduled.is_(True),
                PaymentCollection.scheduled_submitted.is_(False),
                PaymentCollection.scheduled_date <= today,
                PaymentCollection.stripe_status == "scheduled",
            )
        )
    ).scalars().all()

    if not due_collections:
        logger.info("Keine terminierten Einzüge fällig.")
        return {"submitted": 0, "failed": 0}

    logger.info("Verarbeite %d terminierte Einzüge", len(due_collections))

    submitted_count = 0
    failed_count = 0

    for collection in due_collections:
        try:
            await _submit_single(collection, db)
            submitted_count += 1
        except Exception as exc:
            logger.error(
                "Fehler beim Einreichen der terminierten Lastschrift %s: %s",
                collection.id,
                exc,
            )
            collection.stripe_status = "failed"
            collection.failure_reason = str(exc)

            invoice = (
                await db.execute(
                    select(Invoice).where(Invoice.id == collection.invoice_id)
                )
            ).scalar_one_or_none()
            if invoice:
                invoice.collection_status = CollectionStatus.FAILED

            failed_count += 1

    await db.flush()
    logger.info(
        "Terminierte Einzüge: %d eingereicht, %d fehlgeschlagen",
        submitted_count,
        failed_count,
    )
    return {"submitted": submitted_count, "failed": failed_count}


async def _submit_single(collection: PaymentCollection, db: AsyncSession) -> None:
    """Submit a single scheduled collection to Stripe."""
    # Load integration
    integration = (
        await db.execute(
            select(Integration).where(Integration.tenant_id == collection.tenant_id)
        )
    ).scalar_one_or_none()

    if not integration or not integration.stripe_connected:
        raise RuntimeError("Stripe nicht verbunden")

    secret_key = integration.stripe_secret_key
    if not secret_key:
        raise RuntimeError("Stripe Secret Key fehlt")

    stripe_svc = StripeService(secret_key)

    # Load related data
    invoice = (
        await db.execute(select(Invoice).where(Invoice.id == collection.invoice_id))
    ).scalar_one_or_none()
    if not invoice:
        raise RuntimeError("Rechnung nicht gefunden")

    mandate = (
        await db.execute(select(SepaMandate).where(SepaMandate.id == collection.mandate_id))
    ).scalar_one_or_none()
    if not mandate:
        raise RuntimeError("Mandat nicht gefunden")

    customer_iban = (
        await db.execute(select(CustomerIban).where(CustomerIban.id == collection.customer_iban_id))
    ).scalar_one_or_none()
    if not customer_iban:
        raise RuntimeError("IBAN nicht gefunden")

    customer = (
        await db.execute(
            select(Customer).where(
                Customer.id == invoice.customer_id,
                Customer.tenant_id == collection.tenant_id,
            )
        )
    ).scalar_one_or_none()
    if not customer:
        raise RuntimeError("Kunde nicht gefunden")

    contact_email = customer.email or "noreply@lexsepa.de"

    # Create Stripe customer
    stripe_customer = stripe_svc.find_or_create_customer(
        name=customer.name,
        email=customer.email,
        metadata={
            "tenant_id": collection.tenant_id,
            "customer_id": customer.id,
            "customer_number": customer.customer_number,
        },
    )

    # Create and attach payment method
    payment_method = stripe_svc.create_sepa_payment_method(
        iban=customer_iban.iban,
        name=customer_iban.account_holder_name,
        email=contact_email,
    )
    stripe_svc.attach_payment_method(
        payment_method_id=payment_method.id,
        customer_id=stripe_customer.id,
    )

    # Create payment intent
    description = collection.description or ""
    payment_intent = stripe_svc.create_payment_intent(
        amount_cents=collection.amount_cents,
        customer_id=stripe_customer.id,
        payment_method_id=payment_method.id,
        mandate_reference=mandate.mandate_reference,
        description=description,
        metadata={
            "tenant_id": collection.tenant_id,
            "invoice_id": invoice.id,
            "mandate_reference": mandate.mandate_reference,
            "voucher_number": invoice.voucher_number,
            "customer_number": customer.customer_number,
        },
        contact_email=contact_email,
    )

    # Update collection
    collection.scheduled_submitted = True
    collection.stripe_payment_intent_id = payment_intent.id
    collection.stripe_status = "processing"
    collection.submitted_at = datetime.now(timezone.utc)

    # Update mandate
    mandate.stripe_payment_method_id = payment_method.id
    mandate.stripe_customer_id = stripe_customer.id

    # Update invoice status
    invoice.collection_status = CollectionStatus.IN_COLLECTION

    logger.info(
        "Terminierte Lastschrift %s eingereicht (PI: %s)",
        collection.id,
        payment_intent.id,
    )
