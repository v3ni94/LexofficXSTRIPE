import enum
import uuid
from datetime import date, datetime
from decimal import Decimal

from sqlalchemy import Date, DateTime, Enum, ForeignKey, Numeric, String, Text, UniqueConstraint, func
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.models import Base


class CollectionStatus(str, enum.Enum):
    NONE = "none"
    OPEN = "open"
    IN_COLLECTION = "in_collection"
    COLLECTED = "collected"
    FAILED = "failed"


class Invoice(Base):
    __tablename__ = "invoices"
    __table_args__ = (
        UniqueConstraint("tenant_id", "lexoffice_invoice_id", name="uq_invoice_tenant_lexoffice"),
    )

    id: Mapped[str] = mapped_column(
        String(36), primary_key=True, default=lambda: str(uuid.uuid4())
    )
    tenant_id: Mapped[str] = mapped_column(
        String(36), ForeignKey("users.id"), nullable=False, index=True
    )
    lexoffice_invoice_id: Mapped[str] = mapped_column(
        String(36), nullable=False, index=True
    )
    voucher_number: Mapped[str] = mapped_column(String(50), nullable=False)
    customer_id: Mapped[str | None] = mapped_column(
        String(36), ForeignKey("customers.id"), nullable=True, index=True
    )
    contact_name: Mapped[str] = mapped_column(String(255), nullable=False)
    total_gross_amount: Mapped[Decimal] = mapped_column(
        Numeric(10, 2), nullable=False
    )
    currency: Mapped[str] = mapped_column(String(3), default="EUR")
    due_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    lexoffice_status: Mapped[str] = mapped_column(String(50), nullable=False)
    collection_status: Mapped[CollectionStatus] = mapped_column(
        Enum(CollectionStatus, native_enum=False, length=20),
        default=CollectionStatus.NONE,
    )
    line_items_json: Mapped[str | None] = mapped_column(Text, nullable=True)
    keyword: Mapped[str | None] = mapped_column(String(100), nullable=True)
    keyword_sepa: Mapped[str | None] = mapped_column(String(100), nullable=True)
    last_synced_at: Mapped[datetime | None] = mapped_column(
        DateTime, nullable=True
    )

    created_at: Mapped[datetime] = mapped_column(
        DateTime, server_default=func.now()
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime, server_default=func.now(), onupdate=func.now()
    )

    # Relationships
    tenant: Mapped["User"] = relationship("User", back_populates="invoices")
    customer: Mapped["Customer | None"] = relationship(
        "Customer", back_populates="invoices"
    )
    payment_collections: Mapped[list["PaymentCollection"]] = relationship(
        "PaymentCollection", back_populates="invoice", cascade="all, delete-orphan"
    )
