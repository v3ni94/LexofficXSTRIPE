from sqlalchemy.orm import DeclarativeBase


class Base(DeclarativeBase):
    pass


from app.models.user import User  # noqa: E402, F401
from app.models.integration import Integration  # noqa: E402, F401
from app.models.customer import Customer  # noqa: E402, F401
from app.models.invoice import Invoice  # noqa: E402, F401
from app.models.iban import IBAN  # noqa: E402, F401
from app.models.mandate import Mandate  # noqa: E402, F401
from app.models.collection import Collection  # noqa: E402, F401
