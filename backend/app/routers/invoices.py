import asyncio
import math
import time
from datetime import datetime, timedelta, timezone

from fastapi import APIRouter, Depends, HTTPException, Query, status
from sqlalchemy import func, or_, select
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.orm import selectinload

from app.database import get_db
from app.models.customer import Customer
from app.models.customer_iban import CustomerIban
from app.models.integration import Integration
from app.models.invoice import CollectionStatus, Invoice
from app.models.sepa_mandate import SepaMandate
from app.models.user import User
from app.schemas.invoice import (
    CollectionBrief,
    CustomerBrief,
    InvoiceDetailResponse,
    InvoiceListItem,
    InvoiceListResponse,
    KeywordCount,
    SyncResponse,
)
from app.services.lexoffice_service import LexofficeService
from app.services.sync_service import SyncService
from app.utils.exceptions import LexofficeAuthError
from app.utils.security import get_current_user

router = APIRouter(prefix="/invoices", tags=["invoices"])

# In-memory rate limit: tenant_id -> last manual sync timestamp (UTC)
_last_manual_sync: dict[str, datetime] = {}
MANUAL_SYNC_COOLDOWN_SECONDS = 60


async def _get_lexoffice_service(
    db: AsyncSession, user: User
) -> LexofficeService:
    result = await db.execute(
        select(Integration).where(Integration.tenant_id == user.id)
    )
    integration = result.scalar_one_or_none()
    if not integration or not integration.lexoffice_connected:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Lexoffice ist nicht verbunden",
        )
    api_key = integration.lexoffice_api_key
    if not api_key:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Lexoffice API-Key fehlt",
        )
    return LexofficeService(api_key)


@router.post("/sync", response_model=SyncResponse)
async def sync_invoices(
    current_user: User = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    """Trigger a manual Lexoffice invoice sync (max once per 60 s per tenant)."""
    # --- Rate limiting ---
    now = datetime.now(timezone.utc)
    last = _last_manual_sync.get(current_user.id)
    if last is not None:
        elapsed = (now - last).total_seconds()
        if elapsed < MANUAL_SYNC_COOLDOWN_SECONDS:
            remaining = int(MANUAL_SYNC_COOLDOWN_SECONDS - elapsed)
            raise HTTPException(
                status_code=status.HTTP_429_TOO_MANY_REQUESTS,
                detail=f"Bitte warte noch {remaining}s vor dem nächsten Sync",
                headers={"Retry-After": str(remaining)},
            )

    lex_service = await _get_lexoffice_service(db, current_user)

    t_start = time.monotonic()
    try:
        result = await asyncio.to_thread(
            _run_sync, current_user.id, lex_service, db
        )
    except LexofficeAuthError:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Lexoffice API-Key ungueltig oder abgelaufen",
        )
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_502_BAD_GATEWAY,
            detail=f"Sync-Fehler: {e}",
        )
    duration = round(time.monotonic() - t_start, 2)

    # Record successful sync time
    _last_manual_sync[current_user.id] = datetime.now(timezone.utc)

    return SyncResponse(
        synced_count=result.synced_count,
        new_count=result.new_count,
        updated_count=result.updated_count,
        removed_count=result.removed_count,
        duration_seconds=duration,
    )


def _run_sync(tenant_id, lex_service, db):
    """Wrapper to call async sync from thread – we need a new event loop."""
    import asyncio as _asyncio

    loop = _asyncio.new_event_loop()
    try:
        with lex_service:
            return loop.run_until_complete(
                SyncService.sync_invoices(tenant_id, lex_service, db)
            )
    finally:
        loop.close()


