"""add keyword and description fields

Revision ID: a7f4e1d82b3c
Revises: c2d3a9b13065
Create Date: 2026-03-14

"""
from typing import Sequence, Union

from alembic import op
import sqlalchemy as sa

# revision identifiers, used by Alembic.
revision: str = "a7f4e1d82b3c"
down_revision: Union[str, None] = "c2d3a9b13065"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.add_column("invoices", sa.Column("line_items_json", sa.Text(), nullable=True))
    op.add_column("invoices", sa.Column("keyword", sa.String(100), nullable=True))
    op.add_column("invoices", sa.Column("keyword_sepa", sa.String(100), nullable=True))
    op.add_column("payment_collections", sa.Column("description", sa.String(140), nullable=True))


def downgrade() -> None:
    op.drop_column("payment_collections", "description")
    op.drop_column("invoices", "keyword_sepa")
    op.drop_column("invoices", "keyword")
    op.drop_column("invoices", "line_items_json")
