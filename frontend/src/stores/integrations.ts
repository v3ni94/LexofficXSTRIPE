import { create } from "zustand";
import { api } from "../api/client";

interface IntegrationStatus {
  lexoffice_connected: boolean;
  stripe_connected: boolean;
  lexoffice_last_sync: string | null;
}

interface IntegrationsState {
  status: IntegrationStatus | null;
  isLoading: boolean;
  error: string | null;

  fetchStatus: () => Promise<void>;
  connectLexoffice: (apiKey: string) => Promise<string>;
  connectStripe: (secretKey: string, webhookSecret: string) => Promise<string>;
  disconnectLexoffice: () => Promise<void>;
  disconnectStripe: () => Promise<void>;
}

export const useIntegrationsStore = create<IntegrationsState>((set) => ({
  status: null,
  isLoading: false,
  error: null,

  fetchStatus: async () => {
    set({ isLoading: true, error: null });
    try {
      const res = await api.get("/integrations");
      set({ status: res.data, isLoading: false });
    } catch {
      set({ isLoading: false, error: "Status konnte nicht geladen werden" });
    }
  },

  connectLexoffice: async (apiKey: string) => {
    const res = await api.put("/integrations/lexoffice", { api_key: apiKey });
    // Re-fetch status
    const statusRes = await api.get("/integrations");
    set({ status: statusRes.data });
    return res.data.message;
  },

  connectStripe: async (secretKey: string, webhookSecret: string) => {
    const res = await api.put("/integrations/stripe", {
      secret_key: secretKey,
      webhook_secret: webhookSecret,
    });
    const statusRes = await api.get("/integrations");
    set({ status: statusRes.data });
    return res.data.message;
  },

  disconnectLexoffice: async () => {
    await api.delete("/integrations/lexoffice");
    const statusRes = await api.get("/integrations");
    set({ status: statusRes.data });
  },

  disconnectStripe: async () => {
    await api.delete("/integrations/stripe");
    const statusRes = await api.get("/integrations");
    set({ status: statusRes.data });
  },
}));
