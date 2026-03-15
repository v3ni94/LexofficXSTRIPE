import math

from fastapi import APIRouter, Depends, HTTPException, Query, status
from sqlalchemy import func, or_, select
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.orm import selectinload

from app.database import get_db
from app.models.customer import Customer
from app.models.customer_iban import CustomerIban
from app.models.iban_history import IbanAction, IbanHistory
from app.models.invoice import CollectionStatus, Invoice
from app.models.sepa_mandate import SepaMandate
from app.schemas.customer import (
    CustomerDetailResponse,
    CustomerListItem,
    CustomerListResponse,
    IbanCreateForInvoiceRequest,
    IbanCreateRequest,
    IbanHistoryItem,
    IbanInfo,
    IbanUpdateResponse,
    InvoiceBrief,
    MandateInfo,
)
from app.utils.iban import format_iban, mask_iban, validate_iban
from app.utils.security import UserContext, get_user_context

router = APIRouter(prefix="/customers", tags=["customers"])


@router.get("", response_model=CustomerListResponse)
async def list_customers(
    ctx: UserContext = Depends(get_user_context),
    db: AsyncSession = Depends(get_db),
    search: str | None = Query(None),
    page: int = Query(1, ge=1),
    per_page: int = Query(20, ge=1, le=100),
):
    base = select(Customer).where(Customer.tenant_id == ctx.organization_id)

    if search:
        pattern = f"%{search}%"
        base = base.where(
            or_(
                Customer.name.ilike(pattern),
                Customer.customer_number.ilike(pattern),
            )
        )

    count_stmt = select(func.count()).select_from(base.subquery())
    total = (await db.execute(count_stmt)).scalar() or 0
    total_pages = max(1, math.ceil(total / per_page))

    offset = (page - 1) * per_page
    rows = (
        await db.execute(
            base.options(
                selectinload(Customer.ibans),
                selectinload(Customer.mandates),
            )
            .order_by(Customer.customer_number.asc())
            .offset(offset)
            .limit(per_page)
        )
    ).scalars().all()

    customer_ids = [c.id for c in rows]
    inv_counts: dict[str, int] = {}
    if customer_ids:
        inv_stmt = (
            select(Invoice.customer_id, func.count())
            .where(
                Invoice.customer_id.in_(customer_ids),
                Invoice.lexoffice_status.in_(["open", "overdue"]),
                Invoice.collection_status.in_([
                    CollectionStatus.OPEN,
                    CollectionStatus.IN_COLLECTION,
                    CollectionStatus.FAILED,
                ]),
            )
            .group_by(Invoice.customer_id)
        )
        for cid, cnt in await db.execute(inv_stmt):
            inv_counts[cid] = cnt

    items = []
    for c in rows:
        active_iban = next((ib for ib in c.ibans if ib.is_active), None)
        items.append(
            CustomerListItem(
                id=c.id,
                customer_number=c.customer_number,
                name=c.name,
                email=c.email,
                is_walk_in=c.is_walk_in,
                active_iban_masked=mask_iban(active_iban.iban) if active_iban else None,
                mandate_count=sum(1 for m in c.mandates if m.is_active),
                open_invoice_count=inv_counts.get(c.id, 0),
            )
        )

    return CustomerListResponse(
        items=items, total=total, page=page, per_page=per_page, total_pages=total_pages
    )


@router.get("/{customer_id}", response_model=CustomerDetailResponse)
async def get_customer(
    customer_id: str,
    ctx: UserContext = Depends(get_user_context),
    db: AsyncSession = Depends(get_db),
):
    stmt = (
        select(Customer)
        .options(
            selectinload(Customer.ibans),
            selectinload(Customer.mandates),
        )
        .where(Customer.id == customer_id, Customer.tenant_id == ctx.organization_id)
    )
    customer = (await db.execute(stmt)).scalar_one_or_none()
    if not customer:
        raise HTTPException(status_code=404, detail="Kunde nicht gefunden")

    inv_stmt = select(Invoice).where(
        Invoice.customer_id == customer_id,
        Invoice.tenant_id == ctx.organization_id,
        Invoice.lexoffice_status.in_(["open", "overdue"]),
        Invoice.collection_status.in_([
            CollectionStatus.OPEN,
            CollectionStatus.IN_COLLECTION,
            CollectionStatus.FAILED,
        ]),
    ).order_by(Invoice.due_date.asc().nulls_last())
    invoices = (await db.execute(inv_stmt)).scalars().all()

    return CustomerDetailResponse(
        id=customer.id,
        customer_number=customer.customer_number,
        name=customer.name,
        email=customer.email,
        is_walk_in=customer.is_walk_in,
        lexoffice_contact_id=customer.lexoffice_contact_id,
        ibans=[
            IbanInfo(
                id=ib.id,
                iban_masked=mask_iban(ib.iban),
                iban_formatted=format_iban(ib.iban) if ib.is_active else None,
                bic=ib.bic,
                account_holder_name=ib.account_holder_name,
                is_active=ib.is_active,
                created_at=ib.created_at,
            )
            for ib in sorted(customer.ibans, key=lambda x: x.created_at, reverse=True)
        ],
        mandates=[
            MandateInfo(
                id=m.id,
                mandate_reference=m.mandate_reference,
                mandate_date=str(m.mandate_date),
                is_active=m.is_active,
                stripe_payment_method_id=m.stripe_payment_method_id,
            )
            for m in customer.mandates
        ],
        open_invoices=[
            InvoiceBrief(
                id=inv.id,
                voucher_number=inv.voucher_number,
                total_gross_amount=float(inv.total_gross_amount),
                currency=inv.currency,
                due_date=str(inv.due_date) if inv.due_date else None,
                collection_status=inv.collection_status.value
                if hasattr(inv.collection_status, "value")
                else inv.collection_status,
            )
            for inv in invoices
        ],
    )


