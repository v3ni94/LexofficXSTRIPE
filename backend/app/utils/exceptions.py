from fastapi import HTTPException, status


class LexofficeAPIError(HTTPException):
    def __init__(self, detail: str = "Lexoffice API error"):
        super().__init__(status_code=status.HTTP_502_BAD_GATEWAY, detail=detail)


class StripeAPIError(HTTPException):
    def __init__(self, detail: str = "Stripe API error"):
        super().__init__(status_code=status.HTTP_502_BAD_GATEWAY, detail=detail)


class InvalidIBANError(HTTPException):
    def __init__(self):
        super().__init__(status_code=status.HTTP_422_UNPROCESSABLE_ENTITY, detail="Invalid IBAN")
