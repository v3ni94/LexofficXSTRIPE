from jose import JWTError

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.models.integration import Integration
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
        """Create user + empty integration row, return user and tokens."""
        user = User(
            email=data.email,
            hashed_password=hash_password(data.password),
            company_name=data.company_name,
        )
        db.add(user)
        await db.flush()

        integration = Integration(tenant_id=user.id)
        db.add(integration)
        await db.flush()
        await db.refresh(user)

        tokens = TokenResponse(
            access_token=create_access_token(user.id),
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
    def create_tokens(user_id: str) -> TokenResponse:
        return TokenResponse(
            access_token=create_access_token(user_id),
            refresh_token=create_refresh_token(user_id),
        )

    @staticmethod
    def refresh_access_token(refresh_token: str) -> RefreshResponse | None:
        try:
            payload = decode_token(refresh_token)
            if payload.get("type") != "refresh":
                return None
            user_id = payload.get("sub")
            if not user_id:
                return None
            return RefreshResponse(access_token=create_access_token(user_id))
        except JWTError:
            return None
