from pydantic import BaseModel


class CustomerCreate(BaseModel):
    name: str
    email: str | None = None


class CustomerResponse(BaseModel):
    id: str
    name: str
    email: str | None
    lexoffice_id: str | None
    stripe_customer_id: str | None

    model_config = {"from_attributes": True}
