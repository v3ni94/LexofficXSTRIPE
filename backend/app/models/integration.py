import uuid
from datetime import datetime

from sqlalchemy import Boolean, DateTime, ForeignKey, String, Text, func
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.models import Base


class Integration(Base):
    __tablename__ = "integrations"

    id: Mapped[str] = mapped_column(
        String(36), primary_key=True, default=lambda: str(uuid.uuid4())
    )
    tenant_id: Mapped[str] = mapped_column(
        String(36), ForeignKey("users.id"), unique=True, nullable=False, index=True
    )

    # Encrypted API keys — NEVER stored in plaintext
    lexoffice_api_key_encrypted: Mapped[str | None] = mapped_column(Text, nullable=True)
    stripe_secret_key_encrypted: Mapped[str | None] = mapped_column(Text, nullable=True)
    stripe_webhook_secret_encrypted: Mapped[str | None] = mapped_column(Text, nullable=True)

    lexoffice_connected: Mapped[bool] = mapped_column(Boolean, default=False)
    stripe_connected: Mapped[bool] = mapped_column(Boolean, default=False)
    lexoffice_last_sync: Mapped[datetime | None] = mapped_column(DateTime, nullable=True)

    created_at: Mapped[datetime] = mapped_column(
        DateTime, server_default=func.now()
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime, server_default=func.now(), onupdate=func.now()
    )

    # Relationships
    tenant: Mapped["User"] = relationship("User", back_populates="integration")

    # --- Fernet property helpers ---
    @property
    def lexoffice_api_key(self) -> str | None:
        if self.lexoffice_api_key_encrypted is None:
            return None
        from app.utils.security import decrypt_value
        return decrypt_value(self.lexoffice_api_key_encrypted)

    @lexoffice_api_key.setter
    def lexoffice_api_key(self, value: str | None) -> None:
        if value is None:
            self.lexoffice_api_key_encrypted = None
            return
        from app.utils.security import encrypt_value
        self.lexoffice_api_key_encrypted = encrypt_value(value)

    @property
    def stripe_secret_key(self) -> str | None:
        if self.stripe_secret_key_encrypted is None:
            return None
        from app.utils.security import decrypt_value
        return decrypt_value(self.stripe_secret_key_encrypted)

    @stripe_secret_key.setter
    def stripe_secret_key(self, value: str | None) -> None:
        if value is None:
            self.stripe_secret_key_encrypted = None
            return
        from app.utils.security import encrypt_value
        self.stripe_secret_key_encrypted = encrypt_value(value)

    @property
    def stripe_webhook_secret(self) -> str | None:
        if self.stripe_webhook_secret_encrypted is None:
            return None
        from app.utils.security import decrypt_value
        return decrypt_value(self.stripe_webhook_secret_encrypted)

    @stripe_webhook_secret.setter
    def stripe_webhook_secret(self, value: str | None) -> None:
        if value is None:
            self.stripe_webhook_secret_encrypted = None
            return
        from app.utils.security import encrypt_value
        self.stripe_webhook_secret_encrypted = encrypt_value(value)
