import asyncio
import logging
import math
from datetime import date

from fastapi import APIRouter, Depends, HTTPException, Query, status
from pydantic import BaseModel
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.database import get_db
from app.models.customer_iban import CustomerIban
from app.models.invoice import Invoice
from app.models.payment_collection import PaymentCollection
from app.models.sepa_mandate import SepaMandate
from app.models.user import User
from app.schemas.collection import CollectionListItem, CollectionListResponse, CollectionResponse
from app.services.collection_service import CollectionError, CollectionService
from app.utils.iban import mask_iban
from app.utils.security import get_current_user

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/collections", tags=["collections"])


class SubmitRequest(BaseModel):
    invoice_id: str


class BatchSubmitRequest(BaseModel):
    invoice_ids: list[str]


def _run_submit(tenant_id: str, invoice_id: str, db):
    """Run async submit_collection in a new event loop (for thread execution)."""
    import asyncio as _asyncio

    loop = _asyncio.new_event_loop()
    try:
        return loop.run_until_complete(
            CollectionService.submit_collection(tenant_id, invoice_id, db)
        )
    finally:
        loop.close()


def _run_batch_submit(tenant_id: str, invoice_ids: list[str], db):
    import asyncio as _asyncio

    loop = _asyncio.new_event_loop()
    try:
        return loop.run_until_complete(
            CollectionService.submit_batch_collection(tenant_id, invoice_ids, db)
        )
    finally:
        loop.close()


@router.post("/submit", response_model=dict)
async def submit_collection(
    body: SubmitRequest,
    current_user: User = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    """Submit a single SEPA direct debit."""
    try:
        collection = await asyncio.to_thread(
            _run_submit, current_user.id, body.invoice_id, db
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
    }


@router.post("/submit-batch", response_model=dict)
async def submit_batch_collection(
    body: BatchSubmitRequest,
    current_user: User = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    """Submit multiple SEPA direct debits at once."""
    try:
        result = await asyncio.to_thread(
            _run_batch_submit, current_user.id, body.invoice_ids, db
        )
        await db.commit()
    except Exception as exc:
        logger.exception("Fehler beim Batch-Einzug: %s", exc)
        raise HTTPException(
            status_code=status.HTTP_502_BAD_GATEWAY,
            detail=f"Fehler: {exc}",
        )

    return result


@router.get("", response_model=CollectionListResponse)
async def list_collections(
    current_user: User = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
    status_filter: str | None = Query(None, alias="status"),
    date_from: date | None = Query(None),
    date_to: date | None = Query(None),
    customer_id: str | None = Query(None),
    page: int = Query(1, ge=1),
    per_page: int = Query(20, ge=1, le=100),
):
    """List all collections for the tenant, paginated, with enriched join data."""
    from sqlalchemy import func

    base = select(PaymentCollection).where(
        PaymentCollection.tenant_id == current_user.id
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
        # Filter via Invoice join
        invoice_ids_stmt = select(Invoice.id).where(
            Invoice.customer_id == customer_id,
            Invoice.tenant_id == current_user.id,
        )
        base = base.where(PaymentCollection.invoice_id.in_(invoice_ids_stmt))

    count_stmt = select(func.count()).select_from(base.subquery())
    total = (await db.execute(count_stmt)).scalar() or 0
    total_pages = max(1, math.ceil(total / per_page))

    offset = (page - 1) * per_page
    collections = (
        await db.execute(
            base.order_by(PaymentCollection.submitted_at.desc())
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
    current_user: User = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    """Get a single collection by ID."""
    collection = (
        await db.execute(
            select(PaymentCollection).where(
                PaymentCollection.id == collection_id,
                PaymentCollection.tenant_id == current_user.id,
            )
        )
    ).scalar_one_or_none()

    if not collection:
        raise HTTPException(status_code=404, detail="Einzug nicht gefunden")

    return CollectionResponse.model_validate(collection)
