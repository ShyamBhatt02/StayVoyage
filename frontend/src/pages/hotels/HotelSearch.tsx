import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Crown, LayoutGrid, List, SlidersHorizontal, X } from 'lucide-react';
import { getPage } from '@/lib/api';
import { cn, money } from '@/lib/format';
import { useConfig } from '@/hooks/useConfig';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { SearchBar, type SearchValues } from '@/components/search/SearchBar';
import { HotelCard } from '@/components/cards';
import { Button } from '@/components/ui/Button';
import { CardSkeleton, EmptyState, ErrorState, Pagination, Stars } from '@/components/ui/misc';
import { Switch } from '@/components/ui/misc';
import type { HotelCard as HotelCardT } from '@/types';

const SORTS = [
  ['recommended', 'Recommended'], ['price_asc', 'Price: low to high'], ['price_desc', 'Price: high to low'], ['rating', 'Top rated'], ['distance', 'Distance from centre'],
] as const;

export default function HotelSearch() {
  const [params, setParams] = useSearchParams();
  const { data: config } = useConfig();
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [layout, setLayout] = useState<'grid' | 'list'>('grid');
  const destination = params.get('destination') ?? '';
  usePageMeta(destination ? `Hotels in ${destination}` : 'Search hotels', 'Compare hand-picked hotels with live availability and member pricing.');

  const query = useMemo(() => {
    const q: Record<string, unknown> = {};
    params.forEach((v, k) => {
      if (['stars', 'amenities', 'property_types'].includes(k)) q[k] = params.getAll(k);
      else q[k] = v;
    });
    return q;
  }, [params]);

  const { data, isLoading, isFetching, isError, error, refetch } = useQuery({
    queryKey: ['public', 'hotels', query],
    queryFn: () => getPage<HotelCardT>('/hotels', Object.fromEntries(Object.entries(query).map(([k, v]) => [Array.isArray(v) ? `${k}[]` : k, v]))),
    placeholderData: keepPreviousData,
  });

  useEffect(() => { if (filtersOpen) document.body.style.overflow = 'hidden'; return () => { document.body.style.overflow = ''; }; }, [filtersOpen]);

  const update = (changes: Record<string, string | string[] | null>) => {
    const next = new URLSearchParams(params);
    Object.entries(changes).forEach(([k, v]) => {
      next.delete(k);
      if (Array.isArray(v)) v.forEach((x) => next.append(k, x));
      else if (v !== null && v !== '') next.set(k, v);
    });
    if (!('page' in changes)) next.delete('page');
    setParams(next, { replace: false });
  };
  const toggleList = (key: string, value: string) => {
    const current = params.getAll(key);
    update({ [key]: current.includes(value) ? current.filter((x) => x !== value) : [...current, value] });
  };

  const onSearch = (v: SearchValues) => update({ destination: v.destination ?? '', check_in: v.check_in, check_out: v.check_out, guests: String(v.guests), rooms: String(v.rooms) });
  const activeFilters = ['price_min', 'price_max', 'rating_min', 'stars', 'amenities', 'distance_max', 'membership', 'breakfast', 'free_cancellation', 'property_types'].filter((k) => params.getAll(k).length > 0).length;
  const qs = new URLSearchParams(['check_in', 'check_out', 'guests', 'rooms'].filter((k) => params.get(k)).map((k) => [k, params.get(k)!])).toString();

  const filterPanel = (
    <div className="space-y-6">
      <div className="rounded-2xl bg-sand-50 p-4 ring-1 ring-sand-200">
        <Switch checked={params.get('membership') === '1'} onChange={(v) => update({ membership: v ? '1' : null })} label={<span className="flex items-center gap-1.5"><Crown className="h-4 w-4 text-sand-600" /> Membership eligible only</span>} />
      </div>
      <fieldset>
        <legend className="mb-3 text-sm font-bold text-ink-900">Price per night</legend>
        <div className="grid grid-cols-2 gap-2">
          <input type="number" min={0} inputMode="numeric" defaultValue={params.get('price_min') ?? ''} placeholder={`Min ${money(config?.price_range.min ?? 0)}`} aria-label="Minimum price"
            className="input" onBlur={(e) => update({ price_min: e.target.value })} />
          <input type="number" min={0} inputMode="numeric" defaultValue={params.get('price_max') ?? ''} placeholder={`Max ${money(config?.price_range.max ?? 50000)}`} aria-label="Maximum price"
            className="input" onBlur={(e) => update({ price_max: e.target.value })} />
        </div>
        <div className="mt-2 flex flex-wrap gap-1.5">
          {[[0, 5000], [5000, 10000], [10000, 20000], [20000, 100000]].map(([a, b]) => (
            <button key={a} className={cn('chip text-xs', params.get('price_min') === String(a) && params.get('price_max') === String(b) && 'chip-active')} onClick={() => update({ price_min: String(a), price_max: String(b) })}>
              {b! >= 100000 ? `${money(a)}+` : `${money(a)}–${money(b)}`}
            </button>
          ))}
        </div>
      </fieldset>
      <fieldset>
        <legend className="mb-3 text-sm font-bold text-ink-900">Guest rating</legend>
        <div className="flex flex-wrap gap-1.5">
          {[['', 'Any'], ['3.5', '3.5+'], ['4', '4.0+'], ['4.5', '4.5+']].map(([v, l]) => (
            <button key={l} className={cn('chip', (params.get('rating_min') ?? '') === v && 'chip-active')} onClick={() => update({ rating_min: v! })}>{l}</button>
          ))}
        </div>
      </fieldset>
      <fieldset>
        <legend className="mb-3 text-sm font-bold text-ink-900">Star category</legend>
        <div className="space-y-2">
          {[5, 4, 3].map((s) => (
            <label key={s} className="flex cursor-pointer items-center gap-2.5 text-sm">
              <input type="checkbox" className="h-4 w-4 accent-brand-600" checked={params.getAll('stars').includes(String(s))} onChange={() => toggleList('stars', String(s))} />
              <Stars value={s} size="h-3.5 w-3.5" /> <span className="text-ink-600">{s} star</span>
            </label>
          ))}
        </div>
      </fieldset>
      <fieldset>
        <legend className="mb-3 text-sm font-bold text-ink-900">Popular filters</legend>
        <div className="space-y-2.5">
          <Switch checked={params.get('free_cancellation') === '1'} onChange={(v) => update({ free_cancellation: v ? '1' : null })} label="Free cancellation" />
          <Switch checked={params.get('breakfast') === '1'} onChange={(v) => update({ breakfast: v ? '1' : null })} label="Breakfast included" />
        </div>
      </fieldset>
      <fieldset>
        <legend className="mb-3 text-sm font-bold text-ink-900">Distance from centre</legend>
        <select className="input" value={params.get('distance_max') ?? ''} onChange={(e) => update({ distance_max: e.target.value })} aria-label="Maximum distance">
          <option value="">Any distance</option><option value="1">Within 1 km</option><option value="3">Within 3 km</option><option value="5">Within 5 km</option><option value="10">Within 10 km</option>
        </select>
      </fieldset>
      <fieldset>
        <legend className="mb-3 text-sm font-bold text-ink-900">Property type</legend>
        <div className="flex flex-wrap gap-1.5">
          {(config?.property_types ?? []).map((t) => (
            <button key={t} className={cn('chip capitalize', params.getAll('property_types').includes(t) && 'chip-active')} onClick={() => toggleList('property_types', t)}>{t}</button>
          ))}
        </div>
      </fieldset>
      <fieldset>
        <legend className="mb-3 text-sm font-bold text-ink-900">Amenities</legend>
        <div className="grid grid-cols-1 gap-2">
          {(config?.amenities ?? []).filter((a) => a.scope !== 'room').slice(0, 14).map((a) => (
            <label key={a.slug} className="flex cursor-pointer items-center gap-2.5 text-sm text-ink-700">
              <input type="checkbox" className="h-4 w-4 accent-brand-600" checked={params.getAll('amenities').includes(a.slug)} onChange={() => toggleList('amenities', a.slug)} />{a.name}
            </label>
          ))}
        </div>
      </fieldset>
    </div>
  );

  return (
    <div className="pb-10">
      <div className="border-b border-ink-200 bg-white py-5">
        <div className="container-page">
          <SearchBar variant="compact" key={params.toString()} onSearch={onSearch} initial={{
            destination, check_in: params.get('check_in') ?? undefined, check_out: params.get('check_out') ?? undefined,
            guests: params.get('guests') ? Number(params.get('guests')) : undefined, rooms: params.get('rooms') ? Number(params.get('rooms')) : undefined,
          }} />
        </div>
      </div>

      <div className="container-page mt-8 grid gap-8 lg:grid-cols-[280px_1fr]">
        <aside className="hidden lg:block">
          <div className="card sticky top-24 max-h-[calc(100vh-7rem)] overflow-y-auto p-5">
            <div className="mb-4 flex items-center justify-between">
              <h2 className="font-sans text-base font-bold text-ink-900">Filters</h2>
              {activeFilters > 0 && <button className="text-sm font-semibold text-brand-700 hover:underline" onClick={() => setParams(new URLSearchParams(qs + (destination ? `&destination=${destination}` : '')))}>Clear all</button>}
            </div>
            {filterPanel}
          </div>
        </aside>

        <section aria-live="polite" aria-busy={isFetching}>
          <div className="sticky top-[72px] z-20 -mx-4 mb-5 flex flex-wrap items-center justify-between gap-3 bg-ink-50/95 px-4 py-3 backdrop-blur sm:mx-0 sm:px-0 lg:static lg:bg-transparent lg:p-0">
            <div>
              <h1 className="font-display text-2xl font-semibold text-ink-900">{destination ? `Hotels in “${destination}”` : 'All hotels'}</h1>
              <p className="text-sm text-ink-500">{isLoading ? 'Searching…' : `${data?.pagination.total ?? 0} propert${data?.pagination.total === 1 ? 'y' : 'ies'} found${params.get('check_in') ? ' with rooms available for your dates' : ''}`}</p>
            </div>
            <div className="flex items-center gap-2">
              <Button variant="outline" size="sm" className="lg:hidden" onClick={() => setFiltersOpen(true)} icon={<SlidersHorizontal className="h-4 w-4" />}>Filters{activeFilters ? ` (${activeFilters})` : ''}</Button>
              <select className="input h-9 w-auto py-1.5 text-sm" value={params.get('sort') ?? 'recommended'} onChange={(e) => update({ sort: e.target.value })} aria-label="Sort hotels">
                {SORTS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
              </select>
              <div className="hidden rounded-xl bg-ink-100 p-1 sm:flex" role="group" aria-label="Layout">
                <button onClick={() => setLayout('grid')} aria-pressed={layout === 'grid'} className={cn('rounded-lg p-1.5', layout === 'grid' && 'bg-white shadow-sm')} aria-label="Grid view"><LayoutGrid className="h-4 w-4" /></button>
                <button onClick={() => setLayout('list')} aria-pressed={layout === 'list'} className={cn('rounded-lg p-1.5', layout === 'list' && 'bg-white shadow-sm')} aria-label="List view"><List className="h-4 w-4" /></button>
              </div>
            </div>
          </div>

          {isError ? <ErrorState message={(error as Error).message} onRetry={() => refetch()} />
            : isLoading ? <div className="grid gap-6 sm:grid-cols-2 xl:grid-cols-3"><CardSkeleton count={6} /></div>
              : !data?.items.length ? (
                <EmptyState title="No hotels found" description="No properties match these dates and filters. Try different dates, fewer filters, or a nearby destination."
                  action={<Button variant="outline" onClick={() => setParams(new URLSearchParams())}>Reset search</Button>} />
              ) : (
                <div className={cn('grid gap-6 transition-opacity', isFetching && 'opacity-60', layout === 'grid' ? 'sm:grid-cols-2 xl:grid-cols-3' : 'grid-cols-1')}>
                  {data.items.map((h) => <HotelCard key={h.id} hotel={h} query={qs} layout={layout} />)}
                </div>
              )}
          <Pagination className="mt-10" meta={data?.pagination} onPage={(p) => { update({ page: String(p) }); window.scrollTo({ top: 0, behavior: 'smooth' }); }} />
        </section>
      </div>

      {filtersOpen && (
        <div className="fixed inset-0 z-50 lg:hidden" role="dialog" aria-modal="true" aria-label="Filters">
          <div className="absolute inset-0 bg-ink-950/40" onClick={() => setFiltersOpen(false)} />
          <div className="absolute inset-y-0 right-0 flex w-full max-w-sm flex-col bg-white shadow-lift">
            <div className="flex items-center justify-between border-b border-ink-100 px-5 py-4">
              <h2 className="font-sans text-lg font-bold">Filters</h2>
              <button onClick={() => setFiltersOpen(false)} className="rounded-lg p-1.5 hover:bg-ink-100" aria-label="Close filters"><X className="h-5 w-5" /></button>
            </div>
            <div className="flex-1 overflow-y-auto p-5">{filterPanel}</div>
            <div className="border-t border-ink-100 p-4"><Button className="w-full" onClick={() => setFiltersOpen(false)}>Show {data?.pagination.total ?? 0} hotels</Button></div>
          </div>
        </div>
      )}
    </div>
  );
}
