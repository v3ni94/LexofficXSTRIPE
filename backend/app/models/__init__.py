from sqlalchemy.orm import DeclarativeBase


class Base(DeclarativeBase):
    pass


from app.models.user import User  # noqa: E402, F401
from app.models.integration import Integration  # noqa: E402, F401
from app.models.customer import Customer  # noqa: E402, F401
from app.models.customer_iban import CustomerIban  # noqa: E402, F401
from app.models.iban_history import IbanHistory  # noqa: E402, F401
from app.models.sepa_mandate import SepaMandate  # noqa: E402, F401
from app.models.invoice import Invoice  # noqa: E402, F401
from app.models.payment_collection import PaymentCollection  # noqa: E402, F401