@router.get("/keywords", response_model=list[KeywordCount])
async def list_keywords(
    current_user: User = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    """Return keyword counts for filter dropdown."""
    stmt = (
        select(Invoice.keyword, func.count().label("count"))
        .where(
            Invoice.tenant_id == current_user.id,
            Invoice.keyword.isnot(None),
        )
        .group_by(Invoice.keyword)
        .order_by(func.count().desc())
    )
    rows = (await db.execute(stmt)).all()
    return [KeywordCount(keyword=row[0], count=row[1]) for row in rows]


@router.get("", response_model=InvoiceListResponse)
async def list_invoices(
    current_user: User = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
    status_filter: str | None = Query(None, alias="status"),
    keyword_filter: str | None = Query(None, alias="keyword"),
    search: str | None = Query(None),
    page: int = Query(1, ge=1),
    per_page: int = Query(20, ge=1, le=100),
):
    """List invoices: only open/overdue with open/in_collection/failed."""
    base = select(Invoice).where(
        Invoice.tenant_id == current_user.id,
        Invoice.lexoffice_status.in_(["open", "overdue"]),
        Invoice.collection_status.in_([
            CollectionStatus.OPEN,
            CollectionStatus.IN_COLLECTION,
            CollectionStatus.FAILED,
        ]),
    )

    if status_filter:
        base = base.where(Invoice.collection_status == status_filter)

    if keyword_filter:
        base = base.where(Invoice.keyword == keyword_filter)

    if search:
        pattern = f"%{search}%"
        base = base.where(
            or_(
                Invoice.voucher_number.ilike(pattern),
                Invoice.contact_name.ilike(pattern),
            )
        )

    # Count
    count_stmt = select(func.count()).select_from(base.subquery())
    total = (await db.execute(count_stmt)).scalar() or 0
    total_pages = max(1, math.ceil(total / per_page))

    # Fetch page
    offset = (page - 1) * per_page
    rows_stmt = (
        base.order_by(Invoice.due_date.asc().nulls_last(), Invoice.created_at.asc())
        .offset(offset)
        .limit(per_page)
    )
    rows = (await db.execute(rows_stmt)).scalars().all()

    # Check which customers have active IBANs
    customer_ids = [r.customer_id for r in rows if r.customer_id]
    iban_map: dict[str, bool] = {}
    if customer_ids:
        iban_stmt = (
            select(CustomerIban.customer_id)
            .where(
                CustomerIban.customer_id.in_(customer_ids),
                CustomerIban.is_active.is_(True),
            )
            .distinct()
        )
        iban_results = (await db.execute(iban_stmt)).scalars().all()
        iban_map = {cid: True for cid in iban_results}

    items = []
    for inv in rows:
        item = InvoiceListItem(
            id=inv.id,
            lexoffice_invoice_id=inv.lexoffice_invoice_id,
            voucher_number=inv.voucher_number,
            customer_id=inv.customer_id,
            contact_name=inv.contact_name,
            total_gross_amount=inv.total_gross_amount,
            currency=inv.currency,
            due_date=inv.due_date,
            lexoffice_status=inv.lexoffice_status,
            collection_status=inv.collection_status.value
            if isinstance(inv.collection_status, CollectionStatus)
            else inv.collection_status,
            customer_has_iban=iban_map.get(inv.customer_id or "", False),
            keyword=inv.keyword,
            keyword_sepa=inv.keyword_sepa,
        )
        items.append(item)

    return InvoiceListResponse(
        items=items,
        total=total,
        page=page,
        per_page=per_page,
        total_pages=total_pages,
    )


@router.get("/{invoice_id}", response_model=InvoiceDetailResponse)
async def get_invoice(
    invoice_id: str,
    current_user: User = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    """Get invoice detail with customer, IBAN, mandate, collections."""
    stmt = (
        select(Invoice)
        .options(
            selectinload(Invoice.customer).selectinload(Customer.ibans),
            selectinload(Invoice.customer).selectinload(Customer.mandates),
            selectinload(Invoice.payment_collections),
        )
        .where(Invoice.id == invoice_id, Invoice.tenant_id == current_user.id)
    )
    invoice = (await db.execute(stmt)).scalar_one_or_none()
    if not invoice:
        raise HTTPException(status_code=404, detail="Rechnung nicht gefunden")

    customer_brief = None
    if invoice.customer:
        c = invoice.customer
        has_iban = any(ib.is_active for ib in c.ibans)
        has_mandate = any(m.is_active for m in c.mandates)
        customer_brief = CustomerBrief(
            id=c.id,
            name=c.name,
            customer_number=c.customer_number,
            email=c.email,
            is_walk_in=c.is_walk_in,
            has_active_iban=has_iban,
            has_active_mandate=has_mandate,
        )

    collections = [
        CollectionBrief(
            id=pc.id,
            amount_cents=pc.amount_cents,
            currency=pc.currency,
            stripe_status=pc.stripe_status,
            submitted_at=pc.submitted_at,
            completed_at=pc.completed_at,
            failure_reason=pc.failure_reason,
        )
        for pc in invoice.payment_collections
    ]

    return InvoiceDetailResponse(
        id=invoice.id,
        tenant_id=invoice.tenant_id,
        lexoffice_invoice_id=invoice.lexoffice_invoice_id,
        voucher_number=invoice.voucher_number,
        customer_id=invoice.customer_id,
        contact_name=invoice.contact_name,
        total_gross_amount=invoice.total_gross_amount,
        currency=invoice.currency,
        due_date=invoice.due_date,
        lexoffice_status=invoice.lexoffice_status,
        collection_status=invoice.collection_status.value
        if isinstance(invoice.collection_status, CollectionStatus)
        else invoice.collection_status,
        keyword=invoice.keyword,
        keyword_sepa=invoice.keyword_sepa,
        last_synced_at=invoice.last_synced_at,
        customer=customer_brief,
        collections=collections,
    )
