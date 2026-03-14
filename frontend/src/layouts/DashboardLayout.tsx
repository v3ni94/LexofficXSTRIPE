import { useEffect, useState } from "react";
import { Link, NavLink, Outlet, useNavigate } from "react-router-dom";
import { useAuthStore } from "../stores/auth";

const navItems = [
  { to: "/dashboard", label: "Uebersicht", end: true },
  { to: "/dashboard/invoices", label: "Rechnungen", end: false },
  { to: "/dashboard/customers", label: "Kunden & Stammdaten", end: false },
  { to: "/dashboard/collections", label: "Einzuege", end: false },
  { to: "/dashboard/settings", label: "Einstellungen", end: false },
];

export default function DashboardLayout() {
  const user = useAuthStore((s) => s.user);
  const isLoading = useAuthStore((s) => s.isLoading);
  const logout = useAuthStore((s) => s.logout);
  const fetchUser = useAuthStore((s) => s.fetchUser);
  const navigate = useNavigate();
  const [sidebarOpen, setSidebarOpen] = useState(false);

  useEffect(() => {
    if (!user && !isLoading) {
      fetchUser();
    }
  }, [user, isLoading, fetchUser]);

  const handleLogout = () => {
    logout();
    navigate("/login", { replace: true });
  };

  if (isLoading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50">
        <p className="text-gray-500">Laden...</p>
      </div>
    );
  }

  const linkClass = ({ isActive }: { isActive: boolean }) =>
    `block px-4 py-2.5 rounded-lg text-sm font-medium transition-colors ${
      isActive
        ? "bg-blue-50 text-blue-700"
        : "text-gray-700 hover:bg-gray-100"
    }`;

  return (
    <div className="min-h-screen bg-gray-50 flex">
      {/* Mobile overlay */}
      {sidebarOpen && (
        <div
          className="fixed inset-0 bg-black/30 z-30 lg:hidden"
          onClick={() => setSidebarOpen(false)}
        />
      )}

      {/* Sidebar */}
      <aside
        className={`fixed inset-y-0 left-0 z-40 w-64 bg-white border-r border-gray-200 flex flex-col transform transition-transform duration-200 lg:translate-x-0 lg:static lg:z-auto ${
          sidebarOpen ? "translate-x-0" : "-translate-x-full"
        }`}
      >
        {/* Logo */}
        <div className="h-16 flex items-center px-6 border-b border-gray-200 shrink-0">
          <Link
            to="/dashboard"
            className="text-xl font-bold text-gray-900"
            onClick={() => setSidebarOpen(false)}
          >
            LexSEPA
          </Link>
        </div>

        {/* Navigation */}
        <nav className="flex-1 px-3 py-4 space-y-1 overflow-y-auto">
          {navItems.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              end={item.end}
              className={linkClass}
              onClick={() => setSidebarOpen(false)}
            >
              {item.label}
            </NavLink>
          ))}
        </nav>

        {/* User info */}
        <div className="border-t border-gray-200 p-4 shrink-0">
          {user && (
            <div className="mb-3">
              <p className="text-sm font-medium text-gray-900 truncate">
                {user.company_name}
              </p>
              <p className="text-xs text-gray-500 truncate">{user.email}</p>
            </div>
          )}
          <button
            onClick={handleLogout}
            className="w-full text-left text-sm text-gray-600 hover:text-gray-900 transition-colors"
          >
            Abmelden
          </button>
        </div>
      </aside>

      {/* Main content */}
      <div className="flex-1 flex flex-col min-w-0">
        {/* Top bar (mobile) */}
        <header className="h-16 bg-white border-b border-gray-200 flex items-center px-4 lg:hidden shrink-0">
          <button
            onClick={() => setSidebarOpen(true)}
            className="p-2 rounded-md text-gray-600 hover:bg-gray-100"
            aria-label="Menue oeffnen"
          >
            <svg
              className="w-6 h-6"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth={2}
                d="M4 6h16M4 12h16M4 18h16"
              />
            </svg>
          </button>
          <span className="ml-3 text-lg font-bold text-gray-900">LexSEPA</span>
        </header>

        {/* Page content */}
        <main className="flex-1 p-6 overflow-y-auto">
          {user ? <Outlet /> : <p className="text-gray-500">Laden...</p>}
        </main>
      </div>
    </div>
  );
}
