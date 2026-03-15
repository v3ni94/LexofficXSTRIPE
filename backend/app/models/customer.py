import uuid
from datetime import datetime

from sqlalchemy import Boolean, DateTime, ForeignKey, String, UniqueConstraint, func
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.models import Base


class Customer(Base):
    __tablename__ = "customers"
    __table_args__ = (
        UniqueConstraint("tenant_id", "lexoffice_contact_id", name="uq_customer_tenant_lexoffice"),
        UniqueConstraint("tenant_id", "customer_number", name="uq_customer_tenant_number"),
    )

    id: Mapped[str] = mapped_column(
        String(36), primary_key=True, default=lambda: str(uuid.uuid4())
    )
    tenant_id: Mapped[str] = mapped_column(
        String(36), ForeignKey("organizations.id"), nullable=False, index=True
    )
    lexoffice_contact_id: Mapped[str | None] = mapped_column(
        String(36), nullable=True, index=True
    )
    customer_number: Mapped[str] = mapped_column(String(50), nullable=False)
    name: Mapped[str] = mapped_column(String(255), nullable=False)
    email: Mapped[str | None] = mapped_column(String(255), nullable=True)
    is_walk_in: Mapped[bool] = mapped_column(Boolean, default=False)

    created_at: Mapped[datetime] = mapped_column(
        DateTime, server_default=func.now()
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime, server_default=func.now(), onupdate=func.now()
    )

    # Relationships
    organization: Mapped["Organization"] = relationship("Organization", back_populates="customers")
    ibans: Mapped[list["CustomerIban"]] = relationship(
        "CustomerIban", back_populates="customer", cascade="all, delete-orphan"
    )
    mandates: Mapped[list["SepaMandate"]] = relationship(
        "SepaMandate", back_populates="customer", cascade="all, delete-orphan"
    )
    invoices: Mapped[list["Invoice"]] = relationship(
        "Invoice", back_populates="customer", cascade="all, delete-orphan"
    )
