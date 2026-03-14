import enum
import uuid
from datetime import datetime

from sqlalchemy import DateTime, Enum, ForeignKey, String, Text, func
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.models import Base


class IbanAction(str, enum.Enum):
    CREATED = "created"
    DEACTIVATED = "deactivated"
    REACTIVATED = "reactivated"


class IbanHistory(Base):
    __tablename__ = "iban_history"

    id: Mapped[str] = mapped_column(
        String(36), primary_key=True, default=lambda: str(uuid.uuid4())
    )
    tenant_id: Mapped[str] = mapped_column(
        String(36), ForeignKey("users.id"), nullable=False, index=True
    )
    customer_iban_id: Mapped[str] = mapped_column(
        String(36), ForeignKey("customer_ibans.id"), nullable=False, index=True
    )
    action: Mapped[IbanAction] = mapped_column(
        Enum(IbanAction, native_enum=False, length=20), nullable=False
    )
    old_iban: Mapped[str | None] = mapped_column(String(34), nullable=True)
    new_iban: Mapped[str | None] = mapped_column(String(34), nullable=True)
    changed_by: Mapped[str] = mapped_column(
        String(36), ForeignKey("users.id"), nullable=False
    )
    change_reason: Mapped[str | None] = mapped_column(Text, nullable=True)

    created_at: Mapped[datetime] = mapped_column(
        DateTime, server_default=func.now()
    )

    # Relationships
    customer_iban: Mapped["CustomerIban"] = relationship(
        "CustomerIban", back_populates="history_entries"
    )
