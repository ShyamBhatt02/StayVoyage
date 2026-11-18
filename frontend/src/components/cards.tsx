import { Link } from 'react-router-dom';
import { Clock, Coffee, Crown, MapPin, Moon, ShieldCheck, Star, Wifi, Waves, Dumbbell, Utensils, Sparkles, Car, Plane, type LucideIcon } from 'lucide-react';
import { Badge, Img, RatingPill, Stars } from '@/components/ui/misc';
import { cn, money } from '@/lib/format';
import type { Destination, HotelCard as HotelCardT, TourPackage } from '@/types';

const AMENITY_ICONS: Record<string, LucideIcon> = {
  'free-wi-fi': Wifi, 'swimming-pool': Waves, 'fitness-centre': Dumbbell, restaurant: Utensils, 'spa-wellness': Sparkles,
  'free-parking': Car, 'airport-shuttle': Plane, 'breakfast-available': Coffee,
};

export function AmenityIcon({ slug, className }: { slug: string; className?: string }) {
  const Icon = AMENITY_ICONS[slug] ?? ShieldCheck;
  return <Icon className={cn('h-4 w-4', className)} aria-hidden />;
}

export function HotelCard({ hotel, query, layout = 'grid' }: { hotel: HotelCardT; query?: string; layout?: 'grid' | 'list' }) {
  const href = `/hotels/${hotel.slug}${query ? `?${query}` : ''}`;
  const list = layout === 'list';
  return (
    <article className={cn('card group overflow-hidden transition hover:-translate-y-0.5 hover:shadow-lift', list && 'sm:grid sm:grid-cols-[300px_1fr]')}>
      <Link to={href} className={cn('relative block overflow-hidden', list ? 'h-56 sm:h-full' : 'h-56')} tabIndex={-1} aria-hidden>
        <Img src={hotel.cover_url} alt="" className="h-full w-full transition duration-500 group-hover:scale-105" />
        <div className="absolute left-3 top-3 flex flex-wrap gap-1.5">
          {hotel.is_membership_eligible
            ? <Badge tone="sand" className="bg-sand-50/95 backdrop-blur" icon={<Crown className="h-3 w-3" />}>Membership Eligible</Badge>
            : <Badge tone="neutral" className="bg-white/95 backdrop-blur">Regular Booking</Badge>}
        </div>
        {hotel.rooms_left !== null && hotel.rooms_left !== undefined && hotel.rooms_left <= 5 && (
          <span className="absolute bottom-3 left-3 rounded-full bg-coral-500 px-2.5 py-1 text-xs font-bold text-white">Only {hotel.rooms_left} left</span>
        )}
      </Link>
      <div className="flex flex-col p-5">
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0">
            <Stars value={hotel.star_rating} size="h-3.5 w-3.5" label={`${hotel.star_rating}-star ${hotel.property_type}`} />
            <h3 className="mt-1.5 font-display text-lg font-semibold leading-snug text-ink-900">
              <Link to={href} className="hover:text-brand-700 focus:outline-none">
                <span className="absolute inset-0 hidden" />{hotel.name}
              </Link>
            </h3>
            <p className="mt-1 flex items-center gap-1 text-sm text-ink-500"><MapPin className="h-3.5 w-3.5 shrink-0" />{hotel.city}, {hotel.country}
              {hotel.distance_from_center_km !== null && <span className="text-ink-400">· {hotel.distance_from_center_km} km from centre</span>}</p>
          </div>
        </div>
        <RatingPill value={hotel.rating_avg} count={hotel.review_count} className="mt-3" />
        {hotel.amenities && hotel.amenities.length > 0 && (
          <ul className="mt-3 flex flex-wrap gap-x-3 gap-y-1.5 text-xs text-ink-600" aria-label="Top amenities">
            {hotel.amenities.slice(0, list ? 6 : 4).map((a) => (
              <li key={a.id} className="flex items-center gap-1"><AmenityIcon slug={a.slug} className="h-3.5 w-3.5 text-brand-600" />{a.name}</li>
            ))}
          </ul>
        )}
        <div className="mt-4 flex flex-wrap gap-1.5">
          {hotel.free_cancellation_available && <Badge tone="success">Free cancellation</Badge>}
          {hotel.breakfast_available && <Badge tone="info" icon={<Coffee className="h-3 w-3" />}>Breakfast</Badge>}
        </div>
        <div className="mt-auto flex items-end justify-between gap-3 border-t border-ink-100 pt-4 mt-4">
          <div>
            {hotel.is_membership_eligible && <p className="flex items-center gap-1 text-xs font-semibold text-sand-700"><Moon className="h-3.5 w-3.5" /> Use membership nights</p>}
            <p className="text-xs text-ink-500">Regular price from</p>
            <p className="text-xl font-bold text-ink-900">{money(hotel.from_price)}<span className="text-sm font-medium text-ink-500">/night</span></p>
          </div>
          <Link to={href} className="rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700">View Hotel</Link>
        </div>
      </div>
    </article>
  );
}

