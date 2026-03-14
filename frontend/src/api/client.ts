import axios from "axios";

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

// --- Response interceptor: auto-refresh on 401 ---
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

api.interceptors.response.use(
  (response) => response,
  async (error) => {
    const originalRequest = error.config;

    if (
      error.response?.status !== 401 ||
      originalRequest._retry ||
      !getTokens
    ) {
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
      return Promise.reject(refreshError);
    } finally {
      isRefreshing = false;
    }
  }
);
