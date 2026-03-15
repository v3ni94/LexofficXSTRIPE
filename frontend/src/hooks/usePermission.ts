import { useAuthStore } from "../stores/auth";

export function usePermission() {
  const user = useAuthStore((s) => s.user);
  const role = user?.role ?? "member";

  return {
    role,
    canManageIntegrations: role === "owner" || role === "admin",
    canManageTeam: role === "owner" || role === "admin",
    canChangeRoles: role === "owner",
    canDeleteOrg: role === "owner",
  };
}
