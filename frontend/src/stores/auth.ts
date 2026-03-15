import { create } from "zustand";
import { api, setTokenAccessor } from "../api/client";

interface User {
  id: string;
  email: string;
  display_name: string | null;
  is_active: boolean;
  organization_id: string | null;
  organization_name: string | null;
  role: string | null;
}

interface AuthState {
  user: User | null;
  accessToken: string | null;
  refreshToken: string | null;
  isAuthenticated: boolean;
  isLoading: boolean;

  login: (email: string, password: string) => Promise<void>;
  register: (
    email: string,
    password: string,
    passwordConfirm: string,
    companyName: string
  ) => Promise<void>;
  logout: () => void;
  refreshAccessToken: () => Promise<boolean>;
  fetchUser: () => Promise<void>;
}

export const useAuthStore = create<AuthState>((set, get) => ({
  user: null,
  accessToken: null,
  refreshToken: null,
  isAuthenticated: false,
  isLoading: false,

  login: async (email, password) => {
    const res = await api.post("/auth/login", { email, password });
    const { access_token, refresh_token } = res.data;
    set({
      accessToken: access_token,
      refreshToken: refresh_token,
      isAuthenticated: true,
    });
    await get().fetchUser();
  },

  register: async (email, password, passwordConfirm, companyName) => {
    const res = await api.post("/auth/register", {
      email,
      password,
      password_confirm: passwordConfirm,
      company_name: companyName,
    });
    const { access_token, refresh_token } = res.data;
    set({
      accessToken: access_token,
      refreshToken: refresh_token,
      isAuthenticated: true,
    });
    await get().fetchUser();
  },

  logout: () => {
    const token = get().accessToken;
    if (token) {
      api.post("/auth/logout").catch(() => {});
    }
    set({
      user: null,
      accessToken: null,
      refreshToken: null,
      isAuthenticated: false,
    });
  },

  refreshAccessToken: async () => {
    const { refreshToken } = get();
    if (!refreshToken) return false;
    try {
      const res = await api.post("/auth/refresh", {
        refresh_token: refreshToken,
      });
      set({ accessToken: res.data.access_token });
      return true;
    } catch {
      get().logout();
      return false;
    }
  },

  fetchUser: async () => {
    try {
      set({ isLoading: true });
      const res = await api.get("/auth/me");
      set({ user: res.data, isLoading: false });
    } catch {
      set({ isLoading: false });
      get().logout();
    }
  },
}));

// Wire up the token accessor
setTokenAccessor(() => ({
  accessToken: useAuthStore.getState().accessToken,
  refreshToken: useAuthStore.getState().refreshToken,
  onRefreshSuccess: (newAccessToken: string) => {
    useAuthStore.setState({ accessToken: newAccessToken });
  },
  onRefreshFailure: () => {
    useAuthStore.getState().logout();
  },
}));
