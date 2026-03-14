from datetime import datetime
from decimal import Decimal

from pydantic import BaseModel


class CollectionCreate(BaseModel):
    invoice_id: str
    mandate_id: str
    customer_iban_id: str


class CollectionResponse(BaseModel):
    id: str
    tenant_id: str
    invoice_id: str
    mandate_id: str
    customer_iban_id: str
    amount_cents: int
    currency: str
    stripe_payment_intent_id: str | None
    stripe_status: str | None
    submitted_at: datetime | None
    completed_at: datetime | None
    failure_reason: str | None
    description: str | None = None

    model_config = {"from_attributes": True}


class CollectionListItem(BaseModel):
    """Enriched collection row for the collections list page."""
    id: str
    invoice_id: str
    voucher_number: str
    contact_name: str
    amount_cents: int
    currency: str
    iban_masked: str | None
    mandate_reference: str | None
    stripe_status: str | None
    submitted_at: datetime | None
    completed_at: datetime | None
    failure_reason: str | None
    description: str | None = None


class CollectionListResponse(BaseModel):
    items: list[CollectionListItem]
    total: int
    page: int
    per_page: int
    total_pages: int


class CollectionPreview(BaseModel):
    description: str
    keyword: str | None
    keyword_sepa: str | None
    voucher_number: str
    customer_number: str
    amount: float


class DashboardStats(BaseModel):
    open_invoices_count: int
    open_invoices_amount: Decimal
    in_collection_count: int
    in_collection_amount: Decimal
    collected_last_30_days_count: int
    collected_last_30_days_amount: Decimal
    failed_count: int
    failed_amount: Decimal
    lexoffice_connected: bool
    stripe_connected: bool
    last_sync: datetime | None
