from pydantic import BaseModel


class CustomerIbanCreate(BaseModel):
    iban: str
    bic: str | None = None
    account_holder_name: str


class CustomerIbanResponse(BaseModel):
    id: str
    customer_id: str
    iban: str
    bic: str | None
    account_holder_name: str
    is_active: bool

    model_config = {"from_attributes": True}
