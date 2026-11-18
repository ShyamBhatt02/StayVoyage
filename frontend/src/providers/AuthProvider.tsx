import { createContext, useCallback, useContext, useMemo, type ReactNode } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiError, get, post } from '@/lib/api';
import type { User } from '@/types';

interface LoginResult { twoFactor: boolean; message: string }

interface AuthContextValue {
  user: User | null;
  loading: boolean;
  isAuthenticated: boolean;
  login: (email: string, password: string, remember?: boolean) => Promise<LoginResult>;
  verifyTwoFactor: (code: string) => Promise<string>;
  register: (data: Record<string, unknown>) => Promise<string>;
  logout: () => Promise<void>;
  refresh: () => Promise<unknown>;
  can: (permission: string) => boolean;
}

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const qc = useQueryClient();
  const me = useQuery({
    queryKey: ['auth', 'me'],
    queryFn: async () => {
      try {
        return await get<User | null>('/auth/session');
      } catch (e) {
        if (e instanceof ApiError && (e.status === 401 || e.status === 403)) return null;
        throw e;
      }
    },
    staleTime: 5 * 60_000,
    retry: false,
  });

  const setUser = useCallback((u: User | null) => qc.setQueryData(['auth', 'me'], u), [qc]);

  const login = useCallback(async (email: string, password: string, remember = false) => {
    const res = await post<User | { two_factor: boolean }>('/auth/login', { email, password, remember });
    if ((res.data as { two_factor?: boolean }).two_factor) return { twoFactor: true, message: res.message };
    setUser(res.data as User);
    qc.invalidateQueries({ predicate: (q) => q.queryKey[0] !== 'auth' });
    return { twoFactor: false, message: res.message };
  }, [qc, setUser]);

  const verifyTwoFactor = useCallback(async (code: string) => {
    const res = await post<User>('/auth/two-factor-challenge', { code });
    setUser(res.data);
    return res.message;
  }, [setUser]);

  const register = useCallback(async (data: Record<string, unknown>) => {
    const res = await post<User>('/auth/register', data);
    setUser(res.data);
    return res.message;
  }, [setUser]);

  const logout = useCallback(async () => {
    try {
      await post('/auth/logout');
    } finally {
      setUser(null);
      qc.removeQueries({ predicate: (q) => !['auth', 'meta', 'public'].includes(String(q.queryKey[0])) });
    }
  }, [qc, setUser]);

  const value = useMemo<AuthContextValue>(() => {
    const user = me.data ?? null;
    return {
      user,
      loading: me.isLoading,
      isAuthenticated: !!user,
      login,
      verifyTwoFactor,
      register,
      logout,
      refresh: () => me.refetch(),
      can: (permission: string) => !!user?.permissions && (user.permissions.includes('*') || user.permissions.includes(permission)),
    };
  }, [me, login, verifyTwoFactor, register, logout]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}
