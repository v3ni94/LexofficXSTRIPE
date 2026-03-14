/**
 * Shared sync controls used on both InvoicesPage and DashboardPage.
 * - SyncButton: shows spinner, cooldown countdown, disabled state
 * - LastSyncLabel: "Letzte Synchronisierung: vor X Minuten"
 * - SyncToast: green toast with sync result summary
 */
import { useEffect, useState } from "react";
import type { SyncResult } from "../stores/invoices";

// ---------------------------------------------------------------------------
// Relative time helper
// ---------------------------------------------------------------------------

export function useRelativeTime(date: Date | null): string {
  const [label, setLabel] = useState("");

  useEffect(() => {
    if (!date) {
      setLabel("");
      return;
    }

    const update = () => {
      const diffMs = Date.now() - date.getTime();
      const diffSec = Math.floor(diffMs / 1000);
      if (diffSec < 10) setLabel("gerade eben");
      else if (diffSec < 60) setLabel(`vor ${diffSec}s`);
      else if (diffSec < 3600) setLabel(`vor ${Math.floor(diffSec / 60)} min`);
      else setLabel(`vor ${Math.floor(diffSec / 3600)} Std.`);
    };

    update();
    const id = setInterval(update, 15_000);
    return () => clearInterval(id);
  }, [date]);

  return label;
}

// ---------------------------------------------------------------------------
// LastSyncLabel
// ---------------------------------------------------------------------------

export function LastSyncLabel({ lastSyncAt }: { lastSyncAt: Date | null }) {
  const relative = useRelativeTime(lastSyncAt);
  if (!lastSyncAt) return null;
  return (
    <span className="text-xs text-gray-400 whitespace-nowrap">
      Letzte Sync: {relative}
    </span>
  );
}

// ---------------------------------------------------------------------------
// CooldownSyncButton
// ---------------------------------------------------------------------------

interface SyncButtonProps {
  isSyncing: boolean;
  isCoolingDown: boolean;
  cooldownSecondsLeft: number;
  onClick: () => void;
}

export function CooldownSyncButton({
  isSyncing,
  isCoolingDown,
  cooldownSecondsLeft,
  onClick,
}: SyncButtonProps) {
  const disabled = isSyncing || isCoolingDown;

  return (
    <button
      onClick={onClick}
      disabled={disabled}
      title={isCoolingDown ? `Bitte warte ${cooldownSecondsLeft}s` : undefined}
      className="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white text-sm rounded-md hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
    >
      {isSyncing ? (
        <>
          <svg className="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
          </svg>
          Synchronisiere...
        </>
      ) : isCoolingDown ? (
        <>
          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          Sync ({cooldownSecondsLeft}s)
        </>
      ) : (
        "Rechnungen synchronisieren"
      )}
    </button>
  );
}

// ---------------------------------------------------------------------------
// SyncToast
// ---------------------------------------------------------------------------

export function SyncToast({
  result,
  onClose,
}: {
  result: SyncResult | null;
  onClose: () => void;
}) {
  useEffect(() => {
    if (!result) return;
    const id = setTimeout(onClose, 5000);
    return () => clearTimeout(id);
  }, [result, onClose]);

  if (!result) return null;

  const parts: string[] = [];
  if (result.new_count > 0) parts.push(`${result.new_count} neu`);
  if (result.updated_count > 0) parts.push(`${result.updated_count} aktualisiert`);
  if (result.removed_count > 0) parts.push(`${result.removed_count} entfernt`);
  const summary =
    parts.length > 0
      ? parts.join(", ")
      : `${result.synced_count} Rechnungen geprüft`;

  return (
    <div className="fixed bottom-6 right-6 z-50 flex items-start gap-3 bg-white border border-green-200 shadow-lg rounded-lg px-4 py-3 max-w-sm animate-in">
      <svg className="w-5 h-5 text-green-500 shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 24 24">
        <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      <div className="flex-1 min-w-0">
        <p className="text-sm font-medium text-gray-900">Sync abgeschlossen</p>
        <p className="text-xs text-gray-500 mt-0.5">{summary}</p>
      </div>
      <button onClick={onClose} className="text-gray-400 hover:text-gray-600 shrink-0">
        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Cooldown countdown hook (re-renders every second while cooling down)
// ---------------------------------------------------------------------------

export function useCooldown(
  isCoolingDown: () => boolean,
  cooldownSecondsLeft: () => number
): { coolingDown: boolean; secondsLeft: number } {
  const [tick, setTick] = useState(0);

  useEffect(() => {
    if (!isCoolingDown()) return;
    const id = setInterval(() => setTick((t) => t + 1), 1000);
    return () => clearInterval(id);
  }, [isCoolingDown, tick]);

  return {
    coolingDown: isCoolingDown(),
    secondsLeft: cooldownSecondsLeft(),
  };
}
