import uuid
from datetime import date, datetime

from sqlalchemy import Boolean, Date, DateTime, ForeignKey, String, UniqueConstraint, func
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.models import Base


class SepaMandate(Base):
    __tablename__ = "sepa_mandates"
    __table_args__ = (
        UniqueConstraint("tenant_id", "mandate_reference", name="uq_mandate_tenant_reference"),
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
    customer_iban_id: Mapped[str] = mapped_column(
        String(36), ForeignKey("customer_ibans.id"), nullable=False, index=True
    )
    mandate_reference: Mapped[str] = mapped_column(String(35), nullable=False)
    mandate_date: Mapped[date] = mapped_column(Date, nullable=False)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    stripe_payment_method_id: Mapped[str | None] = mapped_column(
        String(255), nullable=True
    )
    stripe_customer_id: Mapped[str | None] = mapped_column(
        String(255), nullable=True
    )

    created_at: Mapped[datetime] = mapped_column(
        DateTime, server_default=func.now()
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime, server_default=func.now(), onupdate=func.now()
    )

    # Relationships
    customer: Mapped["Customer"] = relationship("Customer", back_populates="mandates")
    customer_iban: Mapped["CustomerIban"] = relationship(
        "CustomerIban", back_populates="mandates"
    )
    payment_collections: Mapped[list["PaymentCollection"]] = relationship(
        "PaymentCollection", back_populates="mandate"
    )
