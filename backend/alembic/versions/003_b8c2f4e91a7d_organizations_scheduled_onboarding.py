"""Add organizations, scheduled collections, and onboarding.

Revision ID: b8c2f4e91a7d
Revises: a7f4e1d82b3c
Create Date: 2026-03-14

Combined migration for:
1. Organizations, organization_members, invitations tables
2. Users: remove company_name, add display_name
3. Payment collections: scheduled_date, is_scheduled, scheduled_submitted
4. Invoices: collection_status enum + 'scheduled'
5. All tenant_id FKs: users.id → organizations.id
6. Data migration: existing users → organizations + members
"""
from alembic import op
import sqlalchemy as sa


# revision identifiers, used by Alembic.
revision = "b8c2f4e91a7d"
down_revision = "a7f4e1d82b3c"
branch_labels = None
depends_on = None


def upgrade() -> None:
    # -----------------------------------------------------------------------
    # 1. Create organizations table
    # -----------------------------------------------------------------------
    op.create_table(
        "organizations",
        sa.Column("id", sa.String(36), primary_key=True),
        sa.Column("name", sa.String(255), nullable=False),
        sa.Column("onboarding_completed", sa.Boolean(), server_default=sa.text("0"), nullable=False),
        sa.Column("onboarding_step", sa.Integer(), server_default=sa.text("0"), nullable=False),
        sa.Column("created_at", sa.DateTime(), server_default=sa.func.now()),
        sa.Column("updated_at", sa.DateTime(), server_default=sa.func.now(), onupdate=sa.func.now()),
    )

    # -----------------------------------------------------------------------
    # 2. Data migration: create an organization for each existing user
    # -----------------------------------------------------------------------
    conn = op.get_bind()

    # Read existing users
    users = conn.execute(
        sa.text("SELECT id, company_name, created_at FROM users")
    ).fetchall()

    for user_row in users:
        user_id = user_row[0]
        company_name = user_row[1] or "Meine Organisation"
        created_at = user_row[2]

        # Create organization with same ID as user (simplifies FK migration)
        conn.execute(
            sa.text(
                "INSERT INTO organizations (id, name, onboarding_completed, onboarding_step, created_at) "
                "VALUES (:id, :name, 1, 5, :created_at)"
            ),
            {"id": user_id, "name": company_name, "created_at": created_at},
        )

    # -----------------------------------------------------------------------
    # 3. Create organization_members table
    # -----------------------------------------------------------------------
    op.create_table(
        "organization_members",
        sa.Column("id", sa.String(36), primary_key=True),
        sa.Column("organization_id", sa.String(36), sa.ForeignKey("organizations.id"), nullable=False, index=True),
        sa.Column("user_id", sa.String(36), sa.ForeignKey("users.id"), nullable=False, index=True),
        sa.Column("role", sa.String(20), nullable=False, server_default="owner"),
        sa.Column("created_at", sa.DateTime(), server_default=sa.func.now()),
        sa.Column("updated_at", sa.DateTime(), server_default=sa.func.now(), onupdate=sa.func.now()),
        sa.UniqueConstraint("organization_id", "user_id", name="uq_org_member_user"),
    )

    # Data migration: create membership for each user
    import uuid
    for user_row in users:
        user_id = user_row[0]
        conn.execute(
            sa.text(
                "INSERT INTO organization_members (id, organization_id, user_id, role) "
                "VALUES (:id, :org_id, :user_id, 'owner')"
            ),
            {"id": str(uuid.uuid4()), "org_id": user_id, "user_id": user_id},
        )

    # -----------------------------------------------------------------------
    # 4. Create invitations table
    # -----------------------------------------------------------------------
    op.create_table(
        "invitations",
        sa.Column("id", sa.String(36), primary_key=True),
        sa.Column("organization_id", sa.String(36), sa.ForeignKey("organizations.id"), nullable=False, index=True),
        sa.Column("email", sa.String(255), nullable=False, index=True),
        sa.Column("role", sa.String(20), nullable=False, server_default="member"),
        sa.Column("token", sa.String(64), unique=True, nullable=False),
        sa.Column("invited_by_user_id", sa.String(36), sa.ForeignKey("users.id"), nullable=False),
        sa.Column("status", sa.String(20), server_default="pending"),
        sa.Column("expires_at", sa.DateTime(), nullable=False),
        sa.Column("created_at", sa.DateTime(), server_default=sa.func.now()),
        sa.UniqueConstraint("organization_id", "email", name="uq_invitation_org_email"),
    )

    # -----------------------------------------------------------------------
    # 5. Update users table: add display_name, drop company_name
    # -----------------------------------------------------------------------
    op.add_column("users", sa.Column("display_name", sa.String(255), nullable=True))

    # Copy company_name to display_name before dropping
    conn.execute(sa.text("UPDATE users SET display_name = company_name"))

    op.drop_column("users", "company_name")

    # -----------------------------------------------------------------------
    # 6. Migrate tenant_id FKs from users.id → organizations.id
    #    Since we used the same IDs for orgs, the data is already correct.
    #    We need to drop old FKs and create new ones.
    # -----------------------------------------------------------------------
    # For SQLite (tests) we can't alter FKs, so we use batch mode.
    # For MySQL (production), batch_alter_table also works.

    tables_with_tenant_id = [
        "integrations",
        "customers",
        "customer_ibans",
        "invoices",
        "payment_collections",
        "sepa_mandates",
        "iban_history",
    ]

    for table_name in tables_with_tenant_id:
        with op.batch_alter_table(table_name) as batch_op:
            # Drop old FK to users.id (convention-based name)
            try:
                batch_op.drop_constraint(f"fk_{table_name}_tenant_id_users", type_="foreignkey")
            except Exception:
                pass  # FK name may differ; batch mode handles recreation
            # Create new FK to organizations.id
            batch_op.create_foreign_key(
                f"fk_{table_name}_tenant_id_organizations",
                "organizations",
                ["tenant_id"],
                ["id"],
            )

    # -----------------------------------------------------------------------
    # 7. Add scheduled collection fields to payment_collections
    # -----------------------------------------------------------------------
    op.add_column("payment_collections", sa.Column("scheduled_date", sa.Date(), nullable=True))
    op.add_column("payment_collections", sa.Column("is_scheduled", sa.Boolean(), server_default=sa.text("0"), nullable=False))
    op.add_column("payment_collections", sa.Column("scheduled_submitted", sa.Boolean(), server_default=sa.text("0"), nullable=False))

    # -----------------------------------------------------------------------
    # 8. Extend collection_status enum with 'scheduled'
    #    Since we use native_enum=False (VARCHAR), no DDL change needed.
    #    The model already includes SCHEDULED.
    # -----------------------------------------------------------------------


