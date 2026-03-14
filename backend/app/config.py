from pydantic_settings import BaseSettings


class Settings(BaseSettings):
    # Database
    DATABASE_URL: str = "mysql+aiomysql://lexsepa:password@db:3306/lexsepa"

    # JWT
    JWT_SECRET_KEY: str = "change-me-to-random-64-chars"
    JWT_ALGORITHM: str = "HS256"
    JWT_ACCESS_TOKEN_EXPIRE_MINUTES: int = 30
    JWT_REFRESH_TOKEN_EXPIRE_DAYS: int = 7

    # Encryption
    ENCRYPTION_KEY: str = "change-me-to-fernet-key"

    # Lexoffice
    LEXOFFICE_API_URL: str = "https://api.lexoffice.io/v1"

    # Stripe
    STRIPE_WEBHOOK_SECRET: str = ""

    # Logging
    LOG_LEVEL: str = "INFO"

    # CORS
    CORS_ORIGINS: list[str] = ["http://localhost:5173"]

    model_config = {"env_file": ".env", "extra": "ignore"}


settings = Settings()
