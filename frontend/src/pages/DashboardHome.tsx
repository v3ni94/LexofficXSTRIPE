import { useAuthStore } from "../stores/auth";

export default function DashboardHome() {
  const user = useAuthStore((s) => s.user);

  return (
    <div>
      <h2 className="text-2xl font-bold text-gray-900 mb-4">Dashboard</h2>
      <p className="text-gray-600">
        Willkommen, {user?.company_name ?? user?.email}!
      </p>
    </div>
  );
}
