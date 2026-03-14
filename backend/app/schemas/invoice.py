from datetime import date, datetime
from decimal import Decimal

from pydantic import BaseModel


class InvoiceListItem(BaseModel):
    id: str
    lexoffice_invoice_id: str
    voucher_number: str
    customer_id: str | None
    contact_name: str
    total_gross_amount: Decimal
    currency: str
    due_date: date | None
    lexoffice_status: str
    collection_status: str
    customer_has_iban: bool = False
    keyword: str | None = None
    keyword_sepa: str | None = None

    model_config = {"from_attributes": True}


class InvoiceListResponse(BaseModel):
    items: list[InvoiceListItem]
    total: int
    page: int
    per_page: int
    total_pages: int


class InvoiceDetailResponse(BaseModel):
    id: str
    tenant_id: str
    lexoffice_invoice_id: str
    voucher_number: str
    customer_id: str | None
    contact_name: str
    total_gross_amount: Decimal
    currency: str
    due_date: date | None
    lexoffice_status: str
    collection_status: str
    keyword: str | None = None
    keyword_sepa: str | None = None
    last_synced_at: datetime | None
    customer: "CustomerBrief | None" = None
    collections: list["CollectionBrief"] = []

    model_config = {"from_attributes": True}


class CustomerBrief(BaseModel):
    id: str
    name: str
    customer_number: str
    email: str | None
    is_walk_in: bool
    has_active_iban: bool = False
    has_active_mandate: bool = False

    model_config = {"from_attributes": True}


class CollectionBrief(BaseModel):
    id: str
    amount_cents: int
    currency: str
    stripe_status: str | None
    submitted_at: datetime | None
    completed_at: datetime | None
    failure_reason: str | None

    model_config = {"from_attributes": True}


class KeywordCount(BaseModel):
    keyword: str
    count: int


class SyncResponse(BaseModel):
    synced_count: int
    new_count: int
    updated_count: int
    removed_count: int = 0
    duration_seconds: float = 0.0
