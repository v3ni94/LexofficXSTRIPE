import { useEffect } from "react";
import { useCollectionsStore } from "../stores/collections";
import type { CollectionListItem } from "../stores/collections";

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function formatAmount(cents: number, currency: string): string {
  return new Intl.NumberFormat("de-DE", {
    style: "currency",
    currency,
  }).format(cents / 100);
}

function formatDateTime(iso: string | null): string {
  if (!iso) return "-";
  return new Date(iso).toLocaleString("de-DE", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

function formatDate(iso: string | null): string {
  if (!iso) return "-";
  return new Date(iso).toLocaleDateString("de-DE");
}

// ---------------------------------------------------------------------------
// Status badge
// ---------------------------------------------------------------------------

function StatusBadge({ item }: { item: CollectionListItem }) {
  const s = item.stripe_status;

  if (s === "processing") {
    return (
      <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">
        <span className="relative flex h-2 w-2">
          <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-500 opacity-75" />
          <span className="relative inline-flex rounded-full h-2 w-2 bg-amber-500" />
        </span>
        Verarbeitung
      </span>
    );
  }

  if (s === "succeeded") {
    return (
      <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
        <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={3} d="M5 13l4 4L19 7" />
        </svg>
        Erfolgreich
      </span>
    );
  }

  if (s === "failed") {
    return (
      <div className="group relative inline-block">
        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
          <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={3} d="M6 18L18 6M6 6l12 12" />
          </svg>
          Fehlgeschlagen
        </span>
        {item.failure_reason && (
          <div className="pointer-events-none absolute bottom-full left-0 mb-1 hidden w-56 rounded bg-gray-900 px-2 py-1 text-xs text-white group-hover:block z-10">
            {item.failure_reason}
          </div>
        )}
      </div>
    );
  }

  if (s === "disputed") {
    return (
      <div className="group relative inline-block">
        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
          <svg className="w-3 h-3" fill="currentColor" viewBox="0 0 24 24">
            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z" />
          </svg>
          Widerspruch
        </span>
        {item.failure_reason && (
          <div className="pointer-events-none absolute bottom-full left-0 mb-1 hidden w-56 rounded bg-gray-900 px-2 py-1 text-xs text-white group-hover:block z-10">
            {item.failure_reason}
          </div>
        )}
      </div>
    );
  }

  return (
    <span className="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
      {s ?? "-"}
    </span>
  );
}

// ---------------------------------------------------------------------------
// CSV export
// ---------------------------------------------------------------------------

function exportCsv(items: CollectionListItem[]) {
  const header = [
    "Datum",
    "Rechnungsnr.",
    "Kunde",
    "Betrag (EUR)",
    "IBAN",
    "Mandatsreferenz",
    "Status",
    "Abgeschlossen",
    "Fehlergrund",
  ];

  const rows = items.map((item) => [
    formatDateTime(item.submitted_at),
    item.voucher_number,
    item.contact_name,
    (item.amount_cents / 100).toFixed(2).replace(".", ","),
    item.iban_masked ?? "",
    item.mandate_reference ?? "",
    item.stripe_status ?? "",
    formatDate(item.completed_at),
    item.failure_reason ?? "",
  ]);

  const csv = [header, ...rows]
    .map((row) => row.map((cell) => `"${String(cell).replace(/"/g, '""')}"`).join(";"))
    .join("\n");

  const blob = new Blob(["\uFEFF" + csv], { type: "text/csv;charset=utf-8;" });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = `einzuege_${new Date().toISOString().slice(0, 10)}.csv`;
  a.click();
  URL.revokeObjectURL(url);
}

// ---------------------------------------------------------------------------
// Filter bar
// ---------------------------------------------------------------------------

const STATUS_OPTIONS = [
  { value: "", label: "Alle Status" },
  { value: "processing", label: "Verarbeitung" },
  { value: "succeeded", label: "Erfolgreich" },
  { value: "failed", label: "Fehlgeschlagen" },
  { value: "disputed", label: "Widerspruch" },
];

function FilterBar() {
  const { filters, setFilters } = useCollectionsStore();

  return (
    <div className="flex flex-wrap gap-3 items-end">
      <div>
        <label className="block text-xs font-medium text-gray-500 mb-1">Status</label>
        <select
          value={filters.status}
          onChange={(e) => setFilters({ status: e.target.value })}
          className="px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white"
        >
          {STATUS_OPTIONS.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </select>
      </div>

      <div>
        <label className="block text-xs font-medium text-gray-500 mb-1">Von</label>
        <input
          type="date"
          value={filters.date_from}
          onChange={(e) => setFilters({ date_from: e.target.value })}
          className="px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
        />
      </div>

      <div>
        <label className="block text-xs font-medium text-gray-500 mb-1">Bis</label>
        <input
          type="date"
          value={filters.date_to}
          onChange={(e) => setFilters({ date_to: e.target.value })}
          className="px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
        />
      </div>

      <button
        onClick={() => setFilters({ status: "", date_from: "", date_to: "", customer_id: "" })}
        className="px-3 py-2 text-sm border border-gray-300 rounded-md text-gray-600 hover:bg-gray-50 transition-colors"
      >
        Filter zurücksetzen
      </button>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Main page
// ---------------------------------------------------------------------------

export default function CollectionsPage() {
  const { data, isLoading, fetchCollections, setPage } = useCollectionsStore();

  useEffect(() => {
    fetchCollections(1);
  }, [fetchCollections]);

  const handleExport = () => {
    if (!data) return;
    exportCsv(data.items);
  };

  return (
    <div className="space-y-4">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <h2 className="text-2xl font-bold text-gray-900">Einzüge</h2>
        <button
          onClick={handleExport}
          disabled={!data || data.items.length === 0}
          className="inline-flex items-center gap-2 px-4 py-2 text-sm border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
        >
          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
          </svg>
          CSV exportieren
        </button>
      </div>

      {/* Filters */}
      <FilterBar />

      {/* Table */}
      {isLoading ? (
        <p className="text-gray-500">Laden...</p>
      ) : !data || data.items.length === 0 ? (
        <div className="bg-white rounded-lg border border-gray-200 p-8 text-center">
          <p className="text-gray-500">Keine Einzüge gefunden.</p>
        </div>
      ) : (
        <>
          <div className="bg-white rounded-lg border border-gray-200 overflow-hidden">
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Datum
                    </th>
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
                      Mandatsreferenz
                    </th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Status
                    </th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Abgeschlossen
                    </th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200">
                  {data.items.map((item) => (
                    <tr key={item.id} className="hover:bg-gray-50">
                      <td className="px-4 py-3 text-sm text-gray-700 whitespace-nowrap">
                        {formatDateTime(item.submitted_at)}
                      </td>
                      <td className="px-4 py-3 text-sm font-medium text-gray-900">
                        {item.voucher_number}
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-700">
                        {item.contact_name}
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-900 text-right font-medium whitespace-nowrap">
                        {formatAmount(item.amount_cents, item.currency)}
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-500 font-mono">
                        {item.mandate_reference ?? "-"}
                      </td>
                      <td className="px-4 py-3">
                        <StatusBadge item={item} />
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-700 whitespace-nowrap">
                        {formatDate(item.completed_at)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          {/* Summary row */}
          <p className="text-sm text-gray-500">
            {data.total} Einzüge insgesamt
          </p>

          {/* Pagination */}
          {data.total_pages > 1 && (
            <div className="flex items-center justify-between">
              <p className="text-sm text-gray-600">
                Seite {data.page} von {data.total_pages}
              </p>
              <div className="flex gap-2">
                <button
                  onClick={() => setPage(data.page - 1)}
                  disabled={data.page <= 1}
                  className="px-3 py-1.5 text-sm border border-gray-300 rounded-md hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                >
                  Zurück
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
