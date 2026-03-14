"""initial schema

Revision ID: c2d3a9b13065
Revises:
Create Date: 2026-03-14

"""
from typing import Sequence, Union

from alembic import op
import sqlalchemy as sa

# revision identifiers, used by Alembic.
revision: str = "c2d3a9b13065"
down_revision: Union[str, None] = None
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    # --- users ---
    op.create_table(
        "users",
        sa.Column("id", sa.String(36), primary_key=True),
        sa.Column("email", sa.String(255), nullable=False),
        sa.Column("hashed_password", sa.String(255), nullable=False),
        sa.Column("company_name", sa.String(255), nullable=False),
        sa.Column("is_active", sa.Boolean(), nullable=False, server_default=sa.text("1")),
        sa.Column("created_at", sa.DateTime(), nullable=False, server_default=sa.func.now()),
        sa.Column("updated_at", sa.DateTime(), nullable=False, server_default=sa.func.now()),
    )
    op.create_index("ix_users_email", "users", ["email"], unique=True)

    # --- integrations ---
    op.create_table(
        "integrations",
        sa.Column("id", sa.String(36), primary_key=True),
        sa.Column("tenant_id", sa.String(36), sa.ForeignKey("users.id"), nullable=False),
        sa.Column("lexoffice_api_key_encrypted", sa.Text(), nullable=True),
        sa.Column("stripe_secret_key_encrypted", sa.Text(), nullable=True),
        sa.Column("stripe_webhook_secret_encrypted", sa.Text(), nullable=True),
        sa.Column("lexoffice_connected", sa.Boolean(), nullable=False, server_default=sa.text("0")),
        sa.Column("stripe_connected", sa.Boolean(), nullable=False, server_default=sa.text("0")),
        sa.Column("lexoffice_last_sync", sa.DateTime(), nullable=True),
        sa.Column("created_at", sa.DateTime(), nullable=False, server_default=sa.func.now()),
        sa.Column("updated_at", sa.DateTime(), nullable=False, server_default=sa.func.now()),
    )
    op.create_index("ix_integrations_tenant_id", "integrations", ["tenant_id"], unique=True)

    # --- customers ---
    op.create_table(
        "customers",
        sa.Column("id", sa.String(36), primary_key=True),
        sa.Column("tenant_id", sa.String(36), sa.ForeignKey("users.id"), nullable=False),
        sa.Column("lexoffice_contact_id", sa.String(36), nullable=True),
        sa.Column("customer_number", sa.String(50), nullable=False),
        sa.Column("name", sa.String(255), nullable=False),
        sa.Column("email", sa.String(255), nullable=True),
        sa.Column("is_walk_in", sa.Boolean(), nullable=False, server_default=sa.text("0")),
        sa.Column("created_at", sa.DateTime(), nullable=False, server_default=sa.func.now()),
        sa.Column("updated_at", sa.DateTime(), nullable=False, server_default=sa.func.now()),
    )
    op.create_index("ix_customers_tenant_id", "customers", ["tenant_id"])
    op.create_index("ix_customers_lexoffice_contact_id", "customers", ["lexoffice_contact_id"])
    op.create_unique_constraint("uq_customer_tenant_lexoffice", "customers", ["tenant_id", "lexoffice_contact_id"])
    op.create_unique_constraint("uq_customer_tenant_number", "customers", ["tenant_id", "customer_number"])

    # --- customer_ibans ---
    op.create_table(
        "customer_ibans",
        sa.Column("id", sa.String(36), primary_key=True),
        sa.Column("tenant_id", sa.String(36), sa.ForeignKey("users.id"), nullable=False),
        sa.Column("customer_id", sa.String(36), sa.ForeignKey("customers.id"), nullable=False),
        sa.Column("iban", sa.String(34), nullable=False),
        sa.Column("bic", sa.String(11), nullable=True),
        sa.Column("account_holder_name", sa.String(255), nullable=False),
        sa.Column("is_active", sa.Boolean(), nullable=False, server_default=sa.text("1")),
        sa.Column("created_at", sa.DateTime(), nullable=False, server_default=sa.func.now()),
        sa.Column("updated_at", sa.DateTime(), nullable=False, server_default=sa.func.now()),
    )
    op.create_index("ix_customer_ibans_tenant_id", "customer_ibans", ["tenant_id"])
    op.create_index("ix_customer_ibans_customer_id", "customer_ibans", ["customer_id"])
    op.create_index("ix_customer_iban_active", "customer_ibans", ["customer_id", "is_active"])

    # --- iban_history ---
    op.create_table(
        "iban_history",
        sa.Column("id", sa.String(36), primary_key=True),
        sa.Column("tenant_id", sa.String(36), sa.ForeignKey("users.id"), nullable=False),
        sa.Column("customer_iban_id", sa.String(36), sa.ForeignKey("customer_ibans.id"), nullable=False),
        sa.Column("action", sa.String(20), nullable=False),
        sa.Column("old_iban", sa.String(34), nullable=True),
        sa.Column("new_iban", sa.String(34), nullable=True),
        sa.Column("changed_by", sa.String(36), sa.ForeignKey("users.id"), nullable=False),
        sa.Column("change_reason", sa.Text(), nullable=True),
        sa.Column("created_at", sa.DateTime(), nullable=False, server_default=sa.func.now()),
    )
    op.create_index("ix_iban_history_tenant_id", "iban_history", ["tenant_id"])
    op.create_index("ix_iban_history_customer_iban_id", "iban_history", ["customer_iban_id"])

    # --- sepa_mandates ---
    op.create_table(
        "sepa_mandates",
        sa.Column("id", sa.String(36), primary_key=True),
        sa.Column("tenant_id", sa.String(36), sa.ForeignKey("users.id"), nullable=False),
        sa.Column("customer_id", sa.String(36), sa.ForeignKey("customers.id"), nullable=False),
        sa.Column("customer_iban_id", sa.String(36), sa.ForeignKey("customer_ibans.id"), nullable=False),
        sa.Column("mandate_reference", sa.String(35), nullable=False),
        sa.Column("mandate_date", sa.Date(), nullable=False),
        sa.Column("is_active", sa.Boolean(), nullable=False, server_default=sa.text("1")),
        sa.Column("stripe_payment_method_id", sa.String(255), nullable=True),
        sa.Column("stripe_customer_id", sa.String(255), nullable=True),
        sa.Column("created_at", sa.DateTime(), nullable=False, server_default=sa.func.now()),
        sa.Column("updated_at", sa.DateTime(), nullable=False, server_default=sa.func.now()),
    )
    op.create_index("ix_sepa_mandates_tenant_id", "sepa_mandates", ["tenant_id"])
    op.create_index("ix_sepa_mandates_customer_id", "sepa_mandates", ["customer_id"])
    op.create_index("ix_sepa_mandates_customer_iban_id", "sepa_mandates", ["customer_iban_id"])
    op.create_unique_constraint("uq_mandate_tenant_reference", "sepa_mandates", ["tenant_id", "mandate_reference"])

    # --- invoices ---
    op.create_table(
        "invoices",
        sa.Column("id", sa.String(36), primary_key=True),
        sa.Column("tenant_id", sa.String(36), sa.ForeignKey("users.id"), nullable=False),
        sa.Column("lexoffice_invoice_id", sa.String(36), nullable=False),
        sa.Column("voucher_number", sa.String(50), nullable=False),
        sa.Column("customer_id", sa.String(36), sa.ForeignKey("customers.id"), nullable=True),
        sa.Column("contact_name", sa.String(255), nullable=False),
        sa.Column("total_gross_amount", sa.Numeric(10, 2), nullable=False),
        sa.Column("currency", sa.String(3), nullable=False, server_default="EUR"),
        sa.Column("due_date", sa.Date(), nullable=True),
        sa.Column("lexoffice_status", sa.String(50), nullable=False),
        sa.Column("collection_status", sa.String(20), nullable=False, server_default="none"),
        sa.Column("last_synced_at", sa.DateTime(), nullable=True),
        sa.Column("created_at", sa.DateTime(), nullable=False, server_default=sa.func.now()),
        sa.Column("updated_at", sa.DateTime(), nullable=False, server_default=sa.func.now()),
    )
    op.create_index("ix_invoices_tenant_id", "invoices", ["tenant_id"])
    op.create_index("ix_invoices_lexoffice_invoice_id", "invoices", ["lexoffice_invoice_id"])
    op.create_index("ix_invoices_customer_id", "invoices", ["customer_id"])
    op.create_unique_constraint("uq_invoice_tenant_lexoffice", "invoices", ["tenant_id", "lexoffice_invoice_id"])

    # --- payment_collections ---
    op.create_table(
        "payment_collections",
        sa.Column("id", sa.String(36), primary_key=True),
        sa.Column("tenant_id", sa.String(36), sa.ForeignKey("users.id"), nullable=False),
        sa.Column("invoice_id", sa.String(36), sa.ForeignKey("invoices.id"), nullable=False),
        sa.Column("mandate_id", sa.String(36), sa.ForeignKey("sepa_mandates.id"), nullable=False),
        sa.Column("customer_iban_id", sa.String(36), sa.ForeignKey("customer_ibans.id"), nullable=False),
        sa.Column("amount_cents", sa.Integer(), nullable=False),
        sa.Column("currency", sa.String(3), nullable=False, server_default="EUR"),
        sa.Column("stripe_payment_intent_id", sa.String(255), nullable=True),
        sa.Column("stripe_status", sa.String(50), nullable=True),
        sa.Column("submitted_at", sa.DateTime(), nullable=True),
        sa.Column("completed_at", sa.DateTime(), nullable=True),
        sa.Column("failure_reason", sa.Text(), nullable=True),
        sa.Column("created_at", sa.DateTime(), nullable=False, server_default=sa.func.now()),
        sa.Column("updated_at", sa.DateTime(), nullable=False, server_default=sa.func.now()),
    )
    op.create_index("ix_payment_collections_tenant_id", "payment_collections", ["tenant_id"])
    op.create_index("ix_payment_collections_invoice_id", "payment_collections", ["invoice_id"])
    op.create_index("ix_payment_collections_mandate_id", "payment_collections", ["mandate_id"])
    op.create_index("ix_payment_collections_customer_iban_id", "payment_collections", ["customer_iban_id"])


def downgrade() -> None:
    op.drop_table("payment_collections")
    op.drop_table("invoices")
    op.drop_table("sepa_mandates")
    op.drop_table("iban_history")
    op.drop_table("customer_ibans")
    op.drop_table("customers")
    op.drop_table("integrations")
    op.drop_table("users")
