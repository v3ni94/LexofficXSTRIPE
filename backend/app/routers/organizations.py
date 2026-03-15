import uuid
from datetime import datetime, timedelta, timezone

from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel, EmailStr
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.database import get_db
from app.models.invitation import Invitation
from app.models.organization import Organization
from app.models.organization_member import OrganizationMember, OrgRole
from app.models.user import User
from app.utils.security import UserContext, get_user_context, require_role

router = APIRouter(prefix="/organization", tags=["organization"])


# --- Schemas ---


class OrgResponse(BaseModel):
    id: str
    name: str
    onboarding_completed: bool
    onboarding_step: int


class OrgUpdateRequest(BaseModel):
    name: str


class MemberResponse(BaseModel):
    user_id: str
    email: str
    display_name: str | None
    role: str
    joined_at: str | None


class InviteRequest(BaseModel):
    email: EmailStr
    role: str = "member"


class InviteResponse(BaseModel):
    id: str
    email: str
    role: str
    token: str
    invite_url: str
    expires_at: str


class InvitationListItem(BaseModel):
    id: str
    email: str
    role: str
    status: str
    expires_at: str
    created_at: str


class RoleUpdateRequest(BaseModel):
    role: str


# --- Endpoints ---


@router.get("", response_model=OrgResponse)
async def get_organization(
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

    return OrgResponse(
        id=org.id,
        name=org.name,
        onboarding_completed=org.onboarding_completed,
        onboarding_step=org.onboarding_step,
    )


@router.put("", response_model=OrgResponse)
async def update_organization(
    body: OrgUpdateRequest,
    ctx: UserContext = Depends(require_role("owner", "admin")),
    db: AsyncSession = Depends(get_db),
):
    org = (
        await db.execute(
            select(Organization).where(Organization.id == ctx.organization_id)
        )
    ).scalar_one_or_none()

    if not org:
        raise HTTPException(status_code=404, detail="Organisation nicht gefunden")

    org.name = body.name
    await db.flush()
    await db.refresh(org)

    return OrgResponse(
        id=org.id,
        name=org.name,
        onboarding_completed=org.onboarding_completed,
        onboarding_step=org.onboarding_step,
    )


@router.get("/members", response_model=list[MemberResponse])
async def list_members(
    ctx: UserContext = Depends(get_user_context),
    db: AsyncSession = Depends(get_db),
):
    members = (
        await db.execute(
            select(OrganizationMember).where(
                OrganizationMember.organization_id == ctx.organization_id
            )
        )
    ).scalars().all()

    result = []
    user_ids = [m.user_id for m in members]
    users = {}
    if user_ids:
        rows = (await db.execute(select(User).where(User.id.in_(user_ids)))).scalars().all()
        users = {u.id: u for u in rows}

    for m in members:
        u = users.get(m.user_id)
        result.append(MemberResponse(
            user_id=m.user_id,
            email=u.email if u else "unknown",
            display_name=u.display_name if u else None,
            role=m.role.value if hasattr(m.role, "value") else m.role,
            joined_at=m.created_at.isoformat() if m.created_at else None,
        ))

    return result


@router.post("/invite", response_model=InviteResponse)
async def invite_member(
    body: InviteRequest,
    ctx: UserContext = Depends(require_role("owner", "admin")),
    db: AsyncSession = Depends(get_db),
):
    if body.role not in ("admin", "member"):
        raise HTTPException(status_code=422, detail="Rolle muss 'admin' oder 'member' sein")

    # Check if email is already a member of any org
    existing_user = (
        await db.execute(select(User).where(User.email == body.email))
    ).scalar_one_or_none()

    if existing_user:
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

    # Check for existing pending invitation
    existing_invite = (
        await db.execute(
            select(Invitation).where(
                Invitation.organization_id == ctx.organization_id,
                Invitation.email == body.email,
                Invitation.status == "pending",
            )
        )
    ).scalar_one_or_none()

    if existing_invite:
        raise HTTPException(
            status_code=409,
            detail="Es existiert bereits eine offene Einladung fuer diese E-Mail",
        )

    token = uuid.uuid4().hex
    expires_at = datetime.now(timezone.utc) + timedelta(hours=72)

    invitation = Invitation(
        organization_id=ctx.organization_id,
        email=body.email,
        role=OrgRole(body.role),
        token=token,
        invited_by_user_id=ctx.user_id,
        status="pending",
        expires_at=expires_at,
    )
    db.add(invitation)
    await db.flush()
    await db.refresh(invitation)

    # Build invite URL (frontend handles this route)
    invite_url = f"/invite/{token}"

    return InviteResponse(
        id=invitation.id,
        email=body.email,
        role=body.role,
        token=token,
        invite_url=invite_url,
        expires_at=expires_at.isoformat(),
    )


@router.get("/invitations", response_model=list[InvitationListItem])
async def list_invitations(
    ctx: UserContext = Depends(require_role("owner", "admin")),
    db: AsyncSession = Depends(get_db),
):
    invitations = (
        await db.execute(
            select(Invitation).where(
                Invitation.organization_id == ctx.organization_id,
            ).order_by(Invitation.created_at.desc())
        )
    ).scalars().all()

    return [
        InvitationListItem(
            id=inv.id,
            email=inv.email,
            role=inv.role.value if hasattr(inv.role, "value") else inv.role,
            status=inv.status,
            expires_at=inv.expires_at.isoformat() if inv.expires_at else "",
            created_at=inv.created_at.isoformat() if inv.created_at else "",
        )
        for inv in invitations
    ]


@router.delete("/invitations/{invitation_id}")
async def revoke_invitation(
    invitation_id: str,
    ctx: UserContext = Depends(require_role("owner", "admin")),
    db: AsyncSession = Depends(get_db),
):
    invitation = (
        await db.execute(
            select(Invitation).where(
                Invitation.id == invitation_id,
                Invitation.organization_id == ctx.organization_id,
                Invitation.status == "pending",
            )
        )
    ).scalar_one_or_none()

    if not invitation:
        raise HTTPException(status_code=404, detail="Einladung nicht gefunden")

    await db.delete(invitation)
    await db.flush()
    return {"detail": "Einladung widerrufen"}


@router.put("/members/{user_id}/role", response_model=MemberResponse)
async def update_member_role(
    user_id: str,
    body: RoleUpdateRequest,
    ctx: UserContext = Depends(require_role("owner")),
    db: AsyncSession = Depends(get_db),
):
    if body.role not in ("owner", "admin", "member"):
        raise HTTPException(status_code=422, detail="Ungueltige Rolle")

    member = (
        await db.execute(
            select(OrganizationMember).where(
                OrganizationMember.organization_id == ctx.organization_id,
                OrganizationMember.user_id == user_id,
            )
        )
    ).scalar_one_or_none()

    if not member:
        raise HTTPException(status_code=404, detail="Mitglied nicht gefunden")

    current_role = member.role.value if hasattr(member.role, "value") else member.role

    # Transfer ownership
    if body.role == "owner" and current_role != "owner":
        # Demote current owner to admin
        current_owner = (
            await db.execute(
                select(OrganizationMember).where(
                    OrganizationMember.organization_id == ctx.organization_id,
                    OrganizationMember.user_id == ctx.user_id,
                )
            )
        ).scalar_one_or_none()

        if current_owner:
            current_owner.role = OrgRole.ADMIN

    member.role = OrgRole(body.role)
    await db.flush()

    user = (await db.execute(select(User).where(User.id == user_id))).scalar_one_or_none()

    return MemberResponse(
        user_id=user_id,
        email=user.email if user else "unknown",
        display_name=user.display_name if user else None,
        role=body.role,
        joined_at=member.created_at.isoformat() if member.created_at else None,
    )


@router.delete("/members/{user_id}")
async def remove_member(
    user_id: str,
    ctx: UserContext = Depends(require_role("owner", "admin")),
    db: AsyncSession = Depends(get_db),
):
    if user_id == ctx.user_id:
        raise HTTPException(status_code=400, detail="Du kannst dich nicht selbst entfernen")

    member = (
        await db.execute(
            select(OrganizationMember).where(
                OrganizationMember.organization_id == ctx.organization_id,
                OrganizationMember.user_id == user_id,
            )
        )
    ).scalar_one_or_none()

    if not member:
        raise HTTPException(status_code=404, detail="Mitglied nicht gefunden")

    member_role = member.role.value if hasattr(member.role, "value") else member.role
    if member_role == "owner":
        raise HTTPException(status_code=403, detail="Der Owner kann nicht entfernt werden")

    # Admin cannot remove another admin
    if ctx.role == "admin" and member_role == "admin":
        raise HTTPException(status_code=403, detail="Admins koennen andere Admins nicht entfernen")

    await db.delete(member)
    await db.flush()
    return {"detail": "Mitglied entfernt"}
