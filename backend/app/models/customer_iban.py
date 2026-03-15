import uuid
from datetime import datetime

from sqlalchemy import Boolean, DateTime, ForeignKey, Index, String, func
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.models import Base


class CustomerIban(Base):
    __tablename__ = "customer_ibans"
    __table_args__ = (
        Index("ix_customer_iban_active", "customer_id", "is_active"),
    )

    id: Mapped[str] = mapped_column(
        String(36), primary_key=True, default=lambda: str(uuid.uuid4())
    )
    tenant_id: Mapped[str] = mapped_column(
        String(36), ForeignKey("organizations.id"), nullable=False, index=True
    )
    customer_id: Mapped[str] = mapped_column(
        String(36), ForeignKey("customers.id"), nullable=False, index=True
    )
    iban: Mapped[str] = mapped_column(String(34), nullable=False)
    bic: Mapped[str | None] = mapped_column(String(11), nullable=True)
    account_holder_name: Mapped[str] = mapped_column(String(255), nullable=False)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)

    created_at: Mapped[datetime] = mapped_column(
        DateTime, server_default=func.now()
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime, server_default=func.now(), onupdate=func.now()
    )

    # Relationships
    customer: Mapped["Customer"] = relationship("Customer", back_populates="ibans")
    history_entries: Mapped[list["IbanHistory"]] = relationship(
        "IbanHistory", back_populates="customer_iban", cascade="all, delete-orphan"
    )
    mandates: Mapped[list["SepaMandate"]] = relationship(
        "SepaMandate", back_populates="customer_iban"
    )
    payment_collections: Mapped[list["PaymentCollection"]] = relationship(
        "PaymentCollection", back_populates="customer_iban"
    )
