import { lazy, Suspense } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { CalendarHeart, Check, Clock, Star } from 'lucide-react';
import { get } from '@/lib/api';
import { minutesLabel, money } from '@/lib/format';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { SearchBar } from '@/components/search/SearchBar';
import { HotelCard, PackageCard } from '@/components/cards';
import { EmptyState, ErrorState, Img, Skeleton } from '@/components/ui/misc';
import type { CatalogueItem, Destination, HotelCard as HotelCardT, TourPackage } from '@/types';

const MapView = lazy(() => import('@/components/MapView'));

interface Payload { destination: Destination; hotels: HotelCardT[]; tours: TourPackage[]; activities: CatalogueItem[] }

export default function DestinationDetail() {
  const { slug = '' } = useParams();
  const { data, isLoading, isError, error } = useQuery({ queryKey: ['public', 'destination', slug], queryFn: () => get<Payload>(`/destinations/${slug}`) });
  const d = data?.destination;
  usePageMeta(d ? `Hotels & trips in ${d.name}` : 'Destination', d?.tagline ?? undefined);

  if (isLoading) return <div className="container-page py-10"><Skeleton className="h-80 rounded-3xl" /></div>;
  if (isError || !d) return <div className="container-page py-16">{(error as { status?: number })?.status === 404 ? <EmptyState title="Destination not found" /> : <ErrorState />}</div>;

  return (
    <div className="pb-10">
      <section className="relative isolate overflow-hidden">
        <Img src={d.image_url} alt={`${d.name}, ${d.country}`} eager className="absolute inset-0 -z-10 h-full w-full" />
        <div className="absolute inset-0 -z-10 bg-gradient-to-t from-ink-950/90 via-ink-950/30 to-ink-950/10" />
        <div className="container-page pb-10 pt-40 text-white">
          <p className="text-sm font-semibold uppercase tracking-widest text-white/80">{d.state ? `${d.state}, ` : ''}{d.country}</p>
          <h1 className="mt-2 font-display text-5xl font-semibold">{d.name}</h1>
          <p className="mt-2 text-xl text-white/85">{d.tagline}</p>
          <div className="mt-8 max-w-5xl"><SearchBar initial={{ destination: d.name }} /></div>
        </div>
      </section>

      <div className="container-page mt-12 grid gap-10 lg:grid-cols-[1.4fr_1fr]">
        <div>
          <h2 className="font-display text-2xl font-semibold">About {d.name}</h2>
          <p className="mt-3 leading-relaxed text-ink-700">{d.description}</p>
          {d.best_time_to_visit && <p className="mt-4 flex items-center gap-2 text-sm font-semibold text-brand-700"><CalendarHeart className="h-4 w-4" /> Best time to visit: {d.best_time_to_visit}</p>}
          <ul className="mt-5 grid gap-2 sm:grid-cols-2">{d.highlights.map((h) => <li key={h} className="flex gap-2 text-ink-700"><Check className="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />{h}</li>)}</ul>
        </div>
        <div className="space-y-3">
          {d.gallery.slice(0, 2).map((g, i) => <Img key={i} src={g} alt={`${d.name} photo ${i + 1}`} className="h-44 w-full rounded-2xl" />)}
          {d.latitude && d.longitude && <Suspense fallback={null}><MapView zoom={10} className="h-44 overflow-hidden rounded-2xl" points={[{ lat: d.latitude, lng: d.longitude, label: d.name }]} /></Suspense>}
        </div>
      </div>

      <section className="container-page mt-14">
        <div className="flex items-end justify-between"><h2 className="font-display text-2xl font-semibold">Where to stay in {d.name}</h2><Link to={`/hotels?destination=${encodeURIComponent(d.name)}`} className="link text-sm">See all</Link></div>
        {data.hotels.length ? <div className="mt-6 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">{data.hotels.map((h) => <HotelCard key={h.id} hotel={h} />)}</div> : <p className="mt-4 text-ink-500">Hotels coming soon.</p>}
      </section>

      {data.activities.length > 0 && (
        <section className="container-page mt-14">
          <h2 className="font-display text-2xl font-semibold">Things to do</h2>
          <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {data.activities.map((a) => (
              <div key={a.id} className="card overflow-hidden">
                <Img src={a.image_url} alt="" className="h-36 w-full" />
                <div className="p-4">
                  <p className="line-clamp-2 font-semibold text-ink-900">{a.name}</p>
                  <p className="mt-1 flex items-center gap-3 text-xs text-ink-500"><span className="flex items-center gap-1"><Clock className="h-3.5 w-3.5" />{minutesLabel(a.duration_minutes as number)}</span><span className="flex items-center gap-1"><Star className="h-3.5 w-3.5 fill-sand-400 text-sand-400" />{String(a.rating_avg)}</span></p>
                  <p className="mt-2 font-bold">{money(a.price)} <span className="text-xs font-normal text-ink-500">per adult</span></p>
                </div>
              </div>
            ))}
          </div>
          <p className="mt-4 text-sm text-ink-500">Add any of these to your itinerary in the <Link to="/tour-builder" className="link">Tour Builder</Link>.</p>
        </section>
      )}

      {data.tours.length > 0 && (
        <section className="container-page mt-14">
          <h2 className="font-display text-2xl font-semibold">Tours in {d.name}</h2>
          <div className="mt-6 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">{data.tours.map((t) => <PackageCard key={t.id} pkg={t} />)}</div>
        </section>
      )}
    </div>
  );
}
