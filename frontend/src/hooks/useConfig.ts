import { useQuery } from '@tanstack/react-query';
import { get } from '@/lib/api';
import type { SiteConfig } from '@/types';

export function useConfig() {
  return useQuery({ queryKey: ['meta', 'config'], queryFn: () => get<SiteConfig>('/meta/config'), staleTime: 10 * 60_000 });
}

export function useSetting<T = string | number | boolean>(key: string, fallback: T): T {
  const { data } = useConfig();
  return (data?.settings?.[key] as T) ?? fallback;
}
