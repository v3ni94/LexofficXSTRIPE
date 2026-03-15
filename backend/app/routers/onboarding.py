import asyncio
import logging

from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.database import get_db
from app.models.integration import Integration
from app.models.organization import Organization
from app.services.sync_service import SyncService
from app.services.lexoffice_service import LexofficeService
from app.utils.security import UserContext, get_user_context

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/onboarding", tags=["onboarding"])


class OnboardingStatusResponse(BaseModel):
    completed: bool
    current_step: int
    steps: dict


class CompleteStepRequest(BaseModel):
    step: int


class CompleteStepResponse(BaseModel):
    current_step: int
    completed: bool
    synced_count: int | None = None


@router.get("/status", response_model=OnboardingStatusResponse)
async def onboarding_status(
    ctx: UserContext = Depends(get_user_context),
    db: AsyncSession = Depends(get_db),
):
    org = (
        await db.execute(
            select(Organization).where(Organization.id == ctx.organization_id)
        )
    ).scalar_one_or_none()

    if not org:
        raise HTTPException(status_code=404, detail="Organisation nicht gefunden")

    integration = (
        await db.execute(
            select(Integration).where(Integration.tenant_id == ctx.organization_id)
        )
    ).scalar_one_or_none()

    return OnboardingStatusResponse(
        completed=org.onboarding_completed,
        current_step=org.onboarding_step,
        steps={
            "company_confirmed": org.onboarding_step >= 1,
            "lexoffice_connected": integration.lexoffice_connected if integration else False,
            "stripe_connected": integration.stripe_connected if integration else False,
            "first_sync_done": org.onboarding_step >= 4,
        },
    )


def _run_sync(tenant_id, lex_service, db):
    import asyncio as _asyncio
    loop = _asyncio.new_event_loop()
    try:
        with lex_service:
            return loop.run_until_complete(
                SyncService.sync_invoices(tenant_id, lex_service, db)
            )
    finally:
        loop.close()


@router.put("/complete-step", response_model=CompleteStepResponse)
async def complete_step(
    body: CompleteStepRequest,
    ctx: UserContext = Depends(get_user_context),
    db: AsyncSession = Depends(get_db),
):
    org = (
        await db.execute(
            select(Organization).where(Organization.id == ctx.organization_id)
        )
    ).scalar_one_or_none()

    if not org:
        raise HTTPException(status_code=404, detail="Organisation nicht gefunden")

    integration = (
        await db.execute(
            select(Integration).where(Integration.tenant_id == ctx.organization_id)
        )
    ).scalar_one_or_none()

    synced_count = None

    if body.step == 1:
        # Company confirmed — just advance
        org.onboarding_step = max(org.onboarding_step, 1)

    elif body.step == 2:
        # Verify Lexoffice is connected
        if not integration or not integration.lexoffice_connected:
            raise HTTPException(
                status_code=400,
                detail="Lexoffice ist noch nicht verbunden",
            )
        org.onboarding_step = max(org.onboarding_step, 2)

    elif body.step == 3:
        # Verify Stripe is connected
        if not integration or not integration.stripe_connected:
            raise HTTPException(
                status_code=400,
                detail="Stripe ist noch nicht verbunden",
            )
        org.onboarding_step = max(org.onboarding_step, 3)

    elif body.step == 4:
        # Trigger first sync
        if not integration or not integration.lexoffice_connected:
            raise HTTPException(
                status_code=400,
                detail="Lexoffice muss zuerst verbunden werden",
            )

        api_key = integration.lexoffice_api_key
        if not api_key:
            raise HTTPException(status_code=400, detail="Lexoffice API-Key fehlt")

        lex_service = LexofficeService(api_key)
        try:
            result = await asyncio.to_thread(
                _run_sync, ctx.organization_id, lex_service, db
            )
            synced_count = result.synced_count
        except Exception as e:
            logger.error("Onboarding sync failed: %s", e)
            raise HTTPException(status_code=502, detail=f"Sync-Fehler: {e}")

        org.onboarding_step = max(org.onboarding_step, 4)

    elif body.step == 5:
        # Complete onboarding
        org.onboarding_step = 5
        org.onboarding_completed = True

    else:
        raise HTTPException(status_code=422, detail="Ungueltiger Schritt")

    await db.flush()
    await db.refresh(org)

    return CompleteStepResponse(
        current_step=org.onboarding_step,
        completed=org.onboarding_completed,
        synced_count=synced_count,
    )
