import { useEffect } from "react";
import { Link } from "react-router-dom";
import { useIntegrationsStore } from "../stores/integrations";

export default function DashboardPage() {
  const { status, isLoading, fetchStatus } = useIntegrationsStore();

  useEffect(() => {
    fetchStatus();
  }, [fetchStatus]);

  if (isLoading || !status) {
    return <p className="text-gray-500">Laden...</p>;
  }

  const bothConnected = status.lexoffice_connected && status.stripe_connected;

  return (
    <div className="space-y-6">
      <h2 className="text-2xl font-bold text-gray-900">Uebersicht</h2>

      {/* Not connected banner */}
      {!bothConnected && (
        <div className="bg-amber-50 border border-amber-200 rounded-lg p-4">
          <p className="text-amber-800 text-sm">
            Bitte verbinde zuerst Lexoffice und Stripe unter{" "}
            <Link
              to="/dashboard/settings"
              className="font-medium underline hover:text-amber-900"
            >
              Einstellungen
            </Link>
            .
          </p>
        </div>
      )}

      {/* Connection status cards */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div className="bg-white rounded-lg border border-gray-200 p-6">
          <div className="flex items-center justify-between mb-3">
            <h3 className="text-lg font-semibold text-gray-900">Lexoffice</h3>
            <span
              className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
                status.lexoffice_connected
                  ? "bg-green-100 text-green-800"
                  : "bg-gray-100 text-gray-600"
              }`}
            >
              {status.lexoffice_connected ? "Verbunden" : "Nicht verbunden"}
            </span>
          </div>
          {status.lexoffice_connected && status.lexoffice_last_sync && (
            <p className="text-sm text-gray-500">
              Letzte Synchronisierung:{" "}
              {new Date(status.lexoffice_last_sync).toLocaleString("de-DE")}
            </p>
          )}
          {!status.lexoffice_connected && (
            <Link
              to="/dashboard/settings"
              className="inline-block mt-2 text-sm text-blue-600 hover:underline"
            >
              Jetzt verbinden
            </Link>
          )}
        </div>

        <div className="bg-white rounded-lg border border-gray-200 p-6">
          <div className="flex items-center justify-between mb-3">
            <h3 className="text-lg font-semibold text-gray-900">Stripe</h3>
            <span
              className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
                status.stripe_connected
                  ? "bg-green-100 text-green-800"
                  : "bg-gray-100 text-gray-600"
              }`}
            >
              {status.stripe_connected ? "Verbunden" : "Nicht verbunden"}
            </span>
          </div>
          {!status.stripe_connected && (
            <Link
              to="/dashboard/settings"
              className="inline-block mt-2 text-sm text-blue-600 hover:underline"
            >
              Jetzt verbinden
            </Link>
          )}
        </div>
      </div>

      {/* Summary cards (only when both connected) */}
      {bothConnected && (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div className="bg-white rounded-lg border border-gray-200 p-6">
            <p className="text-sm text-gray-500 mb-1">Offene Rechnungen</p>
            <p className="text-2xl font-bold text-gray-900">--</p>
            <p className="text-sm text-gray-400">-- EUR</p>
          </div>
          <div className="bg-white rounded-lg border border-gray-200 p-6">
            <p className="text-sm text-gray-500 mb-1">Im Einzugsverfahren</p>
            <p className="text-2xl font-bold text-gray-900">--</p>
            <p className="text-sm text-gray-400">-- EUR</p>
          </div>
          <div className="bg-white rounded-lg border border-gray-200 p-6">
            <p className="text-sm text-gray-500 mb-1">
              Letzte Synchronisierung
            </p>
            <p className="text-lg font-semibold text-gray-900">
              {status.lexoffice_last_sync
                ? new Date(status.lexoffice_last_sync).toLocaleString("de-DE")
                : "Noch nie"}
            </p>
          </div>
        </div>
      )}
    </div>
  );
}
