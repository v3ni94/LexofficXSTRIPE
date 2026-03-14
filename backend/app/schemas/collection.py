from decimal import Decimal

from pydantic import BaseModel


class CollectionCreate(BaseModel):
    invoice_id: str
    mandate_id: str


class CollectionResponse(BaseModel):
    id: str
    invoice_id: str
    mandate_id: str
    stripe_payment_intent_id: str | None
    amount: Decimal
    currency: str
    status: str
    error_message: str | None

    model_config = {"from_attributes": True}
