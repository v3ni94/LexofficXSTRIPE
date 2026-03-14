import json
import logging
from datetime import datetime, timezone
from decimal import Decimal

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.models.customer import Customer
from app.models.integration import Integration
from app.models.invoice import CollectionStatus, Invoice
from app.services.invoice_keyword_service import InvoiceKeywordService
from app.services.lexoffice_service import LexofficeService

logger = logging.getLogger(__name__)


class SyncResult:
    def __init__(self):
        self.synced_count = 0
        self.new_count = 0
        self.updated_count = 0
        self.removed_count = 0


class SyncService:
    @staticmethod
    async def sync_invoices(
        tenant_id: str,
        lexoffice_service: LexofficeService,
        db: AsyncSession,
    ) -> SyncResult:
        result = SyncResult()
        seen_lexoffice_ids: set[str] = set()

        # --- Fetch all open + overdue vouchers from Lexoffice ---
        for voucher in lexoffice_service.get_open_invoices_paginated():
            voucher_id = voucher.get("id")
            if not voucher_id:
                continue

            seen_lexoffice_ids.add(voucher_id)
            result.synced_count += 1

            # Get full invoice detail
            detail = lexoffice_service.get_invoice_detail(voucher_id)

            # --- Resolve customer ---
            customer_id = None
            contact_name = _extract_contact_name(detail)
            address = detail.get("address", {})
            contact_id = address.get("contactId")

            if contact_id:
                customer_id = await _upsert_customer(
                    db, tenant_id, contact_id, contact_name, lexoffice_service
                )

            # --- Extract invoice fields ---
            voucher_number = detail.get("voucherNumber", voucher.get("voucherNumber", ""))
            total_amount = _extract_total_gross(detail)
            currency = detail.get("totalPrice", {}).get("currency", "EUR")
            due_date = _parse_date(detail.get("dueDate"))
            lex_status = detail.get("voucherStatus", voucher.get("voucherStatus", "open"))

            # --- Extract keyword from line items ---
            line_items = detail.get("lineItems", [])
            line_items_json_str = json.dumps(line_items, ensure_ascii=False) if line_items else None
            keyword_service = InvoiceKeywordService()
            kw_display, kw_sepa = keyword_service.extract_keyword(line_items)

            # --- Upsert invoice ---
            stmt = select(Invoice).where(
                Invoice.tenant_id == tenant_id,
                Invoice.lexoffice_invoice_id == voucher_id,
            )
            existing = (await db.execute(stmt)).scalar_one_or_none()

            if existing:
                existing.voucher_number = voucher_number
                existing.customer_id = customer_id
                existing.contact_name = contact_name
                existing.total_gross_amount = total_amount
                existing.currency = currency
                existing.due_date = due_date
                existing.lexoffice_status = lex_status
                existing.last_synced_at = datetime.now(timezone.utc)

                # Recalculate keyword only if line items changed
                if line_items_json_str != existing.line_items_json:
                    existing.line_items_json = line_items_json_str
                    existing.keyword = kw_display
                    existing.keyword_sepa = kw_sepa

                # If lexoffice says paid, mark as collected
                if lex_status == "paid" and existing.collection_status != CollectionStatus.COLLECTED:
                    existing.collection_status = CollectionStatus.COLLECTED

                result.updated_count += 1
            else:
                new_invoice = Invoice(
                    tenant_id=tenant_id,
                    lexoffice_invoice_id=voucher_id,
                    voucher_number=voucher_number,
                    customer_id=customer_id,
                    contact_name=contact_name,
                    total_gross_amount=total_amount,
                    currency=currency,
                    due_date=due_date,
                    lexoffice_status=lex_status,
                    collection_status=CollectionStatus.OPEN,
                    line_items_json=line_items_json_str,
                    keyword=kw_display,
                    keyword_sepa=kw_sepa,
                    last_synced_at=datetime.now(timezone.utc),
                )
                db.add(new_invoice)
                result.new_count += 1

        # --- Check local invoices no longer open/overdue in Lexoffice ---
        local_open_stmt = select(Invoice).where(
            Invoice.tenant_id == tenant_id,
            Invoice.lexoffice_status.in_(["open", "overdue"]),
        )
        local_open = (await db.execute(local_open_stmt)).scalars().all()

        for inv in local_open:
            if inv.lexoffice_invoice_id not in seen_lexoffice_ids:
                # Invoice disappeared from open/overdue list – re-check
                try:
                    detail = lexoffice_service.get_invoice_detail(
                        inv.lexoffice_invoice_id
                    )
                    new_status = detail.get("voucherStatus", "unknown")
                    inv.lexoffice_status = new_status
                    inv.last_synced_at = datetime.now(timezone.utc)

                    if new_status in ("paid",):
                        inv.collection_status = CollectionStatus.COLLECTED
                        result.removed_count += 1
                    elif new_status in ("voided", "cancelled"):
                        inv.collection_status = CollectionStatus.NONE
                        result.removed_count += 1

                    result.updated_count += 1
                except Exception:
                    logger.warning(
                        "Konnte Rechnung %s nicht pruefen", inv.lexoffice_invoice_id
                    )

        # --- Update last sync timestamp ---
        integ_stmt = select(Integration).where(Integration.tenant_id == tenant_id)
        integration = (await db.execute(integ_stmt)).scalar_one_or_none()
        if integration:
            integration.lexoffice_last_sync = datetime.now(timezone.utc)

        await db.flush()
        return result


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------


