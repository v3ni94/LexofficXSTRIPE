# Tenant isolation is fully handled by the get_current_user dependency
# in app/utils/security.py, which:
#   1. Extracts tenant_id from the JWT access token
#   2. Loads the User from the database
#   3. Verifies is_active == True
#
# All protected routes use: Depends(get_current_user)
#
# The returned User object serves as the tenant context:
#   - user.id is the tenant_id for all queries
#   - All models with tenant_id FK are scoped to this user
#
# Re-export for convenience:
from app.utils.security import get_current_user

__all__ = ["get_current_user"]
