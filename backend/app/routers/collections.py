import asyncio
import json
import logging
import math
from datetime import date

from fastapi import APIRouter, Depends, HTTPException, Query, status
from pydantic import BaseModel
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.database import get_db
from app.models.customer import Customer
from app.models.customer_iban import CustomerIban
from app.models.invoice import Invoice
from app.models.payment_collection import PaymentCollection
from app.models.sepa_mandate import SepaMandate
from app.schemas.collection import CollectionListItem, CollectionListResponse, CollectionPreview, CollectionResponse
from app.services.collection_service import CollectionError, CollectionService
from app.services.invoice_keyword_service import InvoiceKeywordService
from app.utils.iban import mask_iban
from app.utils.security import UserContext, get_user_context

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/collections", tags=["collections"])


class SubmitRequest(BaseModel):
    invoice_id: str
    scheduled_date: date | None = None


class BatchSubmitRequest(BaseModel):
    invoice_ids: list[str]
    scheduled_date: date | None = None


class RescheduleRequest(BaseModel):
    scheduled_date: date


def _run_submit(tenant_id: str, invoice_id: str, db, scheduled_date=None):
    """Run async submit_collection in a new event loop (for thread execution)."""
    import asyncio as _asyncio

    loop = _asyncio.new_event_loop()
    try:
        return loop.run_until_complete(
            CollectionService.submit_collection(tenant_id, invoice_id, db, scheduled_date=scheduled_date)
        )
    finally:
        loop.close()


def _run_batch_submit(tenant_id: str, invoice_ids: list[str], db, scheduled_date=None):
    import asyncio as _asyncio

    loop = _asyncio.new_event_loop()
    try:
        return loop.run_until_complete(
            CollectionService.submit_batch_collection(tenant_id, invoice_ids, db, scheduled_date=scheduled_date)
        )
    finally:
        loop.close()


@router.post("/submit", response_model=dict)
async def submit_collection(
    body: SubmitRequest,
    ctx: UserContext = Depends(get_user_context),
    db: AsyncSession = Depends(get_db),
):
    """Submit a single SEPA direct debit (immediate or scheduled)."""
    try:
        collection = await asyncio.to_thread(
            _run_submit, ctx.organization_id, body.invoice_id, db, body.scheduled_date
        )
        await db.commit()
    except CollectionError as exc:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail=str(exc))
    except Exception as exc:
        logger.exception("Fehler beim Einreichen der Lastschrift: %s", exc)
        raise HTTPException(
            status_code=status.HTTP_502_BAD_GATEWAY,
            detail=f"Stripe-Fehler: {exc}",
        )

    return {
        "collection_id": collection.id,
        "status": collection.stripe_status,
        "stripe_payment_intent_id": collection.stripe_payment_intent_id,
        "scheduled_date": str(collection.scheduled_date) if collection.scheduled_date else None,
    }


@router.post("/submit-batch", response_model=dict)
async def submit_batch_collection(
    body: BatchSubmitRequest,
    ctx: UserContext = Depends(get_user_context),
    db: AsyncSession = Depends(get_db),
):
    """Submit multiple SEPA direct debits at once."""
    try:
        result = await asyncio.to_thread(
            _run_batch_submit, ctx.organization_id, body.invoice_ids, db, body.scheduled_date
        )
        await db.commit()
    except Exception as exc:
        logger.exception("Fehler beim Batch-Einzug: %s", exc)
        raise HTTPException(
            status_code=status.HTTP_502_BAD_GATEWAY,
            detail=f"Fehler: {exc}",
        )

    return result


@router.delete("/{collection_id}/cancel")
async def cancel_collection(
    collection_id: str,
    ctx: UserContext = Depends(get_user_context),
    db: AsyncSession = Depends(get_db),
):
    """Cancel a scheduled collection."""
    try:
        collection = await CollectionService.cancel_scheduled_collection(
            ctx.organization_id, collection_id, db
        )
        await db.commit()
    except CollectionError as exc:
        raise HTTPException(status_code=400, detail=str(exc))

    return {"status": "cancelled", "collection_id": collection.id}


@router.put("/{collection_id}/reschedule")
async def reschedule_collection(
    collection_id: str,
    body: RescheduleRequest,
    ctx: UserContext = Depends(get_user_context),
    db: AsyncSession = Depends(get_db),
):
    """Reschedule a scheduled collection."""
    try:
        collection = await CollectionService.reschedule_collection(
            ctx.organization_id, collection_id, body.scheduled_date, db
        )
        await db.commit()
    except CollectionError as exc:
        raise HTTPException(status_code=400, detail=str(exc))

    return {
        "collection_id": collection.id,
        "scheduled_date": str(collection.scheduled_date),
    }


