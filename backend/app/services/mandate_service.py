from datetime import date

from sqlalchemy import select, func
from sqlalchemy.ext.asyncio import AsyncSession

from app.models.customer import Customer
from app.models.sepa_mandate import SepaMandate


class MandateService:
    @staticmethod
    async def get_or_create_mandate(
        tenant_id: str,
        customer_id: str,
        customer_iban_id: str,
        db: AsyncSession,
    ) -> SepaMandate:
        # 1. Check for existing active mandate for this customer
        stmt = select(SepaMandate).where(
            SepaMandate.tenant_id == tenant_id,
            SepaMandate.customer_id == customer_id,
            SepaMandate.is_active.is_(True),
        )
        existing = (await db.execute(stmt)).scalar_one_or_none()

        if existing:
            # Update IBAN on existing mandate if changed
            if existing.customer_iban_id != customer_iban_id:
                existing.customer_iban_id = customer_iban_id
                # Clear Stripe payment method — needs re-creation on next collection
                existing.stripe_payment_method_id = None
                await db.flush()
            return existing

        # 2. Load customer to determine mandate reference format
        customer = (
            await db.execute(select(Customer).where(Customer.id == customer_id))
        ).scalar_one()

        # 3. Generate mandate reference
        if not customer.is_walk_in:
            # Stammkunde: HVM + customer_number, e.g. "HVM10045"
            mandate_ref = f"HVM{customer.customer_number}"
        else:
            # Laufkunde: HVM + YYYYMMDD + 3-digit counter, e.g. "HVM20260314000"
            today = date.today()
            date_str = today.strftime("%Y%m%d")
            prefix = f"HVM{date_str}"

            # Count existing mandates for this tenant with this date prefix
            count_stmt = select(func.count()).where(
                SepaMandate.tenant_id == tenant_id,
                SepaMandate.mandate_reference.like(f"{prefix}%"),
            )
            count = (await db.execute(count_stmt)).scalar() or 0
            mandate_ref = f"{prefix}{str(count).zfill(3)}"

        # 4. Create new mandate
        mandate = SepaMandate(
            tenant_id=tenant_id,
            customer_id=customer_id,
            customer_iban_id=customer_iban_id,
            mandate_reference=mandate_ref,
            mandate_date=date.today(),
            is_active=True,
        )
        db.add(mandate)
        await db.flush()
        await db.refresh(mandate)
        return mandate
