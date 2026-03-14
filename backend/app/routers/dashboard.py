from datetime import datetime, timedelta, timezone
from decimal import Decimal

from fastapi import APIRouter, Depends
from sqlalchemy import func, select
from sqlalchemy.ext.asyncio import AsyncSession

from app.database import get_db
from app.models.integration import Integration
from app.models.invoice import CollectionStatus, Invoice
from app.models.payment_collection import PaymentCollection
from app.models.user import User
from app.schemas.collection import DashboardStats
from app.utils.security import get_current_user

router = APIRouter(prefix="/dashboard", tags=["dashboard"])


@router.get("/stats", response_model=DashboardStats)
async def get_dashboard_stats(
    current_user: User = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    tenant_id = current_user.id

    # --- Invoice aggregates by collection_status ---
    inv_stmt = select(
        Invoice.collection_status,
        func.count().label("cnt"),
        func.coalesce(func.sum(Invoice.total_gross_amount), 0).label("total"),
    ).where(
        Invoice.tenant_id == tenant_id,
    ).group_by(Invoice.collection_status)

    inv_rows = (await db.execute(inv_stmt)).all()

    counts: dict[str, int] = {}
    amounts: dict[str, Decimal] = {}
    for row in inv_rows:
        key = row.collection_status.value if hasattr(row.collection_status, "value") else str(row.collection_status)
        counts[key] = row.cnt
        amounts[key] = Decimal(str(row.total))

    # --- Collected in last 30 days (via PaymentCollection completed_at) ---
    thirty_days_ago = datetime.now(timezone.utc) - timedelta(days=30)
    collected_stmt = select(
        func.count().label("cnt"),
        func.coalesce(func.sum(PaymentCollection.amount_cents), 0).label("total_cents"),
    ).where(
        PaymentCollection.tenant_id == tenant_id,
        PaymentCollection.stripe_status == "succeeded",
        PaymentCollection.completed_at >= thirty_days_ago,
    )
    collected_row = (await db.execute(collected_stmt)).one()
    collected_count = collected_row.cnt or 0
    collected_amount = Decimal(str(collected_row.total_cents or 0)) / 100

    # --- Failed collections aggregate ---
    failed_stmt = select(
        func.count().label("cnt"),
        func.coalesce(func.sum(PaymentCollection.amount_cents), 0).label("total_cents"),
    ).where(
        PaymentCollection.tenant_id == tenant_id,
        PaymentCollection.stripe_status.in_(["failed", "disputed"]),
    )
    failed_row = (await db.execute(failed_stmt)).one()
    failed_count = failed_row.cnt or 0
    failed_amount = Decimal(str(failed_row.total_cents or 0)) / 100

    # --- Integration status ---
    integration = (
        await db.execute(
            select(Integration).where(Integration.tenant_id == tenant_id)
        )
    ).scalar_one_or_none()

    lex_connected = integration.lexoffice_connected if integration else False
    stripe_connected = integration.stripe_connected if integration else False
    last_sync = integration.lexoffice_last_sync if integration else None

    return DashboardStats(
        open_invoices_count=counts.get(CollectionStatus.OPEN.value, 0),
        open_invoices_amount=amounts.get(CollectionStatus.OPEN.value, Decimal("0")),
        in_collection_count=counts.get(CollectionStatus.IN_COLLECTION.value, 0),
        in_collection_amount=amounts.get(CollectionStatus.IN_COLLECTION.value, Decimal("0")),
        collected_last_30_days_count=collected_count,
        collected_last_30_days_amount=collected_amount,
        failed_count=failed_count,
        failed_amount=failed_amount,
        lexoffice_connected=lex_connected,
        stripe_connected=stripe_connected,
        last_sync=last_sync,
    )


@router.get("/recent-collections")
async def get_recent_collections(
    current_user: User = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    """Return the 5 most recent collections with invoice/customer context."""
    from sqlalchemy.orm import selectinload
    from app.models.invoice import Invoice
    from app.models.sepa_mandate import SepaMandate
    from app.utils.iban import mask_iban

    stmt = (
        select(PaymentCollection)
        .where(PaymentCollection.tenant_id == current_user.id)
        .order_by(PaymentCollection.submitted_at.desc())
        .limit(5)
    )
    collections = (await db.execute(stmt)).scalars().all()

    # Load related invoices
    invoice_ids = [c.invoice_id for c in collections]
    invoices: dict[str, Invoice] = {}
    if invoice_ids:
        inv_rows = (
            await db.execute(select(Invoice).where(Invoice.id.in_(invoice_ids)))
        ).scalars().all()
        invoices = {inv.id: inv for inv in inv_rows}

    # Load related mandates
    mandate_ids = [c.mandate_id for c in collections]
    mandates: dict[str, SepaMandate] = {}
    if mandate_ids:
        m_rows = (
            await db.execute(select(SepaMandate).where(SepaMandate.id.in_(mandate_ids)))
        ).scalars().all()
        mandates = {m.id: m for m in m_rows}

    result = []
    for c in collections:
        inv = invoices.get(c.invoice_id)
        mandate = mandates.get(c.mandate_id)
        result.append({
            "id": c.id,
            "voucher_number": inv.voucher_number if inv else "-",
            "contact_name": inv.contact_name if inv else "-",
            "amount_cents": c.amount_cents,
            "currency": c.currency,
            "stripe_status": c.stripe_status,
            "mandate_reference": mandate.mandate_reference if mandate else None,
            "submitted_at": c.submitted_at.isoformat() if c.submitted_at else None,
            "failure_reason": c.failure_reason,
        })
    return result


@router.get("/keyword-stats")
async def get_keyword_stats(
    current_user: User = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    """Return collection counts grouped by invoice keyword."""
    stmt = (
        select(
            Invoice.keyword,
            func.count().label("count"),
            func.coalesce(func.sum(PaymentCollection.amount_cents), 0).label("total_cents"),
        )
        .join(PaymentCollection, PaymentCollection.invoice_id == Invoice.id)
        .where(
            PaymentCollection.tenant_id == current_user.id,
            Invoice.keyword.isnot(None),
        )
        .group_by(Invoice.keyword)
        .order_by(func.count().desc())
    )
    rows = (await db.execute(stmt)).all()
    return [
        {"keyword": row[0], "count": row[1], "amount_cents": row[2]}
        for row in rows
    ]


@router.get("/upcoming-invoices")
async def get_upcoming_invoices(
    current_user: User = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    """Return 5 invoices with the nearest due dates (open/failed only)."""
    stmt = (
        select(Invoice)
        .where(
            Invoice.tenant_id == current_user.id,
            Invoice.collection_status.in_([CollectionStatus.OPEN, CollectionStatus.FAILED]),
            Invoice.lexoffice_status.in_(["open", "overdue"]),
            Invoice.due_date.is_not(None),
        )
        .order_by(Invoice.due_date.asc())
        .limit(5)
    )
    invoices = (await db.execute(stmt)).scalars().all()

    return [
        {
            "id": inv.id,
            "voucher_number": inv.voucher_number,
            "contact_name": inv.contact_name,
            "total_gross_amount": float(inv.total_gross_amount),
            "currency": inv.currency,
            "due_date": inv.due_date.isoformat() if inv.due_date else None,
            "collection_status": inv.collection_status.value
            if hasattr(inv.collection_status, "value")
            else inv.collection_status,
        }
        for inv in invoices
    ]
