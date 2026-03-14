from pydantic import BaseModel


class IBANCreate(BaseModel):
    iban: str
    bic: str | None = None
    bank_name: str | None = None
    is_primary: bool = False


class IBANResponse(BaseModel):
    id: str
    iban: str
    bic: str | None
    bank_name: str | None
    is_primary: bool

    model_config = {"from_attributes": True}