def downgrade() -> None:
    # Remove scheduled fields
    op.drop_column("payment_collections", "scheduled_submitted")
    op.drop_column("payment_collections", "is_scheduled")
    op.drop_column("payment_collections", "scheduled_date")

    # Revert tenant_id FKs back to users.id
    tables_with_tenant_id = [
        "integrations",
        "customers",
        "customer_ibans",
        "invoices",
        "payment_collections",
        "sepa_mandates",
        "iban_history",
    ]
    for table_name in tables_with_tenant_id:
        with op.batch_alter_table(table_name) as batch_op:
            try:
                batch_op.drop_constraint(f"fk_{table_name}_tenant_id_organizations", type_="foreignkey")
            except Exception:
                pass
            batch_op.create_foreign_key(
                f"fk_{table_name}_tenant_id_users",
                "users",
                ["tenant_id"],
                ["id"],
            )

    # Re-add company_name, drop display_name
    op.add_column("users", sa.Column("company_name", sa.String(255), nullable=True))
    conn = op.get_bind()
    conn.execute(sa.text("UPDATE users SET company_name = COALESCE(display_name, 'Unbekannt')"))
    op.drop_column("users", "display_name")

    # Drop tables
    op.drop_table("invitations")
    op.drop_table("organization_members")
    op.drop_table("organizations")