@router.get("/preview", response_model=CollectionPreview)
async def preview_collection(
    invoice_id: str = Query(...),
    ctx: UserContext = Depends(get_user_context),
    db: AsyncSession = Depends(get_db),
):
    """Preview the SEPA description without submitting."""
    invoice = (
        await db.execute(
            select(Invoice).where(
                Invoice.id == invoice_id,
                Invoice.tenant_id == ctx.organization_id,
            )
        )
    ).scalar_one_or_none()

    if not invoice:
        raise HTTPException(status_code=404, detail="Rechnung nicht gefunden")

    customer = None
    if invoice.customer_id:
        customer = (
            await db.execute(
                select(Customer).where(
                    Customer.id == invoice.customer_id,
                    Customer.tenant_id == ctx.organization_id,
                )
            )
        ).scalar_one_or_none()

    customer_number = customer.customer_number if customer else "00000"
    keyword_service = InvoiceKeywordService()

    keyword_sepa = invoice.keyword_sepa
    keyword_display = invoice.keyword
    if not keyword_sepa and invoice.line_items_json:
        line_items = json.loads(invoice.line_items_json)
        keyword_display, keyword_sepa = keyword_service.extract_keyword(line_items)
    if not keyword_sepa:
        keyword_sepa = "Sonstiges"
        keyword_display = "Sonstiges"

    description = keyword_service.build_description(
        voucher_number=invoice.voucher_number,
        customer_number=customer_number,
        keyword_sepa=keyword_sepa,
    )

    return CollectionPreview(
        description=description,
        keyword=keyword_display,
        keyword_sepa=keyword_sepa,
        voucher_number=invoice.voucher_number,
        customer_number=customer_number,
        amount=float(invoice.total_gross_amount),
    )


@router.get("", response_model=CollectionListResponse)
async def list_collections(
    ctx: UserContext = Depends(get_user_context),
    db: AsyncSession = Depends(get_db),
    status_filter: str | None = Query(None, alias="status"),
    date_from: date | None = Query(None),
    date_to: date | None = Query(None),
    customer_id: str | None = Query(None),
    page: int = Query(1, ge=1),
    per_page: int = Query(20, ge=1, le=100),
):
    """List all collections for the organization, paginated."""
    from sqlalchemy import func

    base = select(PaymentCollection).where(
        PaymentCollection.tenant_id == ctx.organization_id
    )

    if status_filter:
        base = base.where(PaymentCollection.stripe_status == status_filter)

    if date_from:
        from datetime import datetime, timezone
        dt_from = datetime(date_from.year, date_from.month, date_from.day, tzinfo=timezone.utc)
        base = base.where(PaymentCollection.submitted_at >= dt_from)

    if date_to:
        from datetime import datetime, timezone, timedelta
        dt_to = datetime(date_to.year, date_to.month, date_to.day, tzinfo=timezone.utc) + timedelta(days=1)
        base = base.where(PaymentCollection.submitted_at < dt_to)

    if customer_id:
        invoice_ids_stmt = select(Invoice.id).where(
            Invoice.customer_id == customer_id,
            Invoice.tenant_id == ctx.organization_id,
        )
        base = base.where(PaymentCollection.invoice_id.in_(invoice_ids_stmt))

    count_stmt = select(func.count()).select_from(base.subquery())
    total = (await db.execute(count_stmt)).scalar() or 0
    total_pages = max(1, math.ceil(total / per_page))

    offset = (page - 1) * per_page
    collections = (
        await db.execute(
            base.order_by(PaymentCollection.created_at.desc())
            .offset(offset)
            .limit(per_page)
        )
    ).scalars().all()

    # Bulk-load related records
    inv_ids = list({c.invoice_id for c in collections})
    iban_ids = list({c.customer_iban_id for c in collections})
    mandate_ids = list({c.mandate_id for c in collections})

    invoices: dict[str, Invoice] = {}
    ibans: dict[str, CustomerIban] = {}
    mandates: dict[str, SepaMandate] = {}

    if inv_ids:
        rows = (await db.execute(select(Invoice).where(Invoice.id.in_(inv_ids)))).scalars().all()
        invoices = {r.id: r for r in rows}
    if iban_ids:
        rows = (await db.execute(select(CustomerIban).where(CustomerIban.id.in_(iban_ids)))).scalars().all()
        ibans = {r.id: r for r in rows}
    if mandate_ids:
        rows = (await db.execute(select(SepaMandate).where(SepaMandate.id.in_(mandate_ids)))).scalars().all()
        mandates = {r.id: r for r in rows}

    items = []
    for c in collections:
        inv = invoices.get(c.invoice_id)
        iban = ibans.get(c.customer_iban_id)
        mandate = mandates.get(c.mandate_id)
        items.append(CollectionListItem(
            id=c.id,
            invoice_id=c.invoice_id,
            voucher_number=inv.voucher_number if inv else "-",
            contact_name=inv.contact_name if inv else "-",
            amount_cents=c.amount_cents,
            currency=c.currency,
            iban_masked=mask_iban(iban.iban) if iban else None,
            mandate_reference=mandate.mandate_reference if mandate else None,
            stripe_status=c.stripe_status,
            submitted_at=c.submitted_at,
            completed_at=c.completed_at,
            failure_reason=c.failure_reason,
            description=c.description,
            scheduled_date=c.scheduled_date,
            is_scheduled=c.is_scheduled,
        ))

    return CollectionListResponse(
        items=items,
        total=total,
        page=page,
        per_page=per_page,
        total_pages=total_pages,
    )


@router.get("/{collection_id}", response_model=CollectionResponse)
async def get_collection(
    collection_id: str,
    ctx: UserContext = Depends(get_user_context),
    db: AsyncSession = Depends(get_db),
):
    """Get a single collection by ID."""
    collection = (
        await db.execute(
            select(PaymentCollection).where(
                PaymentCollection.id == collection_id,
                PaymentCollection.tenant_id == ctx.organization_id,
            )
        )
    ).scalar_one_or_none()

    if not collection:
        raise HTTPException(status_code=404, detail="Einzug nicht gefunden")

    return CollectionResponse.model_validate(collection)
