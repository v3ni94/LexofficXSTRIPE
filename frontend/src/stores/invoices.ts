import { create } from "zustand";
import { api } from "../api/client";

export interface InvoiceListItem {
  id: string;
  lexoffice_invoice_id: string;
  voucher_number: string;
  customer_id: string | null;
  contact_name: string;
  total_gross_amount: number;
  currency: string;
  due_date: string | null;
  lexoffice_status: string;
  collection_status: string;
  customer_has_iban: boolean;
}

interface InvoiceListResponse {
  items: InvoiceListItem[];
  total: number;
  page: number;
  per_page: number;
  total_pages: number;
}

interface SyncResult {
  synced_count: number;
  new_count: number;
  updated_count: number;
}

interface SubmitResult {
  collection_id: string;
  status: string;
  stripe_payment_intent_id: string;
}

interface BatchSubmitResult {
  successful: Array<{
    invoice_id: string;
    collection_id: string;
    stripe_payment_intent_id: string;
  }>;
  failed: Array<{ invoice_id: string; error: string }>;
}

interface InvoicesState {
  data: InvoiceListResponse | null;
  isLoading: boolean;
  isSyncing: boolean;
  search: string;
  page: number;
  lastSyncResult: SyncResult | null;
  submittingIds: Set<string>;
  pollingIntervalId: ReturnType<typeof setInterval> | null;

  fetchInvoices: (page?: number, search?: string) => Promise<void>;
  syncInvoices: () => Promise<SyncResult>;
  setSearch: (search: string) => void;
  setPage: (page: number) => void;
  submitCollection: (invoiceId: string) => Promise<SubmitResult>;
  submitBatch: (invoiceIds: string[]) => Promise<BatchSubmitResult>;
  saveIban: (customerId: string, iban: string, accountHolderName: string) => Promise<void>;
  startPolling: () => void;
  stopPolling: () => void;
}

export const useInvoicesStore = create<InvoicesState>((set, get) => ({
  data: null,
  isLoading: false,
  isSyncing: false,
  search: "",
  page: 1,
  lastSyncResult: null,
  submittingIds: new Set(),
  pollingIntervalId: null,

  fetchInvoices: async (page?: number, search?: string) => {
    const p = page ?? get().page;
    const s = search ?? get().search;
    set({ isLoading: true });
    try {
      const params: Record<string, string | number> = {
        page: p,
        per_page: 20,
      };
      if (s) params.search = s;
      const res = await api.get("/invoices", { params });
      set({ data: res.data, isLoading: false, page: p, search: s });
    } catch {
      set({ isLoading: false });
    }
  },

  syncInvoices: async () => {
    set({ isSyncing: true });
    try {
      const res = await api.post("/invoices/sync");
      const result: SyncResult = res.data;
      set({ isSyncing: false, lastSyncResult: result });
      await get().fetchInvoices(1);
      return result;
    } catch (err) {
      set({ isSyncing: false });
      throw err;
    }
  },

  setSearch: (search: string) => {
    set({ search, page: 1 });
    get().fetchInvoices(1, search);
  },

  setPage: (page: number) => {
    set({ page });
    get().fetchInvoices(page);
  },

  submitCollection: async (invoiceId: string) => {
    set((state) => ({
      submittingIds: new Set([...state.submittingIds, invoiceId]),
    }));
    try {
      const res = await api.post("/collections/submit", { invoice_id: invoiceId });
      // Refresh list after submission
      await get().fetchInvoices();
      return res.data as SubmitResult;
    } finally {
      set((state) => {
        const next = new Set(state.submittingIds);
        next.delete(invoiceId);
        return { submittingIds: next };
      });
    }
  },

  submitBatch: async (invoiceIds: string[]) => {
    const res = await api.post("/collections/submit-batch", {
      invoice_ids: invoiceIds,
    });
    await get().fetchInvoices();
    return res.data as BatchSubmitResult;
  },

  saveIban: async (customerId: string, iban: string, accountHolderName: string) => {
    await api.put(`/customers/${customerId}/iban`, {
      iban,
      account_holder_name: accountHolderName,
    });
    await get().fetchInvoices();
  },

  startPolling: () => {
    const existing = get().pollingIntervalId;
    if (existing !== null) return;

    const id = setInterval(() => {
      get().fetchInvoices();
    }, 30_000);

    set({ pollingIntervalId: id });
  },

  stopPolling: () => {
    const id = get().pollingIntervalId;
    if (id !== null) {
      clearInterval(id);
      set({ pollingIntervalId: null });
    }
  },
}));
