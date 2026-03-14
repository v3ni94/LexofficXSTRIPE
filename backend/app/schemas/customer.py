from pydantic import BaseModel


class CustomerCreate(BaseModel):
    customer_number: str
    name: str
    email: str | None = None
    lexoffice_contact_id: str | None = None


class CustomerResponse(BaseModel):
    id: str
    tenant_id: str
    lexoffice_contact_id: str | None
    customer_number: str
    name: str
    email: str | None
    is_walk_in: bool

    model_config = {"from_attributes": True}
