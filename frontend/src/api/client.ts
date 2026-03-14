import axios from "axios";
import toast from "react-hot-toast";

// Token accessor injected by the auth store to avoid circular imports.
interface TokenAccessor {
  accessToken: string | null;
  refreshToken: string | null;
  onRefreshSuccess: (newAccessToken: string) => void;
  onRefreshFailure: () => void;
}

let getTokens: (() => TokenAccessor) | null = null;

export function setTokenAccessor(accessor: () => TokenAccessor) {
  getTokens = accessor;
}

export const api = axios.create({
  baseURL: "/api",
  headers: { "Content-Type": "application/json" },
});

// --- Request interceptor: attach access token ---
api.interceptors.request.use((config) => {
  if (getTokens) {
    const { accessToken } = getTokens();
    if (accessToken) {
      config.headers.Authorization = `Bearer ${accessToken}`;
    }
  }
  return config;
});

// --- Response interceptor: auto-refresh on 401 + error toasts ---
let isRefreshing = false;
let pendingQueue: Array<{
  resolve: (token: string) => void;
  reject: (err: unknown) => void;
}> = [];

function processPendingQueue(token: string | null, error: unknown) {
  pendingQueue.forEach(({ resolve, reject }) => {
    if (token) resolve(token);
    else reject(error);
  });
  pendingQueue = [];
}

/** Map a backend error response to a German user-facing message. */
function toUserMessage(error: unknown): string | null {
  if (!axios.isAxiosError(error)) return null;

  const status = error.response?.status;
  const data = error.response?.data as
    | { error?: string; message?: string }
    | undefined;
  const errorCode = data?.error ?? "";

  // Lexoffice-specific
  if (errorCode === "lexoffice_auth_error") {
    return "Der Lexoffice API-Schlüssel ist ungültig. Bitte prüfe deine Einstellungen.";
  }
  if (errorCode === "lexoffice_rate_limit") {
    return "Lexoffice-Rate-Limit erreicht. Bitte versuche es später erneut.";
  }
  if (errorCode === "lexoffice_api_error") {
    return "Lexoffice-API-Fehler. Bitte versuche es erneut.";
  }

  // Stripe-specific
  if (errorCode === "stripe_auth_error") {
    return "Stripe-Anmeldedaten sind ungültig. Bitte prüfe deine Einstellungen.";
  }
  if (errorCode === "stripe_payment_error") {
    return data?.message ?? "Stripe-Zahlung fehlgeschlagen.";
  }

  // Validation
  if (errorCode === "iban_error") {
    return data?.message ?? "Ungültige IBAN.";
  }
  if (errorCode === "mandate_error") {
    return data?.message ?? "SEPA-Mandatsfehler.";
  }

  // HTTP status fallbacks
  if (status === 403) return "Keine Berechtigung für diese Aktion.";
  if (status === 404) return "Die angeforderte Ressource wurde nicht gefunden.";
  if (status === 429) return "Zu viele Anfragen. Bitte warte einen Moment.";
  if (status === 502) return "Verbindung zum externen Dienst fehlgeschlagen.";
  if (status !== undefined && status >= 500) {
    return "Ein interner Fehler ist aufgetreten. Bitte versuche es erneut.";
  }

  return null;
}

api.interceptors.response.use(
  (response) => response,
  async (error) => {
    const originalRequest = error.config;

    if (
      error.response?.status !== 401 ||
      originalRequest._retry ||
      !getTokens
    ) {
      // Show toast for non-401 errors (401 is handled below after refresh attempt)
      if (error.response?.status !== 401) {
        const msg = toUserMessage(error);
        if (msg) toast.error(msg);
      }
      return Promise.reject(error);
    }

    // Skip refresh attempts for auth endpoints themselves
    const url: string = originalRequest.url ?? "";
    if (url.includes("/auth/login") || url.includes("/auth/refresh")) {
      return Promise.reject(error);
    }

    const { refreshToken } = getTokens();
    if (!refreshToken) {
      getTokens()?.onRefreshFailure();
      toast.error("Sitzung abgelaufen. Bitte melde dich erneut an.");
      return Promise.reject(error);
    }

    if (isRefreshing) {
      // Queue this request until refresh completes
      return new Promise<string>((resolve, reject) => {
        pendingQueue.push({ resolve, reject });
      }).then((token) => {
        originalRequest.headers.Authorization = `Bearer ${token}`;
        return api(originalRequest);
      });
    }

    originalRequest._retry = true;
    isRefreshing = true;

    try {
      const res = await axios.post("/api/auth/refresh", {
        refresh_token: refreshToken,
      });
      const newToken: string = res.data.access_token;
      getTokens()?.onRefreshSuccess(newToken);
      processPendingQueue(newToken, null);
      originalRequest.headers.Authorization = `Bearer ${newToken}`;
      return api(originalRequest);
    } catch (refreshError) {
      processPendingQueue(null, refreshError);
      getTokens()?.onRefreshFailure();
      toast.error("Sitzung abgelaufen. Bitte melde dich erneut an.");
      return Promise.reject(refreshError);
    } finally {
      isRefreshing = false;
    }
  }
);
