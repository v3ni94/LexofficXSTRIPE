from datetime import datetime

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

    model_config = {"from_attributes": True}
