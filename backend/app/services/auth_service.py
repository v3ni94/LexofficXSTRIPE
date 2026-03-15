from jose import JWTError

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.models.integration import Integration
from app.models.organization import Organization
from app.models.organization_member import OrganizationMember, OrgRole
from app.models.user import User
from app.schemas.auth import RegisterRequest, RefreshResponse, TokenResponse
from app.utils.security import (
    create_access_token,
    create_refresh_token,
    decode_token,
    hash_password,
    verify_password,
)


class AuthService:
    @staticmethod
    async def register(db: AsyncSession, data: RegisterRequest) -> tuple[User, TokenResponse]:
        """Create user + organization + membership + integration, return user and tokens."""
        user = User(
            email=data.email,
            hashed_password=hash_password(data.password),
            display_name=data.company_name,  # Use company_name as initial display_name
        )
        db.add(user)
        await db.flush()

        # Create organization
        org = Organization(name=data.company_name)
        db.add(org)
        await db.flush()

        # Create membership (owner)
        member = OrganizationMember(
            organization_id=org.id,
            user_id=user.id,
            role=OrgRole.OWNER,
        )
        db.add(member)
        await db.flush()

        # Create empty integration for the organization
        integration = Integration(tenant_id=org.id)
        db.add(integration)
        await db.flush()
        await db.refresh(user)

        tokens = TokenResponse(
            access_token=create_access_token(
                user.id,
                organization_id=org.id,
                role=OrgRole.OWNER.value,
            ),
            refresh_token=create_refresh_token(user.id),
        )
        return user, tokens

    @staticmethod
    async def authenticate(
        db: AsyncSession, email: str, password: str
    ) -> User | None:
        result = await db.execute(select(User).where(User.email == email))
        user = result.scalar_one_or_none()
        if not user or not verify_password(password, user.hashed_password):
            return None
        if not user.is_active:
            return None
        return user

    @staticmethod
    async def create_tokens(user_id: str, db: AsyncSession) -> TokenResponse:
        """Create tokens with org context resolved from DB."""
        from app.models.organization_member import OrganizationMember

        member = (
            await db.execute(
                select(OrganizationMember).where(
                    OrganizationMember.user_id == user_id
                ).limit(1)
            )
        ).scalar_one_or_none()

        org_id = member.organization_id if member else None
        role = (member.role.value if hasattr(member.role, "value") else member.role) if member else None

        return TokenResponse(
            access_token=create_access_token(user_id, organization_id=org_id, role=role),
            refresh_token=create_refresh_token(user_id),
        )

    @staticmethod
    async def refresh_access_token(refresh_token: str, db: AsyncSession) -> RefreshResponse | None:
        try:
            payload = decode_token(refresh_token)
            if payload.get("type") != "refresh":
                return None
            user_id = payload.get("sub")
            if not user_id:
                return None

            # Resolve org context
            from app.models.organization_member import OrganizationMember
            member = (
                await db.execute(
                    select(OrganizationMember).where(
                        OrganizationMember.user_id == user_id
                    ).limit(1)
                )
            ).scalar_one_or_none()

            org_id = member.organization_id if member else None
            role = (member.role.value if hasattr(member.role, "value") else member.role) if member else None

            return RefreshResponse(
                access_token=create_access_token(user_id, organization_id=org_id, role=role)
            )
        except JWTError:
            return None
