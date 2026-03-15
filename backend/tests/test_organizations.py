"""Tests for organization management endpoints."""
import pytest
from httpx import AsyncClient
from sqlalchemy.ext.asyncio import AsyncSession

from app.models.organization import Organization
from app.models.organization_member import OrganizationMember, OrgRole
from app.models.user import User
from app.utils.security import create_access_token, hash_password


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------


def _make_headers(user_id: str, org_id: str, role: str = "owner") -> dict:
    token = create_access_token(user_id, organization_id=org_id, role=role)
    return {"Authorization": f"Bearer {token}"}


# ---------------------------------------------------------------------------
# GET /organization
# ---------------------------------------------------------------------------


async def test_get_organization(
    client: AsyncClient,
    test_user: User,
    test_org: Organization,
):
    headers = _make_headers(test_user.id, test_org.id)
    resp = await client.get("/organization", headers=headers)
    assert resp.status_code == 200
    data = resp.json()
    assert data["name"] == "Test GmbH"
    assert data["id"] == test_org.id


# ---------------------------------------------------------------------------
# PUT /organization
# ---------------------------------------------------------------------------


async def test_update_organization(
    client: AsyncClient,
    test_user: User,
    test_org: Organization,
):
    headers = _make_headers(test_user.id, test_org.id, "owner")
    resp = await client.put(
        "/organization",
        json={"name": "Neue GmbH"},
        headers=headers,
    )
    assert resp.status_code == 200
    assert resp.json()["name"] == "Neue GmbH"


async def test_update_organization_member_forbidden(
    client: AsyncClient,
    db: AsyncSession,
    test_org: Organization,
):
    """Regular member cannot update organization name."""
    member_user = User(
        email="member@example.com",
        hashed_password=hash_password("SecurePass123"),
        display_name="Member",
    )
    db.add(member_user)
    await db.flush()
    m = OrganizationMember(
        organization_id=test_org.id, user_id=member_user.id, role=OrgRole.MEMBER
    )
    db.add(m)
    await db.flush()

    headers = _make_headers(member_user.id, test_org.id, "member")
    resp = await client.put(
        "/organization",
        json={"name": "Hacker GmbH"},
        headers=headers,
    )
    assert resp.status_code == 403


# ---------------------------------------------------------------------------
# GET /organization/members
# ---------------------------------------------------------------------------


async def test_list_members(
    client: AsyncClient,
    test_user: User,
    test_org: Organization,
):
    headers = _make_headers(test_user.id, test_org.id)
    resp = await client.get("/organization/members", headers=headers)
    assert resp.status_code == 200
    data = resp.json()
    assert len(data) >= 1
    assert data[0]["email"] == "tenant@example.com"
    assert data[0]["role"] == "owner"


# ---------------------------------------------------------------------------
# POST /organization/invite
# ---------------------------------------------------------------------------


async def test_invite_member(
    client: AsyncClient,
    test_user: User,
    test_org: Organization,
):
    headers = _make_headers(test_user.id, test_org.id, "owner")
    resp = await client.post(
        "/organization/invite",
        json={"email": "newmember@example.com", "role": "member"},
        headers=headers,
    )
    assert resp.status_code == 200
    data = resp.json()
    assert data["email"] == "newmember@example.com"
    assert data["role"] == "member"
    assert data["token"]
    assert data["invite_url"].startswith("/invite/")


async def test_invite_duplicate_pending(
    client: AsyncClient,
    test_user: User,
    test_org: Organization,
):
    headers = _make_headers(test_user.id, test_org.id, "owner")
    # First invite
    resp1 = await client.post(
        "/organization/invite",
        json={"email": "dup@example.com", "role": "member"},
        headers=headers,
    )
    assert resp1.status_code == 200

    # Second invite for same email
    resp2 = await client.post(
        "/organization/invite",
        json={"email": "dup@example.com", "role": "admin"},
        headers=headers,
    )
    assert resp2.status_code == 409


