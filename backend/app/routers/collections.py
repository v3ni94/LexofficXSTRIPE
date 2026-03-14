import asyncio
import logging
import math

from fastapi import APIRouter, Depends, HTTPException, Query, status
from pydantic import BaseModel
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.database import get_db
from app.models.payment_collection import PaymentCollection
from app.models.user import User
from app.schemas.collection import CollectionResponse
from app.services.collection_service import CollectionError, CollectionService
from app.utils.security import get_current_user

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/collections", tags=["collections"])


class SubmitRequest(BaseModel):
    invoice_id: str


class BatchSubmitRequest(BaseModel):
    invoice_ids: list[str]


class CollectionListResponse(BaseModel):
    items: list[CollectionResponse]
    total: int
    page: int
    per_page: int
    total_pages: int


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
    page: int = Query(1, ge=1),
    per_page: int = Query(20, ge=1, le=100),
):
    """List all collections for the tenant, paginated."""
    from sqlalchemy import func

    base = select(PaymentCollection).where(
        PaymentCollection.tenant_id == current_user.id
    )

    if status_filter:
        base = base.where(PaymentCollection.stripe_status == status_filter)

    count_stmt = select(func.count()).select_from(base.subquery())
    total = (await db.execute(count_stmt)).scalar() or 0
    total_pages = max(1, math.ceil(total / per_page))

    offset = (page - 1) * per_page
    rows = (
        await db.execute(
            base.order_by(PaymentCollection.submitted_at.desc())
            .offset(offset)
            .limit(per_page)
        )
    ).scalars().all()

    return CollectionListResponse(
        items=[CollectionResponse.model_validate(r) for r in rows],
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
