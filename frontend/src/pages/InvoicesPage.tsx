import { useEffect, useRef, useState } from "react";
import {
  CooldownSyncButton,
  LastSyncLabel,
  SyncToast,
  useCooldown,
} from "../components/SyncControls";
import { useInvoicesStore } from "../stores/invoices";
import type { InvoiceListItem, SyncResult } from "../stores/invoices";

import { api } from "../api/client";

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

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

function maskIban(iban: string): string {
  const clean = iban.replace(/\s/g, "");
  if (clean.length < 8) return iban;
  const first = clean.slice(0, 4);
  const last = clean.slice(-4);
  const masked = "*".repeat(clean.length - 8);
  const full = first + masked + last;
  return full.replace(/(.{4})/g, "$1 ").trim();
}

// ---------------------------------------------------------------------------
// Keyword badge
// ---------------------------------------------------------------------------

const KEYWORD_COLORS: Record<string, string> = {
  Vermietung: "bg-blue-100 text-blue-800",
  Verkauf: "bg-green-100 text-green-800",
  Verwaltung: "bg-purple-100 text-purple-800",
  "Mieterhöhung": "bg-orange-100 text-orange-800",
  Nebenkostenabrechnung: "bg-yellow-100 text-yellow-800",
  Kaution: "bg-gray-100 text-gray-700",
  Provision: "bg-teal-100 text-teal-800",
  Instandhaltung: "bg-red-100 text-red-800",
  Sonstiges: "bg-gray-100 text-gray-600",
};

function KeywordBadge({ keyword }: { keyword: string | null }) {
  if (!keyword) return <span className="text-xs text-gray-400">-</span>;

  // Handle combined keywords like "Verkauf/Verwaltung"
  const parts = keyword.split("/");
  if (parts.length > 1) {
    return (
      <div className="flex gap-1 flex-wrap">
        {parts.map((p, i) => (
          <span
            key={i}
            className={`inline-flex px-1.5 py-0.5 rounded text-[10px] font-medium ${
              KEYWORD_COLORS[p.trim()] ?? "bg-gray-100 text-gray-600"
            }`}
          >
            {p.trim()}
          </span>
        ))}
      </div>
    );
  }

  return (
    <span
      className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium ${
        KEYWORD_COLORS[keyword] ?? "bg-gray-100 text-gray-600"
      }`}
    >
      {keyword}
    </span>
  );
}

// ---------------------------------------------------------------------------
// StatusBadge
// ---------------------------------------------------------------------------

function StatusBadge({
  status,
  failureReason,
}: {
  status: string;
  failureReason?: string | null;
}) {
  const styles: Record<string, string> = {
    open: "bg-blue-100 text-blue-800",
    scheduled: "bg-indigo-100 text-indigo-800",
    in_collection: "bg-amber-100 text-amber-800",
    collected: "bg-green-100 text-green-800",
    failed: "bg-red-100 text-red-800",
  };
  const labels: Record<string, string> = {
    open: "Offen",
    scheduled: "Terminiert",
    in_collection: "Im Einzugsverfahren",
    collected: "Eingezogen",
    failed: "Fehlgeschlagen",
  };

  const badge = (
    <span
      className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${
        styles[status] ?? "bg-gray-100 text-gray-600"
      }`}
    >
      {labels[status] ?? status}
    </span>
  );

  if (status === "failed" && failureReason) {
    return (
      <div className="group relative inline-block">
        {badge}
        <div className="pointer-events-none absolute bottom-full left-0 mb-1 hidden w-56 rounded bg-gray-900 px-2 py-1 text-xs text-white group-hover:block z-10">
          {failureReason}
        </div>
      </div>
    );
  }

  return badge;
}

// ---------------------------------------------------------------------------
// Confirmation Dialog
// ---------------------------------------------------------------------------

interface ConfirmDialogProps {
  invoice: InvoiceListItem;
  ibanMasked: string;
  mandateRef: string;
  onConfirm: (scheduledDate?: string) => void;
  onCancel: () => void;
  isSubmitting: boolean;
}

function getMinDate(): string {
  const d = new Date();
  d.setDate(d.getDate() + 1);
  // Skip to Monday if tomorrow is weekend
  while (d.getDay() === 0 || d.getDay() === 6) {
    d.setDate(d.getDate() + 1);
  }
  return d.toISOString().split("T")[0];
}

