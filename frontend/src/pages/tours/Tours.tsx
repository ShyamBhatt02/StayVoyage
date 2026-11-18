import { useState } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Route } from 'lucide-react';
import { get, getPage } from '@/lib/api';
import { cn } from '@/lib/format';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { ButtonLink } from '@/components/ui/Button';
import { CardSkeleton, EmptyState, ErrorState, Pagination } from '@/components/ui/misc';
import { PackageCard } from '@/components/cards';
import type { Destination, TourPackage } from '@/types';

export default function Tours() {
  usePageMeta('Tour packages', 'Curated multi-day tours you can book as-is or customise in the Tour Builder.');
  const [destination, setDestination] = useState('');
  const [duration, setDuration] = useState('');
  const [sort, setSort] = useState('popular');
  const [page, setPage] = useState(1);
  const destinations = useQuery({ queryKey: ['public', 'destinations'], queryFn: () => get<Destination[]>('/destinations') });
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['public', 'tours', destination, duration, sort, page],
    queryFn: () => getPage<TourPackage>('/tours', { destination: destination || undefined, duration: duration || undefined, sort, page }),
    placeholderData: keepPreviousData,
  });

  return (
    <>
      <section className="bg-gradient-to-br from-brand-800 to-brand-950 text-white">
        <div className="container-page py-16">
          <p className="eyebrow text-sand-300">Tour packages</p>
          <h1 className="mt-2 font-display text-4xl font-semibold sm:text-5xl">Ready-made journeys, fully customisable</h1>
          <p className="mt-4 max-w-2xl text-brand-100">Every tour includes hand-picked hotels, transfers and experiences. Book it as it is, or tap “Customize This Tour” to change anything.</p>
          <ButtonLink to="/tour-builder" variant="white" className="mt-6" icon={<Route className="h-4 w-4" />}>Or build from scratch</ButtonLink>
        </div>
      </section>
      <div className="container-page py-10">
        <div className="mb-6 flex flex-wrap items-center gap-3">
          <select className="input w-auto" value={destination} onChange={(e) => { setDestination(e.target.value); setPage(1); }} aria-label="Destination">
            <option value="">All destinations</option>
            {destinations.data?.map((d) => <option key={d.id} value={d.slug}>{d.name}</option>)}
          </select>
          <div className="flex flex-wrap gap-1.5" role="group" aria-label="Duration">
            {[['', 'Any length'], ['short', '1–3 days'], ['medium', '4–6 days'], ['long', '7+ days']].map(([v, l]) => (
              <button key={l} className={cn('chip', duration === v && 'chip-active')} onClick={() => { setDuration(v!); setPage(1); }}>{l}</button>
            ))}
          </div>
          <select className="input ml-auto w-auto" value={sort} onChange={(e) => setSort(e.target.value)} aria-label="Sort">
            <option value="popular">Most popular</option><option value="price_asc">Price: low to high</option><option value="price_desc">Price: high to low</option><option value="duration">Shortest first</option>
          </select>
        </div>
        {isError ? <ErrorState onRetry={() => refetch()} /> : isLoading ? <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3"><CardSkeleton count={6} /></div>
          : !data?.items.length ? <EmptyState title="No tours match" description="Try another destination or duration — or build your own trip." action={<ButtonLink to="/tour-builder">Open Tour Builder</ButtonLink>} />
            : <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">{data.items.map((p) => <PackageCard key={p.id} pkg={p} />)}</div>}
        <Pagination className="mt-10" meta={data?.pagination} onPage={setPage} />
      </div>
    </>
  );
}
