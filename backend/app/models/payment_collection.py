import uuid
from datetime import datetime

from sqlalchemy import DateTime, ForeignKey, Integer, String, Text, func
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.models import Base


class PaymentCollection(Base):
    __tablename__ = "payment_collections"

    id: Mapped[str] = mapped_column(
        String(36), primary_key=True, default=lambda: str(uuid.uuid4())
    )
    tenant_id: Mapped[str] = mapped_column(
        String(36), ForeignKey("users.id"), nullable=False, index=True
    )
    invoice_id: Mapped[str] = mapped_column(
        String(36), ForeignKey("invoices.id"), nullable=False, index=True
    )
    mandate_id: Mapped[str] = mapped_column(
        String(36), ForeignKey("sepa_mandates.id"), nullable=False, index=True
    )
    customer_iban_id: Mapped[str] = mapped_column(
        String(36), ForeignKey("customer_ibans.id"), nullable=False, index=True
    )
    amount_cents: Mapped[int] = mapped_column(Integer, nullable=False)
    currency: Mapped[str] = mapped_column(String(3), default="EUR")
    stripe_payment_intent_id: Mapped[str | None] = mapped_column(
        String(255), nullable=True
    )
    stripe_status: Mapped[str | None] = mapped_column(
        String(50), nullable=True
    )
    submitted_at: Mapped[datetime | None] = mapped_column(
        DateTime, nullable=True
    )
    completed_at: Mapped[datetime | None] = mapped_column(
        DateTime, nullable=True
    )
    failure_reason: Mapped[str | None] = mapped_column(Text, nullable=True)

    created_at: Mapped[datetime] = mapped_column(
        DateTime, server_default=func.now()
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime, server_default=func.now(), onupdate=func.now()
    )

    # Relationships
    tenant: Mapped["User"] = relationship("User", back_populates="payment_collections")
    invoice: Mapped["Invoice"] = relationship(
        "Invoice", back_populates="payment_collections"
    )
    mandate: Mapped["SepaMandate"] = relationship(
        "SepaMandate", back_populates="payment_collections"
    )
    customer_iban: Mapped["CustomerIban"] = relationship(
        "CustomerIban", back_populates="payment_collections"
    )
