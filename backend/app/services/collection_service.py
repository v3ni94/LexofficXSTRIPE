import json
import logging
from datetime import datetime, timezone

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.models.customer import Customer
from app.models.customer_iban import CustomerIban
from app.models.integration import Integration
from app.models.invoice import CollectionStatus, Invoice
from app.models.payment_collection import PaymentCollection
from app.services.invoice_keyword_service import InvoiceKeywordService
from app.services.mandate_service import MandateService
from app.services.stripe_service import StripeService

logger = logging.getLogger(__name__)


class CollectionError(Exception):
    pass


class CollectionService:
    @staticmethod
    async def _get_stripe_service(tenant_id: str, db: AsyncSession) -> StripeService:
        integration = (
            await db.execute(
                select(Integration).where(Integration.tenant_id == tenant_id)
            )
        ).scalar_one_or_none()

        if not integration or not integration.stripe_connected:
            raise CollectionError("Stripe ist nicht verbunden")

        secret_key = integration.stripe_secret_key
        if not secret_key:
            raise CollectionError("Stripe Secret Key fehlt")

        return StripeService(secret_key)

    @staticmethod
    async def submit_collection(
        tenant_id: str,
        invoice_id: str,
        db: AsyncSession,
    ) -> PaymentCollection:
        # 1. Load and validate invoice
        invoice = (
            await db.execute(
                select(Invoice).where(
                    Invoice.id == invoice_id,
                    Invoice.tenant_id == tenant_id,
                )
            )
        ).scalar_one_or_none()

        if not invoice:
            raise CollectionError("Rechnung nicht gefunden")

        if invoice.collection_status == CollectionStatus.IN_COLLECTION:
            raise CollectionError("Rechnung befindet sich bereits im Einzugsverfahren")

        if invoice.lexoffice_status not in ("open", "overdue"):
            raise CollectionError(
                f"Rechnung kann nicht eingezogen werden (Lexoffice-Status: {invoice.lexoffice_status})"
            )

        # 2. Load customer
        if not invoice.customer_id:
            raise CollectionError("Rechnung hat keinen verknüpften Kunden")

        customer = (
            await db.execute(
                select(Customer).where(
                    Customer.id == invoice.customer_id,
                    Customer.tenant_id == tenant_id,
                )
            )
        ).scalar_one_or_none()

        if not customer:
            raise CollectionError("Kunde nicht gefunden")

        # 3. Load active IBAN
        active_iban = (
            await db.execute(
                select(CustomerIban).where(
                    CustomerIban.customer_id == customer.id,
                    CustomerIban.tenant_id == tenant_id,
                    CustomerIban.is_active.is_(True),
                )
            )
        ).scalar_one_or_none()

        if not active_iban:
            raise CollectionError(
                "Für diesen Kunden ist keine IBAN hinterlegt"
            )

        # 4. Get or create mandate
        mandate = await MandateService.get_or_create_mandate(
            tenant_id=tenant_id,
            customer_id=customer.id,
            customer_iban_id=active_iban.id,
            db=db,
        )

        # 5-7. Stripe operations (synchronous – run in calling thread)
        stripe_svc = await CollectionService._get_stripe_service(tenant_id, db)

        contact_email = customer.email or "noreply@lexsepa.de"

        stripe_customer = stripe_svc.find_or_create_customer(
            name=customer.name,
            email=customer.email,
            metadata={
                "tenant_id": tenant_id,
                "customer_id": customer.id,
                "customer_number": customer.customer_number,
            },
        )

        # Create payment method and attach to customer
        payment_method = stripe_svc.create_sepa_payment_method(
            iban=active_iban.iban,
            name=active_iban.account_holder_name,
            email=contact_email,
        )
        stripe_svc.attach_payment_method(
            payment_method_id=payment_method.id,
            customer_id=stripe_customer.id,
        )

        # 8. Build SEPA Verwendungszweck
        keyword_service = InvoiceKeywordService()
        keyword_sepa = invoice.keyword_sepa
        if not keyword_sepa and invoice.line_items_json:
            line_items = json.loads(invoice.line_items_json)
            _, keyword_sepa = keyword_service.extract_keyword(line_items)
        if not keyword_sepa:
            keyword_sepa = "Sonstiges"

        description = keyword_service.build_description(
            voucher_number=invoice.voucher_number,
            customer_number=customer.customer_number,
            keyword_sepa=keyword_sepa,
        )

        # 9. Create and confirm payment intent
        amount_cents = int(invoice.total_gross_amount * 100)
        payment_intent = stripe_svc.create_payment_intent(
            amount_cents=amount_cents,
            customer_id=stripe_customer.id,
            payment_method_id=payment_method.id,
            mandate_reference=mandate.mandate_reference,
            description=description,
            metadata={
                "tenant_id": tenant_id,
                "invoice_id": invoice.id,
                "mandate_reference": mandate.mandate_reference,
                "voucher_number": invoice.voucher_number,
                "customer_number": customer.customer_number,
            },
            contact_email=contact_email,
        )

        # 10. Save PaymentCollection
        collection = PaymentCollection(
            tenant_id=tenant_id,
            invoice_id=invoice.id,
            mandate_id=mandate.id,
            customer_iban_id=active_iban.id,
            amount_cents=amount_cents,
            currency="EUR",
            stripe_payment_intent_id=payment_intent.id,
            stripe_status="processing",
            description=description,
            submitted_at=datetime.now(timezone.utc),
        )
        db.add(collection)

        # 11. Update invoice status
        invoice.collection_status = CollectionStatus.IN_COLLECTION

        # 12. Update mandate with Stripe IDs
        mandate.stripe_payment_method_id = payment_method.id
        mandate.stripe_customer_id = stripe_customer.id

        await db.flush()
        await db.refresh(collection)

        logger.info(
            "Lastschrift eingereicht: %s für Rechnung %s (PI: %s)",
            collection.id,
            invoice.voucher_number,
            payment_intent.id,
        )

        return collection

    @staticmethod
    async def submit_batch_collection(
        tenant_id: str,
        invoice_ids: list[str],
        db: AsyncSession,
    ) -> dict:
        successful = []
        failed = []

        for invoice_id in invoice_ids:
            try:
                collection = await CollectionService.submit_collection(
                    tenant_id=tenant_id,
                    invoice_id=invoice_id,
                    db=db,
                )
                successful.append({
                    "invoice_id": invoice_id,
                    "collection_id": collection.id,
                    "stripe_payment_intent_id": collection.stripe_payment_intent_id,
                })
            except Exception as exc:
                logger.warning(
                    "Batch-Einzug fehlgeschlagen für Rechnung %s: %s",
                    invoice_id,
                    exc,
                )
                failed.append({"invoice_id": invoice_id, "error": str(exc)})

        return {"successful": successful, "failed": failed}
