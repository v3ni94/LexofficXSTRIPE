import { create } from "zustand";
import { api } from "../api/client";

export interface DashboardStats {
  open_invoices_count: number;
  open_invoices_amount: number;
  in_collection_count: number;
  in_collection_amount: number;
  collected_last_30_days_count: number;
  collected_last_30_days_amount: number;
  failed_count: number;
  failed_amount: number;
  lexoffice_connected: boolean;
  stripe_connected: boolean;
  last_sync: string | null;
}

export interface RecentCollection {
  id: string;
  voucher_number: string;
  contact_name: string;
  amount_cents: number;
  currency: string;
  stripe_status: string | null;
  mandate_reference: string | null;
  submitted_at: string | null;
  failure_reason: string | null;
}

export interface UpcomingInvoice {
  id: string;
  voucher_number: string;
  contact_name: string;
  total_gross_amount: number;
  currency: string;
  due_date: string | null;
  collection_status: string;
}

interface DashboardState {
  stats: DashboardStats | null;
  recentCollections: RecentCollection[];
  upcomingInvoices: UpcomingInvoice[];
  isLoading: boolean;

  fetchAll: () => Promise<void>;
}

export const useDashboardStore = create<DashboardState>((set) => ({
  stats: null,
  recentCollections: [],
  upcomingInvoices: [],
  isLoading: false,

  fetchAll: async () => {
    set({ isLoading: true });
    try {
      const [statsRes, recentRes, upcomingRes] = await Promise.all([
        api.get("/dashboard/stats"),
        api.get("/dashboard/recent-collections"),
        api.get("/dashboard/upcoming-invoices"),
      ]);
      set({
        stats: statsRes.data,
        recentCollections: recentRes.data,
        upcomingInvoices: upcomingRes.data,
        isLoading: false,
      });
    } catch {
      set({ isLoading: false });
    }
  },
}));
