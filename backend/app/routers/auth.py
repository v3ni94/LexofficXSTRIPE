from datetime import datetime, timedelta, timezone

from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.database import get_db
from app.models.invitation import Invitation
from app.models.organization import Organization
from app.models.organization_member import OrganizationMember, OrgRole
from app.models.user import User
from app.schemas.auth import (
    AcceptInviteRequest,
    LoginRequest,
    LogoutResponse,
    RefreshRequest,
    RefreshResponse,
    RegisterRequest,
    TokenResponse,
    UserResponse,
)
from app.services.auth_service import AuthService
from app.utils.security import get_current_user, get_user_context, UserContext, hash_password, create_access_token, create_refresh_token

router = APIRouter(prefix="/auth", tags=["auth"])


@router.post("/register", response_model=TokenResponse, status_code=status.HTTP_201_CREATED)
async def register(data: RegisterRequest, db: AsyncSession = Depends(get_db)):
    existing = await db.execute(select(User).where(User.email == data.email))
    if existing.scalar_one_or_none():
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail="E-Mail ist bereits registriert",
        )

    _user, tokens = await AuthService.register(db, data)
    return tokens


@router.post("/login", response_model=TokenResponse)
async def login(data: LoginRequest, db: AsyncSession = Depends(get_db)):
    user = await AuthService.authenticate(db, data.email, data.password)
    if not user:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Ungueltige Anmeldedaten",
        )
    return await AuthService.create_tokens(user.id, db)


@router.post("/refresh", response_model=RefreshResponse)
async def refresh(data: RefreshRequest, db: AsyncSession = Depends(get_db)):
    result = await AuthService.refresh_access_token(data.refresh_token, db)
    if not result:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Ungueltiger oder abgelaufener Refresh-Token",
        )
    return result


@router.get("/me", response_model=UserResponse)
async def me(
    ctx: UserContext = Depends(get_user_context),
    db: AsyncSession = Depends(get_db),
):
    user = (await db.execute(select(User).where(User.id == ctx.user_id))).scalar_one_or_none()
    if not user:
        raise HTTPException(status_code=404, detail="User not found")

    org = (
        await db.execute(select(Organization).where(Organization.id == ctx.organization_id))
    ).scalar_one_or_none()

    return UserResponse(
        id=user.id,
        email=user.email,
        display_name=user.display_name,
        is_active=user.is_active,
        organization_id=ctx.organization_id,
        organization_name=org.name if org else None,
        role=ctx.role,
    )


@router.post("/logout", response_model=LogoutResponse)
async def logout(current_user: User = Depends(get_current_user)):
    return LogoutResponse()


@router.post("/accept-invite", response_model=TokenResponse)
async def accept_invite(data: AcceptInviteRequest, db: AsyncSession = Depends(get_db)):
    """Accept an organization invitation."""
    invitation = (
        await db.execute(
            select(Invitation).where(
                Invitation.token == data.token,
                Invitation.status == "pending",
            )
        )
    ).scalar_one_or_none()

    if not invitation:
        raise HTTPException(status_code=404, detail="Einladung nicht gefunden oder bereits verwendet")

    if invitation.expires_at < datetime.now(timezone.utc):
        invitation.status = "expired"
        await db.flush()
        raise HTTPException(status_code=410, detail="Einladung ist abgelaufen")

    # Check if user already exists
    existing_user = (
        await db.execute(select(User).where(User.email == invitation.email))
    ).scalar_one_or_none()

    if existing_user:
        # Check user isn't already in an org
        existing_member = (
            await db.execute(
                select(OrganizationMember).where(
                    OrganizationMember.user_id == existing_user.id
                )
            )
        ).scalar_one_or_none()

        if existing_member:
            raise HTTPException(
                status_code=409,
                detail="Nutzer gehoert bereits einer Organisation an",
            )

        user = existing_user
    else:
        # Create new user
        if not data.password:
            raise HTTPException(
                status_code=422,
                detail="Passwort ist erforderlich fuer neue Nutzer",
            )

        user = User(
            email=invitation.email,
            hashed_password=hash_password(data.password),
            display_name=data.display_name,
        )
        db.add(user)
        await db.flush()

    # Create membership
    role = OrgRole(invitation.role.value if hasattr(invitation.role, "value") else invitation.role)
    member = OrganizationMember(
        organization_id=invitation.organization_id,
        user_id=user.id,
        role=role,
    )
    db.add(member)

    # Mark invitation as accepted
    invitation.status = "accepted"

    await db.flush()

    tokens = TokenResponse(
        access_token=create_access_token(
            user.id,
            organization_id=invitation.organization_id,
            role=role.value,
        ),
        refresh_token=create_refresh_token(user.id),
    )
    return tokens
