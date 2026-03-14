import { Navigate, Route, Routes } from "react-router-dom";
import ProtectedRoute from "./components/ProtectedRoute";
import DashboardLayout from "./layouts/DashboardLayout";
import DashboardPage from "./pages/DashboardPage";
import LoginPage from "./pages/Login";
import RegisterPage from "./pages/Register";
import SettingsPage from "./pages/SettingsPage";

function PlaceholderPage({ title }: { title: string }) {
  return (
    <div>
      <h2 className="text-2xl font-bold text-gray-900 mb-4">{title}</h2>
      <p className="text-gray-500">Diese Seite wird bald implementiert.</p>
    </div>
  );
}

function App() {
  return (
    <Routes>
      {/* Public routes */}
      <Route path="/login" element={<LoginPage />} />
      <Route path="/register" element={<RegisterPage />} />

      {/* Protected routes */}
      <Route element={<ProtectedRoute />}>
        <Route path="/dashboard" element={<DashboardLayout />}>
          <Route index element={<DashboardPage />} />
          <Route
            path="invoices"
            element={<PlaceholderPage title="Rechnungen" />}
          />
          <Route
            path="customers"
            element={<PlaceholderPage title="Kunden & Stammdaten" />}
          />
          <Route
            path="collections"
            element={<PlaceholderPage title="Einzuege" />}
          />
          <Route path="settings" element={<SettingsPage />} />
        </Route>
      </Route>

      {/* Catch-all redirect */}
      <Route path="*" element={<Navigate to="/login" replace />} />
    </Routes>
  );
}

export default App;