async def test_invite_member_by_regular_member_forbidden(
    client: AsyncClient,
    db: AsyncSession,
    test_org: Organization,
):
    member_user = User(
        email="regmem@example.com",
        hashed_password=hash_password("SecurePass123"),
    )
    db.add(member_user)
    await db.flush()
    db.add(OrganizationMember(
        organization_id=test_org.id, user_id=member_user.id, role=OrgRole.MEMBER
    ))
    await db.flush()

    headers = _make_headers(member_user.id, test_org.id, "member")
    resp = await client.post(
        "/organization/invite",
        json={"email": "blocked@example.com", "role": "member"},
        headers=headers,
    )
    assert resp.status_code == 403


# ---------------------------------------------------------------------------
# DELETE /organization/members/{user_id}
# ---------------------------------------------------------------------------


async def test_remove_member(
    client: AsyncClient,
    db: AsyncSession,
    test_user: User,
    test_org: Organization,
):
    target = User(
        email="toremove@example.com",
        hashed_password=hash_password("SecurePass123"),
    )
    db.add(target)
    await db.flush()
    db.add(OrganizationMember(
        organization_id=test_org.id, user_id=target.id, role=OrgRole.MEMBER
    ))
    await db.flush()

    headers = _make_headers(test_user.id, test_org.id, "owner")
    resp = await client.delete(
        f"/organization/members/{target.id}", headers=headers
    )
    assert resp.status_code == 200


async def test_cannot_remove_self(
    client: AsyncClient,
    test_user: User,
    test_org: Organization,
):
    headers = _make_headers(test_user.id, test_org.id, "owner")
    resp = await client.delete(
        f"/organization/members/{test_user.id}", headers=headers
    )
    assert resp.status_code == 400


async def test_cannot_remove_owner(
    client: AsyncClient,
    db: AsyncSession,
    test_user: User,
    test_org: Organization,
):
    """Admin cannot remove the owner."""
    admin_user = User(
        email="admin@example.com",
        hashed_password=hash_password("SecurePass123"),
    )
    db.add(admin_user)
    await db.flush()
    db.add(OrganizationMember(
        organization_id=test_org.id, user_id=admin_user.id, role=OrgRole.ADMIN
    ))
    await db.flush()

    headers = _make_headers(admin_user.id, test_org.id, "admin")
    resp = await client.delete(
        f"/organization/members/{test_user.id}", headers=headers
    )
    assert resp.status_code == 403


# ---------------------------------------------------------------------------
# PUT /organization/members/{user_id}/role (ownership transfer)
# ---------------------------------------------------------------------------


async def test_transfer_ownership(
    client: AsyncClient,
    db: AsyncSession,
    test_user: User,
    test_org: Organization,
):
    admin_user = User(
        email="newowner@example.com",
        hashed_password=hash_password("SecurePass123"),
    )
    db.add(admin_user)
    await db.flush()
    db.add(OrganizationMember(
        organization_id=test_org.id, user_id=admin_user.id, role=OrgRole.ADMIN
    ))
    await db.flush()

    headers = _make_headers(test_user.id, test_org.id, "owner")
    resp = await client.put(
        f"/organization/members/{admin_user.id}/role",
        json={"role": "owner"},
        headers=headers,
    )
    assert resp.status_code == 200
    assert resp.json()["role"] == "owner"


async def test_role_change_member_forbidden(
    client: AsyncClient,
    db: AsyncSession,
    test_user: User,
    test_org: Organization,
):
    """Only owner can change roles."""
    admin_user = User(
        email="admin2@example.com",
        hashed_password=hash_password("SecurePass123"),
    )
    db.add(admin_user)
    await db.flush()
    db.add(OrganizationMember(
        organization_id=test_org.id, user_id=admin_user.id, role=OrgRole.ADMIN
    ))
    await db.flush()

    headers = _make_headers(admin_user.id, test_org.id, "admin")
    resp = await client.put(
        f"/organization/members/{test_user.id}/role",
        json={"role": "admin"},
        headers=headers,
    )
    assert resp.status_code == 403
