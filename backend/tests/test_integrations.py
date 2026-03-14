"""Tests for /integrations/* endpoints."""
from unittest.mock import AsyncMock, MagicMock, patch

import pytest
from httpx import AsyncClient
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.models.integration import Integration
from app.models.user import User


def _make_httpx_mock(status_code: int, json_data: dict | None = None):
    """Build a patch-ready mock for ``httpx.AsyncClient``."""
    mock_resp = MagicMock()
    mock_resp.status_code = status_code
    mock_resp.json.return_value = json_data or {}

    mock_client = AsyncMock()
    mock_client.get = AsyncMock(return_value=mock_resp)

    mock_cm = AsyncMock()
    mock_cm.__aenter__ = AsyncMock(return_value=mock_client)
    mock_cm.__aexit__ = AsyncMock(return_value=None)

    return mock_cm


# ---------------------------------------------------------------------------
# GET /integrations
# ---------------------------------------------------------------------------


async def test_get_integration_status(client: AsyncClient, test_user: User, auth_headers):
    resp = await client.get("/integrations", headers=auth_headers(test_user.id))
    assert resp.status_code == 200
    data = resp.json()
    assert "lexoffice_connected" in data
    assert "stripe_connected" in data
    # Keys must NEVER appear in the response
    assert "lexoffice_api_key" not in data
    assert "stripe_secret_key" not in data
    assert "stripe_webhook_secret" not in data
    assert "lexoffice_api_key_encrypted" not in data


# ---------------------------------------------------------------------------
# PUT /integrations/lexoffice
# ---------------------------------------------------------------------------


async def test_connect_lexoffice_success(
    client: AsyncClient,
    test_user: User,
    db: AsyncSession,
    auth_headers,
):
    mock_cm = _make_httpx_mock(200, {"organisationId": "org-123"})

    with patch("httpx.AsyncClient", return_value=mock_cm):
        resp = await client.put(
            "/integrations/lexoffice",
            json={"api_key": "valid-lex-key"},
            headers=auth_headers(test_user.id),
        )

    assert resp.status_code == 200
    assert resp.json()["connected"] is True

    # Verify encryption: DB value must not equal plaintext
    result = await db.execute(select(Integration).where(Integration.tenant_id == test_user.id))
    integ = result.scalar_one()
    assert integ.lexoffice_connected is True
    assert integ.lexoffice_api_key_encrypted is not None
    assert integ.lexoffice_api_key_encrypted != "valid-lex-key"
    # But decrypt must yield the original value
    assert integ.lexoffice_api_key == "valid-lex-key"


async def test_connect_lexoffice_invalid_key(
    client: AsyncClient,
    test_user: User,
    auth_headers,
):
    mock_cm = _make_httpx_mock(401)

    with patch("httpx.AsyncClient", return_value=mock_cm):
        resp = await client.put(
            "/integrations/lexoffice",
            json={"api_key": "invalid-key"},
            headers=auth_headers(test_user.id),
        )

    assert resp.status_code == 400


async def test_connect_lexoffice_requires_auth(client: AsyncClient):
    resp = await client.put("/integrations/lexoffice", json={"api_key": "key"})
    assert resp.status_code == 401


# ---------------------------------------------------------------------------
# PUT /integrations/stripe
# ---------------------------------------------------------------------------


async def test_connect_stripe_success(
    client: AsyncClient,
    test_user: User,
    db: AsyncSession,
    auth_headers,
):
    mock_cm = _make_httpx_mock(
        200,
        {"id": "acct_123", "capabilities": {"sepa_debit_payments": "active"}},
    )

    with patch("httpx.AsyncClient", return_value=mock_cm):
        resp = await client.put(
            "/integrations/stripe",
            json={"secret_key": "sk_test_abc", "webhook_secret": "whsec_xyz"},
            headers=auth_headers(test_user.id),
        )

    assert resp.status_code == 200
    assert resp.json()["connected"] is True

    result = await db.execute(select(Integration).where(Integration.tenant_id == test_user.id))
    integ = result.scalar_one()
    assert integ.stripe_connected is True
    # Keys must be stored encrypted
    assert integ.stripe_secret_key_encrypted is not None
    assert integ.stripe_secret_key_encrypted != "sk_test_abc"
    assert integ.stripe_secret_key == "sk_test_abc"
    # Webhook secret also encrypted
    assert integ.stripe_webhook_secret_encrypted != "whsec_xyz"
    assert integ.stripe_webhook_secret == "whsec_xyz"


async def test_connect_stripe_invalid_key(
    client: AsyncClient,
    test_user: User,
    auth_headers,
):
    mock_cm = _make_httpx_mock(401)

    with patch("httpx.AsyncClient", return_value=mock_cm):
        resp = await client.put(
            "/integrations/stripe",
            json={"secret_key": "sk_bad", "webhook_secret": "whsec_bad"},
            headers=auth_headers(test_user.id),
        )

    assert resp.status_code == 400


# ---------------------------------------------------------------------------
# DELETE /integrations/lexoffice
# ---------------------------------------------------------------------------


async def test_disconnect_lexoffice(
    client: AsyncClient,
    test_user: User,
    test_integration: Integration,
    db: AsyncSession,
    auth_headers,
):
    resp = await client.delete(
        "/integrations/lexoffice",
        headers=auth_headers(test_user.id),
    )
    assert resp.status_code == 200
    assert resp.json()["connected"] is False

    result = await db.execute(select(Integration).where(Integration.tenant_id == test_user.id))
    integ = result.scalar_one()
    assert integ.lexoffice_connected is False
    assert integ.lexoffice_api_key_encrypted is None


# ---------------------------------------------------------------------------
# Keys are never returned by GET /integrations
# ---------------------------------------------------------------------------


async def test_keys_not_returned_when_connected(
    client: AsyncClient,
    test_user: User,
    test_integration: Integration,
    auth_headers,
):
    """Even with active integrations the response must not leak plaintext keys."""
    resp = await client.get("/integrations", headers=auth_headers(test_user.id))
    assert resp.status_code == 200
    body = resp.text
    # Plaintext secrets must not appear anywhere in the response body
    assert "lex-key-abc123" not in body
    assert "sk_test_abc123" not in body
    assert "whsec_test_secret_xyz" not in body