@router.put("/{customer_id}/iban", response_model=IbanUpdateResponse)
async def update_iban(
    customer_id: str,
    data: IbanCreateRequest,
    ctx: UserContext = Depends(get_user_context),
    db: AsyncSession = Depends(get_db),
):
    valid, result = validate_iban(data.iban)
    if not valid:
        raise HTTPException(status_code=422, detail=result)
    cleaned_iban = result

    customer = (
        await db.execute(
            select(Customer)
            .options(selectinload(Customer.ibans))
            .where(Customer.id == customer_id, Customer.tenant_id == ctx.organization_id)
        )
    ).scalar_one_or_none()
    if not customer:
        raise HTTPException(status_code=404, detail="Kunde nicht gefunden")

    for ib in customer.ibans:
        if ib.is_active:
            ib.is_active = False
            db.add(IbanHistory(
                tenant_id=ctx.organization_id,
                customer_iban_id=ib.id,
                action=IbanAction.DEACTIVATED,
                old_iban=ib.iban,
                changed_by=ctx.user_id,
                change_reason=data.change_reason,
            ))

    new_iban = CustomerIban(
        tenant_id=ctx.organization_id,
        customer_id=customer_id,
        iban=cleaned_iban,
        bic=data.bic,
        account_holder_name=data.account_holder_name,
        is_active=True,
    )
    db.add(new_iban)
    await db.flush()

    db.add(IbanHistory(
        tenant_id=ctx.organization_id,
        customer_iban_id=new_iban.id,
        action=IbanAction.CREATED,
        new_iban=cleaned_iban,
        changed_by=ctx.user_id,
        change_reason=data.change_reason,
    ))

    mandate_stmt = select(SepaMandate).where(
        SepaMandate.tenant_id == ctx.organization_id,
        SepaMandate.customer_id == customer_id,
        SepaMandate.is_active.is_(True),
    )
    mandate = (await db.execute(mandate_stmt)).scalar_one_or_none()
    if mandate:
        mandate.customer_iban_id = new_iban.id
        mandate.stripe_payment_method_id = None

    await db.flush()

    return IbanUpdateResponse(
        message="IBAN erfolgreich aktualisiert", iban_id=new_iban.id
    )


@router.post("/{customer_id}/iban", response_model=IbanUpdateResponse)
async def create_iban_for_invoice(
    customer_id: str,
    data: IbanCreateForInvoiceRequest,
    ctx: UserContext = Depends(get_user_context),
    db: AsyncSession = Depends(get_db),
):
    valid, result = validate_iban(data.iban)
    if not valid:
        raise HTTPException(status_code=422, detail=result)
    cleaned_iban = result

    customer = (
        await db.execute(
            select(Customer).where(
                Customer.id == customer_id, Customer.tenant_id == ctx.organization_id
            )
        )
    ).scalar_one_or_none()
    if not customer:
        raise HTTPException(status_code=404, detail="Kunde nicht gefunden")

    invoice = (
        await db.execute(
            select(Invoice).where(
                Invoice.id == data.invoice_id,
                Invoice.tenant_id == ctx.organization_id,
                Invoice.customer_id == customer_id,
            )
        )
    ).scalar_one_or_none()
    if not invoice:
        raise HTTPException(status_code=404, detail="Rechnung nicht gefunden")

    new_iban = CustomerIban(
        tenant_id=ctx.organization_id,
        customer_id=customer_id,
        iban=cleaned_iban,
        bic=data.bic,
        account_holder_name=data.account_holder_name,
        is_active=True,
    )
    db.add(new_iban)
    await db.flush()

    db.add(IbanHistory(
        tenant_id=ctx.organization_id,
        customer_iban_id=new_iban.id,
        action=IbanAction.CREATED,
        new_iban=cleaned_iban,
        changed_by=ctx.user_id,
        change_reason=f"Laufkunde, Rechnung {invoice.voucher_number}",
    ))

    await db.flush()

    return IbanUpdateResponse(
        message="IBAN fuer Rechnung hinterlegt", iban_id=new_iban.id
    )


@router.get("/{customer_id}/iban-history", response_model=list[IbanHistoryItem])
async def get_iban_history(
    customer_id: str,
    ctx: UserContext = Depends(get_user_context),
    db: AsyncSession = Depends(get_db),
):
    customer = (
        await db.execute(
            select(Customer).where(
                Customer.id == customer_id, Customer.tenant_id == ctx.organization_id
            )
        )
    ).scalar_one_or_none()
    if not customer:
        raise HTTPException(status_code=404, detail="Kunde nicht gefunden")

    iban_ids_stmt = select(CustomerIban.id).where(
        CustomerIban.customer_id == customer_id
    )
    iban_ids = (await db.execute(iban_ids_stmt)).scalars().all()

    if not iban_ids:
        return []

    history_stmt = (
        select(IbanHistory)
        .where(IbanHistory.customer_iban_id.in_(iban_ids))
        .order_by(IbanHistory.created_at.desc())
    )
    entries = (await db.execute(history_stmt)).scalars().all()

    return [
        IbanHistoryItem(
            id=e.id,
            action=e.action.value if hasattr(e.action, "value") else e.action,
            old_iban=mask_iban(e.old_iban) if e.old_iban else None,
            new_iban=mask_iban(e.new_iban) if e.new_iban else None,
            changed_by=e.changed_by,
            change_reason=e.change_reason,
            created_at=e.created_at,
        )
        for e in entries
    ]
