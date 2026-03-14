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

interface InvoicesState {
  data: InvoiceListResponse | null;
  isLoading: boolean;
  isSyncing: boolean;
  search: string;
  page: number;
  lastSyncResult: SyncResult | null;

  fetchInvoices: (page?: number, search?: string) => Promise<void>;
  syncInvoices: () => Promise<SyncResult>;
  setSearch: (search: string) => void;
  setPage: (page: number) => void;
}

export const useInvoicesStore = create<InvoicesState>((set, get) => ({
  data: null,
  isLoading: false,
  isSyncing: false,
  search: "",
  page: 1,
  lastSyncResult: null,

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
      // Refresh list after sync
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
}));