async def _upsert_customer(
    db: AsyncSession,
    tenant_id: str,
    contact_id: str,
    fallback_name: str,
    lexoffice_service: LexofficeService,
) -> str:
    """Upsert a customer by lexoffice_contact_id, return customer.id."""
    stmt = select(Customer).where(
        Customer.tenant_id == tenant_id,
        Customer.lexoffice_contact_id == contact_id,
    )
    existing = (await db.execute(stmt)).scalar_one_or_none()

    # Fetch contact details from Lexoffice
    try:
        contact = lexoffice_service.get_contact(contact_id)
    except Exception:
        contact = {}

    name = _extract_customer_name(contact) or fallback_name
    customer_number = str(
        contact.get("roles", {})
        .get("customer", {})
        .get("number", "10001")
    )
    email = _extract_email(contact)
    is_walk_in = customer_number == "10001"

    if existing:
        existing.name = name
        existing.customer_number = customer_number
        existing.email = email
        existing.is_walk_in = is_walk_in
        await db.flush()
        return existing.id

    customer = Customer(
        tenant_id=tenant_id,
        lexoffice_contact_id=contact_id,
        customer_number=customer_number,
        name=name,
        email=email,
        is_walk_in=is_walk_in,
    )
    db.add(customer)
    await db.flush()
    return customer.id


def _extract_contact_name(detail: dict) -> str:
    address = detail.get("address", {})
    name = address.get("name")
    if name:
        return name
    # Fallback: company or first+last
    company = address.get("supplement")
    if company:
        return company
    return "Unbekannt"


def _extract_customer_name(contact: dict) -> str | None:
    company = contact.get("company", {})
    if company and company.get("name"):
        return company["name"]
    person = contact.get("person", {})
    first = person.get("firstName", "")
    last = person.get("lastName", "")
    full = f"{first} {last}".strip()
    return full or None


def _extract_email(contact: dict) -> str | None:
    emails = contact.get("emailAddresses", {})
    # Try business, then office, then private, then other
    for key in ("business", "office", "private", "other"):
        addrs = emails.get(key, [])
        if addrs:
            return addrs[0]
    return None


def _extract_total_gross(detail: dict) -> Decimal:
    total_price = detail.get("totalPrice", {})
    gross = total_price.get("totalGrossAmount", 0)
    return Decimal(str(gross))


def _parse_date(value) -> None:
    if not value:
        return None
    from datetime import date

    if isinstance(value, str):
        try:
            return date.fromisoformat(value[:10])
        except ValueError:
            return None
    return None
