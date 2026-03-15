"""Tests for /auth/* endpoints."""
import pytest
from httpx import AsyncClient

from app.models.user import User
from app.utils.security import create_refresh_token


# ---------------------------------------------------------------------------
# Registration
# ---------------------------------------------------------------------------


async def test_register_success(client: AsyncClient):
    resp = await client.post(
        "/auth/register",
        json={
            "email": "new@example.com",
            "password": "StrongPass1",
            "password_confirm": "StrongPass1",
            "company_name": "Neu GmbH",
        },
    )
    assert resp.status_code == 201
    data = resp.json()
    assert "access_token" in data
    assert "refresh_token" in data
    assert data["token_type"] == "bearer"


async def test_register_duplicate_email(client: AsyncClient, test_user: User):
    resp = await client.post(
        "/auth/register",
        json={
            "email": test_user.email,
            "password": "StrongPass1",
            "password_confirm": "StrongPass1",
            "company_name": "Dupe GmbH",
        },
    )
    assert resp.status_code == 409


async def test_register_password_too_short(client: AsyncClient):
    resp = await client.post(
        "/auth/register",
        json={
            "email": "short@example.com",
            "password": "abc",
            "password_confirm": "abc",
            "company_name": "Short GmbH",
        },
    )
    assert resp.status_code == 422


async def test_register_password_mismatch(client: AsyncClient):
    resp = await client.post(
        "/auth/register",
        json={
            "email": "mismatch@example.com",
            "password": "StrongPass1",
            "password_confirm": "DifferentPass1",
            "company_name": "Mismatch GmbH",
        },
    )
    assert resp.status_code == 422


async def test_register_invalid_email(client: AsyncClient):
    resp = await client.post(
        "/auth/register",
        json={
            "email": "not-an-email",
            "password": "StrongPass1",
            "password_confirm": "StrongPass1",
            "company_name": "Invalid GmbH",
        },
    )
    assert resp.status_code == 422


# ---------------------------------------------------------------------------
# Login
# ---------------------------------------------------------------------------


async def test_login_success(client: AsyncClient, test_user: User):
    resp = await client.post(
        "/auth/login",
        json={"email": test_user.email, "password": "SecurePass123"},
    )
    assert resp.status_code == 200
    data = resp.json()
    assert "access_token" in data
    assert "refresh_token" in data


async def test_login_wrong_password(client: AsyncClient, test_user: User):
    resp = await client.post(
        "/auth/login",
        json={"email": test_user.email, "password": "WrongPassword"},
    )
    assert resp.status_code == 401


async def test_login_nonexistent_email(client: AsyncClient):
    resp = await client.post(
        "/auth/login",
        json={"email": "nobody@example.com", "password": "SomePass123"},
    )
    assert resp.status_code == 401


# ---------------------------------------------------------------------------
# Token refresh
# ---------------------------------------------------------------------------


async def test_token_refresh_success(client: AsyncClient, test_user: User):
    refresh = create_refresh_token(test_user.id)
    resp = await client.post("/auth/refresh", json={"refresh_token": refresh})
    assert resp.status_code == 200
    data = resp.json()
    assert "access_token" in data


async def test_token_refresh_invalid_token(client: AsyncClient):
    resp = await client.post("/auth/refresh", json={"refresh_token": "not.a.valid.token"})
    assert resp.status_code == 401


async def test_token_refresh_using_access_token_fails(client: AsyncClient, test_user: User):
    """Access tokens must not be usable as refresh tokens."""
    from app.utils.security import create_access_token

    access = create_access_token(test_user.id)
    resp = await client.post("/auth/refresh", json={"refresh_token": access})
    assert resp.status_code == 401


# ---------------------------------------------------------------------------
# Protected routes
# ---------------------------------------------------------------------------


async def test_me_without_token_returns_401(client: AsyncClient):
    resp = await client.get("/auth/me")
    assert resp.status_code == 401


async def test_me_with_valid_token(client: AsyncClient, test_user: User, auth_headers):
    resp = await client.get("/auth/me", headers=auth_headers(test_user.id))
    assert resp.status_code == 200
    data = resp.json()
    assert data["email"] == test_user.email
    assert data["display_name"] == test_user.display_name
    assert "hashed_password" not in data


async def test_me_with_invalid_token(client: AsyncClient):
    resp = await client.get("/auth/me", headers={"Authorization": "Bearer garbage"})
    assert resp.status_code == 401
