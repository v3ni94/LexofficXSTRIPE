import { useEffect, useState } from "react";
import { useInvoicesStore } from "../stores/invoices";
import type { InvoiceListItem } from "../stores/invoices";

function StatusBadge({ status }: { status: string }) {
  const styles: Record<string, string> = {
    open: "bg-green-100 text-green-800",
    in_collection: "bg-amber-100 text-amber-800",
    failed: "bg-red-100 text-red-800",
  };
  const labels: Record<string, string> = {
    open: "Offen",
    in_collection: "Im Einzug",
    failed: "Fehlgeschlagen",
  };

  return (
    <span
      className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${
        styles[status] ?? "bg-gray-100 text-gray-600"
      }`}
    >
      {labels[status] ?? status}
    </span>
  );
}

function ActionButton({ invoice }: { invoice: InvoiceListItem }) {
  if (invoice.collection_status === "in_collection") {
    return null;
  }
  if (
    invoice.collection_status === "open" ||
    invoice.collection_status === "failed"
  ) {
    if (invoice.customer_has_iban) {
      return (
        <button className="text-xs px-3 py-1 bg-blue-600 text-white rounded hover:bg-blue-700 transition-colors whitespace-nowrap">
          Lastschrift einreichen
        </button>
      );
    }
    return (
      <button className="text-xs px-3 py-1 border border-gray-300 text-gray-700 rounded hover:bg-gray-50 transition-colors whitespace-nowrap">
        IBAN hinterlegen
      </button>
    );
  }
  return null;
}

function formatAmount(amount: number, currency: string): string {
  return new Intl.NumberFormat("de-DE", {
    style: "currency",
    currency,
  }).format(amount);
}

function formatDate(dateStr: string | null): string {
  if (!dateStr) return "-";
  return new Date(dateStr).toLocaleDateString("de-DE");
}

export default function InvoicesPage() {
  const {
    data,
    isLoading,
    isSyncing,
    search,
    fetchInvoices,
    syncInvoices,
    setSearch,
    setPage,
  } = useInvoicesStore();

  const [syncMessage, setSyncMessage] = useState<string | null>(null);
  const [syncError, setSyncError] = useState<string | null>(null);
  const [searchInput, setSearchInput] = useState(search);

  useEffect(() => {
    fetchInvoices();
  }, [fetchInvoices]);

  const handleSync = async () => {
    setSyncMessage(null);
    setSyncError(null);
    try {
      const result = await syncInvoices();
      setSyncMessage(
        `Synchronisiert: ${result.synced_count} Rechnungen (${result.new_count} neu, ${result.updated_count} aktualisiert)`
      );
    } catch (err: unknown) {
      const detail =
        typeof err === "object" &&
        err !== null &&
        "response" in err &&
        typeof (err as Record<string, unknown>).response === "object"
          ? ((err as { response: { data?: { detail?: string } } }).response
              .data?.detail ?? "Sync fehlgeschlagen")
          : "Verbindungsfehler";
      setSyncError(detail);
    }
  };

  const handleSearchSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setSearch(searchInput);
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <h2 className="text-2xl font-bold text-gray-900">Rechnungen</h2>
        <button
          onClick={handleSync}
          disabled={isSyncing}
          className="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white text-sm rounded-md hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
        >
          {isSyncing && (
            <svg
              className="animate-spin h-4 w-4"
              fill="none"
              viewBox="0 0 24 24"
            >
              <circle
                className="opacity-25"
                cx="12"
                cy="12"
                r="10"
                stroke="currentColor"
                strokeWidth="4"
              />
              <path
                className="opacity-75"
                fill="currentColor"
                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
              />
            </svg>
          )}
          {isSyncing ? "Synchronisiere..." : "Rechnungen synchronisieren"}
        </button>
      </div>

      {/* Sync result messages */}
      {syncMessage && (
        <div className="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded text-sm">
          {syncMessage}
        </div>
      )}
      {syncError && (
        <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded text-sm">
          {syncError}
        </div>
      )}

      {/* Search */}
      <form onSubmit={handleSearchSubmit} className="flex gap-2 max-w-md">
        <input
          type="text"
          value={searchInput}
          onChange={(e) => setSearchInput(e.target.value)}
          placeholder="Rechnungsnr. oder Kundenname..."
          className="flex-1 px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
        />
        <button
          type="submit"
          className="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md hover:bg-gray-200 transition-colors"
        >
          Suchen
        </button>
      </form>

      {/* Table */}
      {isLoading ? (
        <p className="text-gray-500">Laden...</p>
      ) : !data || data.items.length === 0 ? (
        <div className="bg-white rounded-lg border border-gray-200 p-8 text-center">
          <p className="text-gray-500">
            {search
              ? "Keine Rechnungen gefunden."
              : "Keine offenen Rechnungen. Synchronisiere zuerst mit Lexoffice."}
          </p>
        </div>
      ) : (
        <>
          <div className="bg-white rounded-lg border border-gray-200 overflow-hidden">
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Rechnungsnr.
                    </th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Kunde
                    </th>
                    <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Betrag
                    </th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Faellig am
                    </th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Status
                    </th>
                    <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Aktion
                    </th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200">
                  {data.items.map((inv) => (
                    <tr key={inv.id} className="hover:bg-gray-50">
                      <td className="px-4 py-3 text-sm font-medium text-gray-900">
                        {inv.voucher_number}
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-700">
                        {inv.contact_name}
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-900 text-right font-medium">
                        {formatAmount(inv.total_gross_amount, inv.currency)}
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-700">
                        {formatDate(inv.due_date)}
                      </td>
                      <td className="px-4 py-3">
                        <StatusBadge status={inv.collection_status} />
                      </td>
                      <td className="px-4 py-3 text-right">
                        <ActionButton invoice={inv} />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          {/* Pagination */}
          {data.total_pages > 1 && (
            <div className="flex items-center justify-between">
              <p className="text-sm text-gray-600">
                Seite {data.page} von {data.total_pages} ({data.total}{" "}
                Rechnungen)
              </p>
              <div className="flex gap-2">
                <button
                  onClick={() => setPage(data.page - 1)}
                  disabled={data.page <= 1}
                  className="px-3 py-1.5 text-sm border border-gray-300 rounded-md hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                >
                  Zurueck
                </button>
                <button
                  onClick={() => setPage(data.page + 1)}
                  disabled={data.page >= data.total_pages}
                  className="px-3 py-1.5 text-sm border border-gray-300 rounded-md hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                >
                  Weiter
                </button>
              </div>
            </div>
          )}
        </>
      )}
    </div>
  );
}
