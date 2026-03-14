"""
Periodic background task: sync Lexoffice invoices for all tenants
every SYNC_INTERVAL_SECONDS (default 900 = 15 minutes).

Runs inside the FastAPI lifespan using asyncio.create_task().
"""

import asyncio
import logging

from sqlalchemy import select

from app.database import async_session
from app.models.integration import Integration
from app.models.user import User
from app.services.lexoffice_service import LexofficeService
from app.services.sync_service import SyncService

logger = logging.getLogger(__name__)

SYNC_INTERVAL_SECONDS = 900  # 15 minutes


def _run_sync_in_thread(tenant_id: str, api_key: str):
    """
    Run async sync_invoices inside a brand-new event loop on a worker thread.
    Opens its own DB session so it doesn't share state with the main loop.
    """
    import asyncio as _asyncio

    async def _inner():
        async with async_session() as db:
            lex = LexofficeService(api_key)
            with lex:
                result = await SyncService.sync_invoices(tenant_id, lex, db)
            await db.commit()
            return result

    loop = _asyncio.new_event_loop()
    try:
        return loop.run_until_complete(_inner())
    finally:
        loop.close()


async def _sync_all_tenants() -> None:
    """Fetch all Lexoffice-connected tenants and synchronise each one."""
    async with async_session() as db:
        stmt = (
            select(Integration, User)
            .join(User, User.id == Integration.tenant_id)
            .where(
                Integration.lexoffice_connected.is_(True),
                Integration.lexoffice_api_key_encrypted.is_not(None),
                User.is_active.is_(True),
            )
        )
        rows = (await db.execute(stmt)).all()

    tenants: list[tuple[str, str, str]] = []
    for integration, user in rows:
        api_key = integration.lexoffice_api_key  # decrypt
        if api_key:
            tenants.append((user.id, user.company_name, api_key))

    if not tenants:
        logger.debug("Auto-Sync: keine verbundenen Tenants gefunden")
        return

    logger.info("Auto-Sync: starte für %d Tenant(s)", len(tenants))

    for tenant_id, company_name, api_key in tenants:
        try:
            result = await asyncio.to_thread(
                _run_sync_in_thread, tenant_id, api_key
            )
            logger.info(
                "Auto-Sync Tenant '%s': %d neu, %d aktualisiert, %d entfernt",
                company_name,
                result.new_count,
                result.updated_count,
                result.removed_count,
            )
        except Exception as exc:
            logger.error(
                "Auto-Sync fehlgeschlagen für Tenant '%s': %s",
                company_name,
                exc,
            )


async def background_sync_loop() -> None:
    """Infinite loop: wait 30 s after startup, then sync every 15 minutes."""
    logger.info(
        "Hintergrund-Sync gestartet (Intervall: %ds)", SYNC_INTERVAL_SECONDS
    )
    # Short initial delay so the DB is fully ready
    await asyncio.sleep(30)

    while True:
        try:
            await _sync_all_tenants()
        except Exception as exc:
            logger.exception("Unerwarteter Fehler im Sync-Loop: %s", exc)

        await asyncio.sleep(SYNC_INTERVAL_SECONDS)
