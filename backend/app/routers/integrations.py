import httpx
from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.config import settings
from app.database import get_db
from app.models.integration import Integration
from app.models.user import User
from app.schemas.integration import (
    IntegrationConnectResponse,
    IntegrationStatusResponse,
    LexofficeConnectRequest,
    StripeConnectRequest,
)
from app.utils.security import get_current_user

router = APIRouter(prefix="/integrations", tags=["integrations"])


async def _get_integration(db: AsyncSession, user: User) -> Integration:
    result = await db.execute(
        select(Integration).where(Integration.tenant_id == user.id)
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
    current_user: User = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    """Return connection status. NEVER return the keys themselves."""
    integration = await _get_integration(db, current_user)
    return integration


@router.put("/lexoffice", response_model=IntegrationConnectResponse)
async def connect_lexoffice(
    data: LexofficeConnectRequest,
    current_user: User = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    """Test the Lexoffice API key, then store encrypted if valid."""
    # 1. Test the key against Lexoffice profile endpoint
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

    # 2. Store encrypted
    integration = await _get_integration(db, current_user)
    integration.lexoffice_api_key = data.api_key
    integration.lexoffice_connected = True
    await db.flush()

    return IntegrationConnectResponse(
        connected=True, message="Lexoffice erfolgreich verbunden"
    )


@router.put("/stripe", response_model=IntegrationConnectResponse)
async def connect_stripe(
    data: StripeConnectRequest,
    current_user: User = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    """Test the Stripe secret key, then store encrypted if valid."""
    # 1. Retrieve account to validate key
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

    # 2. Check if SEPA Direct Debit is enabled
    account_data = resp.json()
    capabilities = account_data.get("capabilities", {})
    sepa_status = capabilities.get("sepa_debit_payments")
    if sepa_status not in ("active", "pending"):
        # Also check payment_methods on the account for broader compatibility
        async with httpx.AsyncClient() as client:
            pm_resp = await client.get(
                "https://api.stripe.com/v1/payment_methods",
                params={"type": "sepa_debit", "limit": "1"},
                auth=(data.secret_key, ""),
                timeout=10.0,
            )
        # If capability not listed, warn but don't block
        if pm_resp.status_code != 200:
            raise HTTPException(
                status_code=status.HTTP_400_BAD_REQUEST,
                detail="SEPA-Lastschrift ist in deinem Stripe-Account nicht aktiviert",
            )

    # 3. Store encrypted
    integration = await _get_integration(db, current_user)
    integration.stripe_secret_key = data.secret_key
    integration.stripe_webhook_secret = data.webhook_secret
    integration.stripe_connected = True
    await db.flush()

    return IntegrationConnectResponse(
        connected=True, message="Stripe erfolgreich verbunden"
    )


@router.delete("/lexoffice", response_model=IntegrationConnectResponse)
async def disconnect_lexoffice(
    current_user: User = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    integration = await _get_integration(db, current_user)
    integration.lexoffice_api_key = None
    integration.lexoffice_connected = False
    integration.lexoffice_last_sync = None
    await db.flush()

    return IntegrationConnectResponse(
        connected=False, message="Lexoffice-Verbindung getrennt"
    )


@router.delete("/stripe", response_model=IntegrationConnectResponse)
async def disconnect_stripe(
    current_user: User = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    integration = await _get_integration(db, current_user)
    integration.stripe_secret_key = None
    integration.stripe_webhook_secret = None
    integration.stripe_connected = False
    await db.flush()

    return IntegrationConnectResponse(
        connected=False, message="Stripe-Verbindung getrennt"
    )
