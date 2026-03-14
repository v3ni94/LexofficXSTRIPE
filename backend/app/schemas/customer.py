from datetime import datetime

from pydantic import BaseModel


class CustomerListItem(BaseModel):
    id: str
    customer_number: str
    name: str
    email: str | None
    is_walk_in: bool
    active_iban_masked: str | None = None
    mandate_count: int = 0
    open_invoice_count: int = 0

    model_config = {"from_attributes": True}


class CustomerListResponse(BaseModel):
    items: list[CustomerListItem]
    total: int
    page: int
    per_page: int
    total_pages: int


class IbanInfo(BaseModel):
    id: str
    iban_masked: str
    iban_formatted: str | None = None
    bic: str | None
    account_holder_name: str
    is_active: bool
    created_at: datetime


class MandateInfo(BaseModel):
    id: str
    mandate_reference: str
    mandate_date: str
    is_active: bool
    stripe_payment_method_id: str | None


class InvoiceBrief(BaseModel):
    id: str
    voucher_number: str
    total_gross_amount: float
    currency: str
    due_date: str | None
    collection_status: str


class CustomerDetailResponse(BaseModel):
    id: str
    customer_number: str
    name: str
    email: str | None
    is_walk_in: bool
    lexoffice_contact_id: str | None
    ibans: list[IbanInfo]
    mandates: list[MandateInfo]
    open_invoices: list[InvoiceBrief]


class IbanCreateRequest(BaseModel):
    iban: str
    account_holder_name: str
    bic: str | None = None
    change_reason: str | None = None


class IbanCreateForInvoiceRequest(BaseModel):
    iban: str
    account_holder_name: str
    invoice_id: str
    bic: str | None = None


class IbanHistoryItem(BaseModel):
    id: str
    action: str
    old_iban: str | None
    new_iban: str | None
    changed_by: str
    change_reason: str | None
    created_at: datetime

    model_config = {"from_attributes": True}


class IbanUpdateResponse(BaseModel):
    message: str
    iban_id: str