export function PackageCard({ pkg }: { pkg: TourPackage }) {
  return (
    <article className="card group flex flex-col overflow-hidden transition hover:-translate-y-0.5 hover:shadow-lift">
      <Link to={`/tours/${pkg.slug}`} className="relative block h-56 overflow-hidden" tabIndex={-1} aria-hidden>
        <Img src={pkg.image_url} alt="" className="h-full w-full transition duration-500 group-hover:scale-105" />
        <span className="absolute left-3 top-3 rounded-full bg-white/95 px-3 py-1 text-xs font-bold text-ink-800 backdrop-blur"><Clock className="mr-1 inline h-3.5 w-3.5" />{pkg.duration_days} days · {pkg.duration_nights} nights</span>
      </Link>
      <div className="flex flex-1 flex-col p-5">
        <p className="text-xs font-semibold uppercase tracking-wider text-coral-600">{pkg.cities ?? pkg.destination?.name}</p>
        <h3 className="mt-1 font-display text-lg font-semibold leading-snug text-ink-900"><Link to={`/tours/${pkg.slug}`} className="hover:text-brand-700">{pkg.name}</Link></h3>
        <p className="mt-2 line-clamp-2 text-sm text-ink-600">{pkg.summary}</p>
        <div className="mt-3 flex items-center gap-1.5 text-sm"><Star className="h-4 w-4 fill-sand-400 text-sand-400" /><span className="font-semibold">{pkg.rating_avg.toFixed(1)}</span><span className="text-ink-500">({pkg.review_count})</span></div>
        <div className="mt-auto flex items-end justify-between border-t border-ink-100 pt-4 mt-4">
          <div>
            <p className="text-xs text-ink-500">From, per person</p>
            <p className="text-xl font-bold text-ink-900">{money(pkg.price_from)}</p>
          </div>
          <Link to={`/tours/${pkg.slug}`} className="rounded-xl bg-brand-50 px-4 py-2.5 text-sm font-semibold text-brand-700 transition hover:bg-brand-100">View tour</Link>
        </div>
      </div>
    </article>
  );
}

export function DestinationCard({ d, tall }: { d: Destination; tall?: boolean }) {
  return (
    <Link to={`/destinations/${d.slug}`} className={cn('group relative block overflow-hidden rounded-3xl shadow-soft', tall ? 'h-80 sm:h-full sm:min-h-[22rem]' : 'h-64')}>
      <Img src={d.image_url} alt={`${d.name}, ${d.country}`} className="absolute inset-0 h-full w-full transition duration-700 group-hover:scale-110" />
      <div className="absolute inset-0 bg-gradient-to-t from-ink-950/85 via-ink-950/20 to-transparent" />
      <div className="absolute inset-x-0 bottom-0 p-5 text-white">
        <p className="text-xs font-semibold uppercase tracking-widest text-white/80">{d.country}</p>
        <h3 className="mt-1 font-display text-2xl font-semibold">{d.name}</h3>
        {d.tagline && <p className="mt-1 text-sm text-white/85">{d.tagline}</p>}
        <p className="mt-3 flex flex-wrap gap-x-3 text-xs font-semibold text-white/90">
          {d.hotels_count !== undefined && <span>{d.hotels_count} hotel{d.hotels_count === 1 ? '' : 's'}</span>}
          {d.from_price ? <span>from {money(d.from_price)}/night</span> : null}
        </p>
      </div>
    </Link>
  );
}
