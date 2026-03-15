import { useEffect, useState } from "react";
import { Navigate, Outlet } from "react-router-dom";
import { api } from "../api/client";

export default function OnboardingGuard() {
  const [status, setStatus] = useState<{
    completed: boolean;
    loading: boolean;
  }>({ completed: true, loading: true });

  useEffect(() => {
    api
      .get("/onboarding/status")
      .then((res) => {
        setStatus({ completed: res.data.completed, loading: false });
      })
      .catch(() => {
        // If onboarding endpoint fails, assume completed (graceful degradation)
        setStatus({ completed: true, loading: false });
      });
  }, []);

  if (status.loading) {
    return (
      <div className="flex items-center justify-center min-h-screen">
        <div className="text-gray-500">Laden...</div>
      </div>
    );
  }

  if (!status.completed) {
    return <Navigate to="/onboarding" replace />;
  }

  return <Outlet />;
}
