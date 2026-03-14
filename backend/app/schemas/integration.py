from pydantic import BaseModel


class IntegrationCreate(BaseModel):
    provider: str  # "lexoffice" or "stripe"
    api_key: str


class IntegrationResponse(BaseModel):
    id: str
    provider: str
    is_active: bool

    model_config = {"from_attributes": True}
