import httpx
from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.config import settings
from app.database import get_db
from app.models.integration import Integration
from app.schemas.integration import (
    IntegrationConnectResponse,
    IntegrationStatusResponse,
    LexofficeConnectRequest,
    StripeConnectRequest,
)
from app.utils.security import UserContext, require_role, get_user_context

router = APIRouter(prefix="/integrations", tags=["integrations"])


async def _get_integration(db: AsyncSession, org_id: str) -> Integration:
    result = await db.execute(
        select(Integration).where(Integration.tenant_id == org_id)
    )
    integration = result.scalar_one_or_none()
    if integration is None:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Integration not found",
        )
    return integration


@router.get("", response_model=IntegrationStatusResponse)
async def get_integration_status(
    ctx: UserContext = Depends(get_user_context),
    db: AsyncSession = Depends(get_db),
):
    """Return connection status. NEVER return the keys themselves."""
    integration = await _get_integration(db, ctx.organization_id)
    return integration


@router.put("/lexoffice", response_model=IntegrationConnectResponse)
async def connect_lexoffice(
    data: LexofficeConnectRequest,
    ctx: UserContext = Depends(require_role("owner", "admin")),
    db: AsyncSession = Depends(get_db),
):
    """Test the Lexoffice API key, then store encrypted if valid."""
    async with httpx.AsyncClient() as client:
        try:
            resp = await client.get(
                f"{settings.LEXOFFICE_API_URL}/profile",
                headers={"Authorization": f"Bearer {data.api_key}"},
                timeout=10.0,
            )
        except httpx.RequestError:
            raise HTTPException(
                status_code=status.HTTP_502_BAD_GATEWAY,
                detail="Lexoffice API nicht erreichbar",
            )

    if resp.status_code != 200:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="API-Key ungueltig",
        )

    integration = await _get_integration(db, ctx.organization_id)
    integration.lexoffice_api_key = data.api_key
    integration.lexoffice_connected = True
    await db.flush()

    return IntegrationConnectResponse(
        connected=True, message="Lexoffice erfolgreich verbunden"
    )


@router.put("/stripe", response_model=IntegrationConnectResponse)
async def connect_stripe(
    data: StripeConnectRequest,
    ctx: UserContext = Depends(require_role("owner", "admin")),
    db: AsyncSession = Depends(get_db),
):
    """Test the Stripe secret key, then store encrypted if valid."""
    async with httpx.AsyncClient() as client:
        try:
            resp = await client.get(
                "https://api.stripe.com/v1/account",
                auth=(data.secret_key, ""),
                timeout=10.0,
            )
        except httpx.RequestError:
            raise HTTPException(
                status_code=status.HTTP_502_BAD_GATEWAY,
                detail="Stripe API nicht erreichbar",
            )

    if resp.status_code != 200:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Stripe Secret Key ungueltig",
        )

    account_data = resp.json()
    capabilities = account_data.get("capabilities", {})
    sepa_status = capabilities.get("sepa_debit_payments")
    if sepa_status not in ("active", "pending"):
        async with httpx.AsyncClient() as client:
            pm_resp = await client.get(
                "https://api.stripe.com/v1/payment_methods",
                params={"type": "sepa_debit", "limit": "1"},
                auth=(data.secret_key, ""),
                timeout=10.0,
            )
        if pm_resp.status_code != 200:
            raise HTTPException(
                status_code=status.HTTP_400_BAD_REQUEST,
                detail="SEPA-Lastschrift ist in deinem Stripe-Account nicht aktiviert",
            )

    integration = await _get_integration(db, ctx.organization_id)
    integration.stripe_secret_key = data.secret_key
    integration.stripe_webhook_secret = data.webhook_secret
    integration.stripe_connected = True
    await db.flush()

    return IntegrationConnectResponse(
        connected=True, message="Stripe erfolgreich verbunden"
    )


@router.delete("/lexoffice", response_model=IntegrationConnectResponse)
async def disconnect_lexoffice(
    ctx: UserContext = Depends(require_role("owner", "admin")),
    db: AsyncSession = Depends(get_db),
):
    integration = await _get_integration(db, ctx.organization_id)
    integration.lexoffice_api_key = None
    integration.lexoffice_connected = False
    integration.lexoffice_last_sync = None
    await db.flush()

    return IntegrationConnectResponse(
        connected=False, message="Lexoffice-Verbindung getrennt"
    )


@router.delete("/stripe", response_model=IntegrationConnectResponse)
async def disconnect_stripe(
    ctx: UserContext = Depends(require_role("owner", "admin")),
    db: AsyncSession = Depends(get_db),
):
    integration = await _get_integration(db, ctx.organization_id)
    integration.stripe_secret_key = None
    integration.stripe_webhook_secret = None
    integration.stripe_connected = False
    await db.flush()

    return IntegrationConnectResponse(
        connected=False, message="Stripe-Verbindung getrennt"
    )