function isWeekday(dateStr: string): boolean {
  const d = new Date(dateStr);
  return d.getDay() !== 0 && d.getDay() !== 6;
}

function ConfirmDialog({
  invoice,
  ibanMasked,
  mandateRef,
  onConfirm,
  onCancel,
  isSubmitting,
}: ConfirmDialogProps) {
  const [preview, setPreview] = useState<{ description: string } | null>(null);
  const [mode, setMode] = useState<"immediate" | "scheduled">("immediate");
  const [scheduledDate, setScheduledDate] = useState(getMinDate());

  useEffect(() => {
    api
      .get("/collections/preview", { params: { invoice_id: invoice.id } })
      .then((res) => setPreview(res.data))
      .catch(() => {});
  }, [invoice.id]);

  const handleConfirm = () => {
    if (mode === "scheduled") {
      onConfirm(scheduledDate);
    } else {
      onConfirm();
    }
  };

  const dateValid = mode === "immediate" || (scheduledDate && isWeekday(scheduledDate));

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
      <div className="bg-white rounded-lg shadow-xl w-full max-w-md mx-4 p-6">
        <h3 className="text-lg font-semibold text-gray-900 mb-4">
          SEPA-Lastschrift einreichen
        </h3>
        <div className="bg-gray-50 rounded-md p-4 space-y-2 text-sm mb-4">
          <div className="flex justify-between">
            <span className="text-gray-500">Rechnung:</span>
            <span className="font-medium">{invoice.voucher_number}</span>
          </div>
          <div className="flex justify-between">
            <span className="text-gray-500">Kunde:</span>
            <span className="font-medium">{invoice.contact_name}</span>
          </div>
          <div className="flex justify-between">
            <span className="text-gray-500">Betrag:</span>
            <span className="font-medium">
              {formatAmount(invoice.total_gross_amount, invoice.currency)}
            </span>
          </div>
          <div className="flex justify-between">
            <span className="text-gray-500">IBAN:</span>
            <span className="font-medium font-mono">{ibanMasked}</span>
          </div>
          <div className="flex justify-between">
            <span className="text-gray-500">Mandatsreferenz:</span>
            <span className="font-medium">{mandateRef}</span>
          </div>
          {preview && (
            <div className="border-t border-gray-200 pt-2 mt-2">
              <div className="flex justify-between items-start">
                <span className="text-gray-500">Verwendungszweck:</span>
                <span className="font-medium font-mono text-right text-xs max-w-[200px]">
                  {preview.description}
                </span>
              </div>
              <p className="text-[10px] text-gray-400 mt-1 text-right">
                Dieser Text erscheint auf dem Kontoauszug des Kunden.
              </p>
            </div>
          )}
        </div>

        {/* Scheduling options */}
        <div className="mb-4 space-y-2">
          <label className="flex items-center gap-2 text-sm cursor-pointer">
            <input
              type="radio"
              name="schedule"
              checked={mode === "immediate"}
              onChange={() => setMode("immediate")}
              className="text-blue-600"
            />
            Sofort einziehen (naechstmoeglicher Termin)
          </label>
          <label className="flex items-center gap-2 text-sm cursor-pointer">
            <input
              type="radio"
              name="schedule"
              checked={mode === "scheduled"}
              onChange={() => setMode("scheduled")}
              className="text-blue-600"
            />
            Einzug terminieren auf:
          </label>
          {mode === "scheduled" && (
            <div className="ml-6">
              <input
                type="date"
                value={scheduledDate}
                min={getMinDate()}
                onChange={(e) => setScheduledDate(e.target.value)}
                className="px-3 py-1.5 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
              {scheduledDate && !isWeekday(scheduledDate) && (
                <p className="text-xs text-red-600 mt-1">
                  SEPA-Einzuege nur an Werktagen (Mo-Fr) moeglich.
                </p>
              )}
              {scheduledDate && isWeekday(scheduledDate) && (
                <p className="text-xs text-gray-500 mt-1">
                  Einzug wird am {new Date(scheduledDate).toLocaleDateString("de-DE")} ausgefuehrt.
                </p>
              )}
            </div>
          )}
        </div>

        <div className="flex gap-3 justify-end">
          <button
            onClick={onCancel}
            disabled={isSubmitting}
            className="px-4 py-2 text-sm border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 disabled:opacity-50"
          >
            Abbrechen
          </button>
          <button
            onClick={handleConfirm}
            disabled={isSubmitting || !dateValid}
            className="px-4 py-2 text-sm bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50 flex items-center gap-2"
          >
            {isSubmitting && (
              <svg className="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
              </svg>
            )}
            {mode === "scheduled"
              ? `Lastschrift terminieren zum ${new Date(scheduledDate).toLocaleDateString("de-DE")}`
              : "Lastschrift jetzt einreichen"}
          </button>
        </div>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// IBAN Modal (for customers without IBAN)
// ---------------------------------------------------------------------------

interface IbanModalProps {
  invoice: InvoiceListItem;
  onSaveAndSubmit: (iban: string, accountHolder: string) => void;
  onCancel: () => void;
  isSaving: boolean;
  error: string | null;
}

function IbanModal({
  invoice,
  onSaveAndSubmit,
  onCancel,
  isSaving,
  error,
}: IbanModalProps) {
  const [iban, setIban] = useState("");
  const [accountHolder, setAccountHolder] = useState("");

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    onSaveAndSubmit(iban.trim().replace(/\s/g, ""), accountHolder.trim());
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
      <div className="bg-white rounded-lg shadow-xl w-full max-w-md mx-4 p-6">
        <h3 className="text-lg font-semibold text-gray-900 mb-2">
          IBAN hinterlegen
        </h3>
        <p className="text-sm text-gray-600 mb-1">
          Für diesen Kunden ist noch keine IBAN hinterlegt.
        </p>
        <p className="text-sm text-gray-500 mb-4">
          Rechnung: <strong>{invoice.voucher_number}</strong> –{" "}
          {formatAmount(invoice.total_gross_amount, invoice.currency)}
        </p>

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              IBAN
            </label>
            <input
              type="text"
              value={iban}
              onChange={(e) => setIban(e.target.value)}
              placeholder="DE89 3704 0044 0532 0130 00"
              required
              className="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              Kontoinhaber
            </label>
            <input
              type="text"
              value={accountHolder}
              onChange={(e) => setAccountHolder(e.target.value)}
              placeholder="Max Mustermann"
              required
              className="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>

          {error && (
            <p className="text-sm text-red-600 bg-red-50 px-3 py-2 rounded">
              {error}
            </p>
          )}

          <div className="flex gap-3 justify-end pt-2">
            <button
              type="button"
              onClick={onCancel}
              disabled={isSaving}
              className="px-4 py-2 text-sm border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 disabled:opacity-50"
            >
              Abbrechen
            </button>
            <button
              type="submit"
              disabled={isSaving}
              className="px-4 py-2 text-sm bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50 flex items-center gap-2"
            >
              {isSaving && (
                <svg className="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
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
              IBAN speichern und Lastschrift einreichen
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Main page
// ---------------------------------------------------------------------------

type DialogState =
  | { type: "none" }
  | {
      type: "confirm";
      invoice: InvoiceListItem;
      ibanMasked: string;
      mandateRef: string;
    }
  | { type: "iban"; invoice: InvoiceListItem };

export default function InvoicesPage() {
  const {
    data,
    isLoading,
    isSyncing,
    search,
    submittingIds,
    lastSyncAt,
    fetchInvoices,
    syncInvoices,
    setSearch,
    setKeyword,
    setPage,
    submitCollection,
    submitBatch,
    saveIban,
    startPolling,
    stopPolling,
    isCoolingDown,
    cooldownSecondsLeft,
  } = useInvoicesStore();

  const { coolingDown, secondsLeft } = useCooldown(isCoolingDown, cooldownSecondsLeft);

  const [syncError, setSyncError] = useState<string | null>(null);
  const [toastResult, setToastResult] = useState<SyncResult | null>(null);
  const [searchInput, setSearchInput] = useState(search);
  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set());
  const [dialog, setDialog] = useState<DialogState>({ type: "none" });
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);
  const [ibanSaveError, setIbanSaveError] = useState<string | null>(null);
  const [keywords, setKeywords] = useState<{ keyword: string; count: number }[]>([]);
  const [keywordFilter, setKeywordFilter] = useState("");

  // Track which invoice IDs are fading out (just transitioned to "collected")
  const [fadingIds, setFadingIds] = useState<Set<string>>(new Set());
  const prevStatusRef = useRef<Record<string, string>>({});

  useEffect(() => {
    fetchInvoices();
    startPolling();
    api.get("/invoices/keywords").then((res) => setKeywords(res.data)).catch(() => {});
    return () => stopPolling();
  }, [fetchInvoices, startPolling, stopPolling]);

  // Detect status changes to "collected" and trigger fade-out
  useEffect(() => {
    if (!data) return;
    const prev = prevStatusRef.current;
    const toFade: string[] = [];
    for (const inv of data.items) {
      if (
        inv.collection_status === "collected" &&
        prev[inv.id] === "in_collection"
      ) {
        toFade.push(inv.id);
      }
    }
    if (toFade.length > 0) {
      setFadingIds((f) => {
        const next = new Set(f);
        toFade.forEach((id) => next.add(id));
        return next;
      });
      // Remove from fading after animation completes (600ms)
      setTimeout(() => {
        setFadingIds((f) => {
          const next = new Set(f);
          toFade.forEach((id) => next.delete(id));
          return next;
        });
      }, 600);
    }
    // Update prev map
    const newPrev: Record<string, string> = {};
    for (const inv of data.items) {
      newPrev[inv.id] = inv.collection_status;
    }
    prevStatusRef.current = newPrev;
  }, [data]);

  const handleSync = async () => {
    setSyncError(null);
    try {
      const result = await syncInvoices();
      setToastResult(result);
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

  const handleActionClick = (invoice: InvoiceListItem) => {
    if (invoice.customer_has_iban) {
      // Show confirm dialog with placeholder IBAN/mandate
      setDialog({
        type: "confirm",
        invoice,
        ibanMasked: "DE** **** **** **** **** **",
        mandateRef: "HVM...",
      });
    } else {
      setDialog({ type: "iban", invoice });
    }
  };

  const handleConfirmSubmit = async (scheduledDate?: string) => {
    if (dialog.type !== "confirm") return;
    setIsSubmitting(true);
    setSubmitError(null);
    try {
      await submitCollection(dialog.invoice.id, scheduledDate);
      setDialog({ type: "none" });
    } catch (err: unknown) {
      const detail =
        typeof err === "object" &&
        err !== null &&
        "response" in err
          ? ((err as { response: { data?: { detail?: string } } }).response
              .data?.detail ?? "Fehler beim Einreichen")
          : "Verbindungsfehler";
      setSubmitError(detail);
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleIbanSaveAndSubmit = async (
    iban: string,
    accountHolder: string
  ) => {
    if (dialog.type !== "iban") return;
    const invoice = dialog.invoice;
    if (!invoice.customer_id) return;

    setIsSubmitting(true);
    setIbanSaveError(null);
    try {
      await saveIban(invoice.customer_id, iban, accountHolder);
      await submitCollection(invoice.id);
      setDialog({ type: "none" });
    } catch (err: unknown) {
      const detail =
        typeof err === "object" &&
        err !== null &&
        "response" in err
          ? ((err as { response: { data?: { detail?: string } } }).response
              .data?.detail ?? "Fehler")
          : "Verbindungsfehler";
      setIbanSaveError(detail);
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleBatchSubmit = async () => {
    if (selectedIds.size === 0) return;
    try {
      const result = await submitBatch([...selectedIds]);
      setSelectedIds(new Set());
      if (result.failed.length > 0) {
        setSyncError(
          `${result.successful.length} erfolgreich, ${result.failed.length} fehlgeschlagen`
        );
      } else {
        setSyncError(null);
      }
    } catch {
      setSyncError("Batch-Einzug fehlgeschlagen");
    }
  };

  const toggleSelect = (id: string) => {
    setSelectedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const selectableInvoices =
    data?.items.filter(
      (inv) =>
        inv.collection_status === "open" || inv.collection_status === "failed"
    ) ?? [];

  const allSelected =
    selectableInvoices.length > 0 &&
    selectableInvoices.every((inv) => selectedIds.has(inv.id));

  const toggleSelectAll = () => {
    if (allSelected) {
      setSelectedIds(new Set());
    } else {
      setSelectedIds(new Set(selectableInvoices.map((inv) => inv.id)));
    }
  };

  return (
    <div className="space-y-4">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
          <h2 className="text-2xl font-bold text-gray-900">Rechnungen</h2>
          <LastSyncLabel lastSyncAt={lastSyncAt} />
        </div>
        <div className="flex items-center gap-2">
          {selectedIds.size > 0 && (
            <button
              onClick={handleBatchSubmit}
              className="inline-flex items-center gap-2 px-4 py-2 bg-green-600 text-white text-sm rounded-md hover:bg-green-700 transition-colors"
            >
              {selectedIds.size} Ausgewählte einziehen
            </button>
          )}
          <CooldownSyncButton
            isSyncing={isSyncing}
            isCoolingDown={coolingDown}
            cooldownSecondsLeft={secondsLeft}
            onClick={handleSync}
          />
        </div>
      </div>

      {/* Messages */}
      {syncError && (
        <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded text-sm">
          {syncError}
        </div>
      )}
      {submitError && (
        <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded text-sm">
          {submitError}
        </div>
      )}

      {/* Search + Keyword filter */}
      <div className="flex flex-wrap gap-3 items-end">
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
        {keywords.length > 0 && (
          <div>
            <label className="block text-xs font-medium text-gray-500 mb-1">Kategorie</label>
            <select
              value={keywordFilter}
              onChange={(e) => {
                setKeywordFilter(e.target.value);
                setKeyword(e.target.value);
              }}
              className="px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white"
            >
              <option value="">Alle Kategorien</option>
              {keywords.map((kw) => (
                <option key={kw.keyword} value={kw.keyword}>
                  {kw.keyword} ({kw.count})
                </option>
              ))}
            </select>
          </div>
        )}
      </div>

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
                    <th className="px-4 py-3 w-10">
                      <input
                        type="checkbox"
                        checked={allSelected}
                        onChange={toggleSelectAll}
                        className="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                        aria-label="Alle auswählen"
                      />
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
                      Faellig am
                    </th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Kategorie
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
                  {data.items.map((inv) => {
                    const isFading = fadingIds.has(inv.id);
                    const isSelectable =
                      inv.collection_status === "open" ||
                      inv.collection_status === "failed";
                    const isBeingSubmitted = submittingIds.has(inv.id);

                    return (
                      <tr
                        key={inv.id}
                        className={`hover:bg-gray-50 transition-opacity duration-500 ${
                          isFading ? "opacity-0" : "opacity-100"
                        }`}
                      >
                        <td className="px-4 py-3">
                          {isSelectable && (
                            <input
                              type="checkbox"
                              checked={selectedIds.has(inv.id)}
                              onChange={() => toggleSelect(inv.id)}
                              className="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                              aria-label={`Rechnung ${inv.voucher_number} auswählen`}
                            />
                          )}
                        </td>
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
                          <KeywordBadge keyword={inv.keyword} />
                        </td>
                        <td className="px-4 py-3">
                          <StatusBadge status={inv.collection_status} />
                        </td>
                        <td className="px-4 py-3 text-right">
                          {inv.collection_status === "in_collection" ? null : isSelectable ? (
                            <button
                              onClick={() => handleActionClick(inv)}
                              disabled={isBeingSubmitted}
                              className={`text-xs px-3 py-1 rounded hover:opacity-90 transition-colors whitespace-nowrap disabled:opacity-50 ${
                                inv.customer_has_iban
                                  ? "bg-blue-600 text-white hover:bg-blue-700"
                                  : "border border-gray-300 text-gray-700 hover:bg-gray-50"
                              }`}
                            >
                              {isBeingSubmitted
                                ? "Einreichen..."
                                : inv.customer_has_iban
                                ? "Lastschrift einreichen"
                                : "IBAN hinterlegen"}
                            </button>
                          ) : null}
                        </td>
                      </tr>
                    );
                  })}
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

      {/* Confirm Dialog */}
      {dialog.type === "confirm" && (
        <ConfirmDialog
          invoice={dialog.invoice}
          ibanMasked={dialog.ibanMasked}
          mandateRef={dialog.mandateRef}
          onConfirm={handleConfirmSubmit}
          onCancel={() => {
            setDialog({ type: "none" });
            setSubmitError(null);
          }}
          isSubmitting={isSubmitting}
        />
      )}

      {/* IBAN Modal */}
      {dialog.type === "iban" && (
        <IbanModal
          invoice={dialog.invoice}
          onSaveAndSubmit={handleIbanSaveAndSubmit}
          onCancel={() => {
            setDialog({ type: "none" });
            setIbanSaveError(null);
          }}
          isSaving={isSubmitting}
          error={ibanSaveError}
        />
      )}

      {/* Sync toast */}
      <SyncToast result={toastResult} onClose={() => setToastResult(null)} />
    </div>
  );
}
