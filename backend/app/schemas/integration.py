from datetime import datetime

from pydantic import BaseModel


class IntegrationUpdate(BaseModel):
    lexoffice_api_key: str | None = None
    stripe_secret_key: str | None = None
    stripe_webhook_secret: str | None = None


class IntegrationResponse(BaseModel):
    id: str
    tenant_id: str
    lexoffice_connected: bool
    stripe_connected: bool
    lexoffice_last_sync: datetime | None

    model_config = {"from_attributes": True}
