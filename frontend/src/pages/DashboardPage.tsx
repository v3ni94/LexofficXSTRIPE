import { useEffect } from "react";
import { Link } from "react-router-dom";
import { useDashboardStore } from "../stores/dashboard";
import type { RecentCollection, UpcomingInvoice } from "../stores/dashboard";

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function formatEur(amount: number): string {
  return new Intl.NumberFormat("de-DE", { style: "currency", currency: "EUR" }).format(amount);
}

function formatCents(cents: number, currency: string): string {
  return new Intl.NumberFormat("de-DE", { style: "currency", currency }).format(cents / 100);
}

function formatDate(iso: string | null): string {
  if (!iso) return "-";
  return new Date(iso).toLocaleDateString("de-DE");
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

// ---------------------------------------------------------------------------
// Stat card
// ---------------------------------------------------------------------------

interface StatCardProps {
  title: string;
  count: number;
  amount: number;
  color: "blue" | "amber" | "green" | "red";
}

const colorMap = {
  blue: {
    bg: "bg-blue-50",
    border: "border-blue-200",
    count: "text-blue-700",
    title: "text-blue-600",
    amount: "text-blue-500",
    dot: "bg-blue-500",
  },
  amber: {
    bg: "bg-amber-50",
    border: "border-amber-200",
    count: "text-amber-700",
    title: "text-amber-600",
    amount: "text-amber-500",
    dot: "bg-amber-500",
  },
  green: {
    bg: "bg-green-50",
    border: "border-green-200",
    count: "text-green-700",
    title: "text-green-600",
    amount: "text-green-500",
    dot: "bg-green-500",
  },
  red: {
    bg: "bg-red-50",
    border: "border-red-200",
    count: "text-red-700",
    title: "text-red-600",
    amount: "text-red-500",
    dot: "bg-red-500",
  },
};

function StatCard({ title, count, amount, color }: StatCardProps) {
  const c = colorMap[color];
  return (
    <div className={`rounded-lg border ${c.border} ${c.bg} p-5`}>
      <p className={`text-sm font-medium ${c.title} mb-2`}>{title}</p>
      <p className={`text-3xl font-bold ${c.count}`}>{count}</p>
      <p className={`text-sm mt-1 ${c.amount}`}>{formatEur(amount)}</p>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Status badge for collections
// ---------------------------------------------------------------------------

function CollectionStatusBadge({ status }: { status: string | null }) {
  if (status === "processing") {
    return (
      <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-700">
        <span className="animate-pulse h-1.5 w-1.5 rounded-full bg-amber-500 inline-block" />
        Verarbeitung
      </span>
    );
  }
  if (status === "succeeded") {
    return <span className="inline-flex px-1.5 py-0.5 rounded text-xs font-medium bg-green-100 text-green-700">Erfolgreich</span>;
  }
  if (status === "failed" || status === "disputed") {
    return <span className="inline-flex px-1.5 py-0.5 rounded text-xs font-medium bg-red-100 text-red-700">Fehlgeschlagen</span>;
  }
  return <span className="text-xs text-gray-400">{status ?? "-"}</span>;
}

// ---------------------------------------------------------------------------
// Recent collections mini-list
// ---------------------------------------------------------------------------

function RecentCollectionsList({ items }: { items: RecentCollection[] }) {
  if (items.length === 0) {
    return <p className="text-sm text-gray-400 py-2">Noch keine Einzüge vorhanden.</p>;
  }
  return (
    <ul className="divide-y divide-gray-100">
      {items.map((c) => (
        <li key={c.id} className="py-3 flex items-center justify-between gap-4">
          <div className="min-w-0">
            <p className="text-sm font-medium text-gray-900 truncate">{c.voucher_number}</p>
            <p className="text-xs text-gray-500 truncate">{c.contact_name}</p>
          </div>
          <div className="text-right shrink-0">
            <p className="text-sm font-medium text-gray-900">
              {formatCents(c.amount_cents, c.currency)}
            </p>
            <CollectionStatusBadge status={c.stripe_status} />
          </div>
        </li>
      ))}
    </ul>
  );
}

// ---------------------------------------------------------------------------
// Upcoming invoices mini-list
// ---------------------------------------------------------------------------

function UpcomingInvoicesList({ items }: { items: UpcomingInvoice[] }) {
  if (items.length === 0) {
    return <p className="text-sm text-gray-400 py-2">Keine fälligen Rechnungen.</p>;
  }
  return (
    <ul className="divide-y divide-gray-100">
      {items.map((inv) => {
        const dueDate = inv.due_date ? new Date(inv.due_date) : null;
        const isOverdue = dueDate ? dueDate < new Date() : false;
        return (
          <li key={inv.id} className="py-3 flex items-center justify-between gap-4">
            <div className="min-w-0">
              <p className="text-sm font-medium text-gray-900 truncate">{inv.voucher_number}</p>
              <p className="text-xs text-gray-500 truncate">{inv.contact_name}</p>
            </div>
            <div className="text-right shrink-0">
              <p className="text-sm font-medium text-gray-900">
                {formatEur(inv.total_gross_amount)}
              </p>
              <p className={`text-xs ${isOverdue ? "text-red-600 font-medium" : "text-gray-500"}`}>
                {dueDate ? formatDate(inv.due_date) : "-"}
                {isOverdue && " (überfällig)"}
              </p>
            </div>
          </li>
        );
      })}
    </ul>
  );
}

// ---------------------------------------------------------------------------
// Main page
// ---------------------------------------------------------------------------

export default function DashboardPage() {
  const { stats, recentCollections, upcomingInvoices, isLoading, fetchAll } =
    useDashboardStore();

  useEffect(() => {
    fetchAll();
  }, [fetchAll]);

  if (isLoading && !stats) {
    return <p className="text-gray-500">Laden...</p>;
  }

  const lex = stats?.lexoffice_connected ?? false;
  const stripe = stats?.stripe_connected ?? false;
  const bothConnected = lex && stripe;

  return (
    <div className="space-y-6">
      <h2 className="text-2xl font-bold text-gray-900">Übersicht</h2>

      {/* Not-connected banner */}
      {(!lex || !stripe) && (
        <div className="bg-amber-50 border border-amber-300 rounded-lg p-4 flex items-start gap-3">
          <svg className="w-5 h-5 text-amber-500 shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 24 24">
            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z" />
          </svg>
          <div>
            <p className="text-amber-900 text-sm font-medium">
              {!lex && !stripe
                ? "Lexoffice und Stripe sind nicht verbunden."
                : !lex
                ? "Lexoffice ist nicht verbunden."
                : "Stripe ist nicht verbunden."}
            </p>
            <p className="text-amber-700 text-sm mt-0.5">
              Verbinde deine Dienste unter{" "}
              <Link to="/dashboard/settings" className="underline font-medium hover:text-amber-900">
                Einstellungen
              </Link>
              , um Lastschriften einzureichen.
            </p>
          </div>
        </div>
      )}

      {/* Connection status */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div className="bg-white rounded-lg border border-gray-200 p-5">
          <div className="flex items-center justify-between">
            <h3 className="text-sm font-semibold text-gray-700">Lexoffice</h3>
            <span
              className={`inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium ${
                lex ? "bg-green-100 text-green-700" : "bg-red-100 text-red-700"
              }`}
            >
              <span className={`h-1.5 w-1.5 rounded-full ${lex ? "bg-green-500" : "bg-red-500"}`} />
              {lex ? "Verbunden" : "Nicht verbunden"}
            </span>
          </div>
          {lex && stats?.last_sync && (
            <p className="text-xs text-gray-400 mt-2">
              Letzte Sync: {formatDateTime(stats.last_sync)}
            </p>
          )}
          {!lex && (
            <Link to="/dashboard/settings" className="inline-block mt-2 text-xs text-blue-600 hover:underline">
              Jetzt verbinden →
            </Link>
          )}
        </div>

        <div className="bg-white rounded-lg border border-gray-200 p-5">
          <div className="flex items-center justify-between">
            <h3 className="text-sm font-semibold text-gray-700">Stripe</h3>
            <span
              className={`inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium ${
                stripe ? "bg-green-100 text-green-700" : "bg-red-100 text-red-700"
              }`}
            >
              <span className={`h-1.5 w-1.5 rounded-full ${stripe ? "bg-green-500" : "bg-red-500"}`} />
              {stripe ? "Verbunden" : "Nicht verbunden"}
            </span>
          </div>
          {!stripe && (
            <Link to="/dashboard/settings" className="inline-block mt-2 text-xs text-blue-600 hover:underline">
              Jetzt verbinden →
            </Link>
          )}
        </div>
      </div>

      {/* Stats cards – always show (with zeroes when no data yet) */}
      {stats && (
        <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
          <StatCard
            title="Offene Rechnungen"
            count={stats.open_invoices_count}
            amount={Number(stats.open_invoices_amount)}
            color="blue"
          />
          <StatCard
            title="Im Einzugsverfahren"
            count={stats.in_collection_count}
            amount={Number(stats.in_collection_amount)}
            color="amber"
          />
          <StatCard
            title="Eingezogen (30 Tage)"
            count={stats.collected_last_30_days_count}
            amount={Number(stats.collected_last_30_days_amount)}
            color="green"
          />
          {(stats.failed_count > 0 || true) && (
            <StatCard
              title="Fehlgeschlagen"
              count={stats.failed_count}
              amount={Number(stats.failed_amount)}
              color="red"
            />
          )}
        </div>
      )}

      {/* Bottom panels */}
      {bothConnected && (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          {/* Recent collections */}
          <div className="bg-white rounded-lg border border-gray-200 p-5">
            <div className="flex items-center justify-between mb-3">
              <h3 className="text-sm font-semibold text-gray-900">Letzte Einzüge</h3>
              <Link
                to="/dashboard/collections"
                className="text-xs text-blue-600 hover:underline"
              >
                Alle anzeigen →
              </Link>
            </div>
            <RecentCollectionsList items={recentCollections} />
          </div>

          {/* Upcoming invoices */}
          <div className="bg-white rounded-lg border border-gray-200 p-5">
            <div className="flex items-center justify-between mb-3">
              <h3 className="text-sm font-semibold text-gray-900">Bald fällige Rechnungen</h3>
              <Link
                to="/dashboard/invoices"
                className="text-xs text-blue-600 hover:underline"
              >
                Alle anzeigen →
              </Link>
            </div>
            <UpcomingInvoicesList items={upcomingInvoices} />
          </div>
        </div>
      )}
    </div>
  );
}
