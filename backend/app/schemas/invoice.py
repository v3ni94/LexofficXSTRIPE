from datetime import date, datetime
from decimal import Decimal

from pydantic import BaseModel


class InvoiceResponse(BaseModel):
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
    last_synced_at: datetime | None

    model_config = {"from_attributes": True}
