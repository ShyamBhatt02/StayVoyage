import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { get } from '@/lib/api';
import { cn } from '@/lib/format';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { DestinationCard } from '@/components/cards';
import { ErrorState, Skeleton } from '@/components/ui/misc';
import type { Destination } from '@/types';

export default function Destinations() {
  usePageMeta('Destinations', 'Explore India and international favourites — Dubai, Bali, Maldives, Goa, Jaipur and more.');
  const { data, isLoading, isError, refetch } = useQuery({ queryKey: ['public', 'destinations'], queryFn: () => get<Destination[]>('/destinations') });
  const [region, setRegion] = useState<'all' | 'india' | 'world'>('all');
  const list = useMemo(() => (data ?? []).filter((d) => region === 'all' || (region === 'india' ? d.country === 'India' : d.country !== 'India')), [data, region]);
  return (
    <div className="container-page py-12">
      <p className="eyebrow">Explore</p>
      <h1 className="section-title mt-2">Where will you go next?</h1>
      <p className="mt-3 max-w-2xl text-ink-600">From royal Rajasthan and the backwaters of Kerala to Dubai's skyline and Maldivian lagoons — every destination comes with hand-picked hotels and experiences.</p>
      <div className="mt-6 flex gap-2">
        {([['all', 'All'], ['india', 'India'], ['world', 'International']] as const).map(([v, l]) => <button key={v} className={cn('chip', region === v && 'chip-active')} onClick={() => setRegion(v)}>{l}</button>)}
      </div>
      {isError ? <ErrorState className="mt-8" onRetry={() => refetch()} /> : (
        <div className="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
          {isLoading ? Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-64 rounded-3xl" />) : list.map((d) => <DestinationCard key={d.id} d={d} />)}
        </div>
      )}
    </div>
  );
}
