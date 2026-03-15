import { create } from "zustand";
import { api } from "../api/client";

export interface CollectionListItem {
  id: string;
  invoice_id: string;
  voucher_number: string;
  contact_name: string;
  amount_cents: number;
  currency: string;
  iban_masked: string | null;
  mandate_reference: string | null;
  stripe_status: string | null;
  submitted_at: string | null;
  completed_at: string | null;
  failure_reason: string | null;
  description: string | null;
  scheduled_date: string | null;
  is_scheduled: boolean;
}

interface CollectionListResponse {
  items: CollectionListItem[];
  total: number;
  page: number;
  per_page: number;
  total_pages: number;
}

interface CollectionsFilters {
  status: string;
  date_from: string;
  date_to: string;
  customer_id: string;
}

interface CollectionsState {
  data: CollectionListResponse | null;
  isLoading: boolean;
  filters: CollectionsFilters;
  page: number;

  fetchCollections: (page?: number, filters?: Partial<CollectionsFilters>) => Promise<void>;
  setFilters: (filters: Partial<CollectionsFilters>) => void;
  setPage: (page: number) => void;
  reset: () => void;
}

const defaultFilters: CollectionsFilters = {
  status: "",
  date_from: "",
  date_to: "",
  customer_id: "",
};

export const useCollectionsStore = create<CollectionsState>((set, get) => ({
  data: null,
  isLoading: false,
  filters: { ...defaultFilters },
  page: 1,

  fetchCollections: async (page?: number, filters?: Partial<CollectionsFilters>) => {
    const p = page ?? get().page;
    const f = { ...get().filters, ...filters };
    set({ isLoading: true });
    try {
      const params: Record<string, string | number> = { page: p, per_page: 20 };
      if (f.status) params.status = f.status;
      if (f.date_from) params.date_from = f.date_from;
      if (f.date_to) params.date_to = f.date_to;
      if (f.customer_id) params.customer_id = f.customer_id;

      const res = await api.get("/collections", { params });
      set({ data: res.data, isLoading: false, page: p, filters: f });
    } catch {
      set({ isLoading: false });
    }
  },

  setFilters: (filters) => {
    const merged = { ...get().filters, ...filters };
    set({ filters: merged, page: 1 });
    get().fetchCollections(1, merged);
  },

  setPage: (page) => {
    set({ page });
    get().fetchCollections(page);
  },

  reset: () => {
    set({ filters: { ...defaultFilters }, page: 1, data: null });
  },
}));
