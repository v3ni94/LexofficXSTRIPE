from datetime import datetime
from decimal import Decimal

from pydantic import BaseModel


class InvoiceResponse(BaseModel):
    id: str
    customer_id: str
    lexoffice_id: str | None
    invoice_number: str | None
    amount: Decimal
    currency: str
    status: str
    due_date: datetime | None

    model_config = {"from_attributes": True}
