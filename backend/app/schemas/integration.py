from datetime import datetime

from pydantic import BaseModel


class IntegrationStatusResponse(BaseModel):
    lexoffice_connected: bool
    stripe_connected: bool
    lexoffice_last_sync: datetime | None

    model_config = {"from_attributes": True}


class LexofficeConnectRequest(BaseModel):
    api_key: str


class StripeConnectRequest(BaseModel):
    secret_key: str
    webhook_secret: str


class IntegrationConnectResponse(BaseModel):
    connected: bool
    message: str
