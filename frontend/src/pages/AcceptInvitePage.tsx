import { useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { api } from "../api/client";
import { useAuthStore } from "../stores/auth";

export default function AcceptInvitePage() {
  const { token } = useParams<{ token: string }>();
  const navigate = useNavigate();
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated);

  const [displayName, setDisplayName] = useState("");
  const [password, setPassword] = useState("");
  const [passwordConfirm, setPasswordConfirm] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleAccept = async () => {
    setLoading(true);
    setError(null);

    if (!isAuthenticated && password !== passwordConfirm) {
      setError("Passwoerter stimmen nicht ueberein");
      setLoading(false);
      return;
    }

    try {
      const body: Record<string, string> = { token: token ?? "" };
      if (!isAuthenticated) {
        body.password = password;
        body.display_name = displayName;
      }
      const res = await api.post("/auth/accept-invite", body);

      // Store tokens and redirect
      useAuthStore.setState({
        accessToken: res.data.access_token,
        refreshToken: res.data.refresh_token,
        isAuthenticated: true,
      });
      await useAuthStore.getState().fetchUser();

      navigate("/dashboard", { replace: true });
    } catch (err: unknown) {
      const detail =
        typeof err === "object" &&
        err !== null &&
        "response" in err
          ? ((err as { response: { data?: { detail?: string } } }).response
              .data?.detail ?? "Fehler")
          : "Verbindungsfehler";
      setError(detail);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen bg-gray-50 flex items-center justify-center p-6">
      <div className="w-full max-w-md bg-white rounded-xl shadow-lg p-8">
        <h2 className="text-xl font-bold text-gray-900 mb-4">
          Einladung annehmen
        </h2>

        {error && (
          <div className="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-2 rounded text-sm">
            {error}
          </div>
        )}

        {isAuthenticated ? (
          <div className="space-y-4">
            <p className="text-sm text-gray-600">
              Du bist bereits eingeloggt. Klicke unten, um die Einladung
              anzunehmen.
            </p>
            <button
              onClick={handleAccept}
              disabled={loading}
              className="w-full px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50"
            >
              {loading ? "..." : "Einladung annehmen"}
            </button>
          </div>
        ) : (
          <div className="space-y-4">
            <p className="text-sm text-gray-600">
              Erstelle ein Konto, um der Organisation beizutreten.
            </p>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                Dein Name
              </label>
              <input
                type="text"
                value={displayName}
                onChange={(e) => setDisplayName(e.target.value)}
                placeholder="Max Mustermann"
                className="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                Passwort
              </label>
              <input
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                placeholder="Mindestens 8 Zeichen"
                className="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                Passwort bestaetigen
              </label>
              <input
                type="password"
                value={passwordConfirm}
                onChange={(e) => setPasswordConfirm(e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
            <button
              onClick={handleAccept}
              disabled={loading || !password || !passwordConfirm}
              className="w-full px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50"
            >
              {loading ? "..." : "Konto erstellen und beitreten"}
            </button>
          </div>
        )}
      </div>
    </div>
  );
}
