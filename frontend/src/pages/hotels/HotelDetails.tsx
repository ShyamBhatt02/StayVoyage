import { lazy, Suspense, useMemo, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import {
  BedDouble, Check, ChevronLeft, ChevronRight, Clock, Coffee, Crown, Heart, Images, MapPin, Maximize2, PawPrint, Phone, ShieldCheck, Users, CigaretteOff, Baby, Info,
} from 'lucide-react';
import { ApiError, get, getPage, post } from '@/lib/api';
import { addDaysIso, cn, fmtDate, money, nightsBetween, plural } from '@/lib/format';
import { useAuth } from '@/providers/AuthProvider';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { Button } from '@/components/ui/Button';
import { Badge, EmptyState, ErrorState, Img, Modal, Pagination, RatingPill, Skeleton, Spinner, Stars, Avatar } from '@/components/ui/misc';
import { AmenityIcon, HotelCard } from '@/components/cards';
import type { Hotel, HotelCard as HotelCardT, HotelQuote, Review, Room } from '@/types';

const MapView = lazy(() => import('@/components/MapView'));

interface HotelPayload { hotel: Hotel; similar: HotelCardT[]; is_saved: boolean }

export default function HotelDetails() {
  const { slug = '' } = useParams();
  const [params, setParams] = useSearchParams();
  const navigate = useNavigate();
  const { user } = useAuth();
  const qc = useQueryClient();

  const checkIn = params.get('check_in') ?? addDaysIso(7);
  const checkOut = params.get('check_out') ?? addDaysIso(9);
  const guests = Number(params.get('guests') ?? 2);
  const rooms = Number(params.get('rooms') ?? 1);
  const nights = nightsBetween(checkIn, checkOut);

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['public', 'hotel', slug, checkIn, checkOut],
    queryFn: () => get<HotelPayload>(`/hotels/${slug}`, { check_in: checkIn, check_out: checkOut }),
  });
  const hotel = data?.hotel;
  usePageMeta(hotel ? `${hotel.name}, ${hotel.city}` : 'Hotel', hotel?.short_description ?? undefined);

  const [gallery, setGallery] = useState<number | null>(null);
  const [reviewPage, setReviewPage] = useState(1);
  const reviews = useQuery({
    queryKey: ['public', 'hotel-reviews', slug, reviewPage],
    queryFn: () => getPage<Review>(`/hotels/${slug}/reviews`, { page: reviewPage }),
    enabled: !!hotel,
  });

  const save = useMutation({
    mutationFn: () => post<{ saved: boolean }>(`/hotels/${slug}/save`),
    onSuccess: (r) => { toast.success(r.message); qc.invalidateQueries({ queryKey: ['public', 'hotel', slug] }); },
    onError: (e) => toast.error((e as Error).message),
  });

  const setDates = (k: string, v: string) => {
    const next = new URLSearchParams(params);
    next.set('check_in', k === 'check_in' ? v : checkIn);
    next.set('check_out', k === 'check_out' ? v : checkOut);
    if (k === 'check_in' && v >= checkOut) {
      const d = new Date(v + 'T00:00:00');
      d.setDate(d.getDate() + 1);
      next.set('check_out', d.toISOString().slice(0, 10));
    }
    if (k === 'guests' || k === 'rooms') next.set(k, v);
    setParams(next, { replace: true });
  };

  if (isLoading) return <div className="container-page py-10"><Skeleton className="h-[420px] rounded-3xl" /><div className="mt-8 grid gap-6 lg:grid-cols-3"><Skeleton className="h-64 lg:col-span-2" /><Skeleton className="h-64" /></div></div>;
  if (isError || !hotel) {
    return <div className="container-page py-16">{error instanceof ApiError && error.status === 404
      ? <EmptyState title="Hotel not found" description="This property may no longer be listed on StayVoyage." action={<Link to="/hotels" className="link">Browse hotels</Link>} />
      : <ErrorState message={(error as Error)?.message} onRetry={() => refetch()} />}</div>;
  }

  const images = hotel.images ?? [];
  const bookRoom = (room: Room, type: 'membership' | 'regular') => {
    const q = new URLSearchParams({ room: String(room.id), check_in: checkIn, check_out: checkOut, rooms: String(rooms), adults: String(Math.max(rooms, Math.min(guests, room.max_adults * rooms))), children: '0', type });
    const target = `/checkout/hotel?${q.toString()}`;
    if (!user) navigate(`/login?redirect=${encodeURIComponent(target)}`);
    else navigate(target);
  };

  return (
    <div className="pb-10">
      {/* Gallery */}
      <div className="container-page pt-6">
        <nav aria-label="Breadcrumb" className="mb-4 flex items-center gap-1.5 text-sm text-ink-500">
          <Link to="/hotels" className="hover:text-brand-700">Hotels</Link><ChevronRight className="h-3.5 w-3.5" />
          {hotel.destination && <><Link to={`/destinations/${hotel.destination.slug}`} className="hover:text-brand-700">{hotel.destination.name}</Link><ChevronRight className="h-3.5 w-3.5" /></>}
          <span className="truncate text-ink-800">{hotel.name}</span>
        </nav>
        <div className="grid h-[300px] gap-2 overflow-hidden rounded-3xl sm:h-[440px] sm:grid-cols-4 sm:grid-rows-2">
          {images.slice(0, 5).map((img, i) => (
            <button key={img.id} onClick={() => setGallery(i)} className={cn('group relative overflow-hidden', i === 0 ? 'sm:col-span-2 sm:row-span-2' : 'hidden sm:block')} aria-label={`Open photo ${i + 1} of ${images.length}`}>
              <Img src={img.url} alt={img.caption ?? `${hotel.name} photo ${i + 1}`} eager={i === 0} className="h-full w-full transition duration-500 group-hover:scale-105" />
              {i === Math.min(images.length, 5) - 1 && <span className="absolute bottom-3 right-3 flex items-center gap-1.5 rounded-xl bg-white/95 px-3 py-1.5 text-sm font-semibold text-ink-800"><Images className="h-4 w-4" /> All {images.length} photos</span>}
            </button>
          ))}
        </div>
      </div>

      <div className="container-page mt-8 grid gap-10 lg:grid-cols-[1fr_360px]">
        <div className="min-w-0">
          {/* Header */}
          <div className="flex flex-wrap items-start justify-between gap-4">
            <div>
              <div className="flex flex-wrap items-center gap-2">
                <Stars value={hotel.star_rating} label={`${hotel.star_rating}-star ${hotel.property_type}`} />
                <Badge tone="neutral" className="capitalize">{hotel.property_type}</Badge>
                {hotel.is_membership_eligible ? <Badge tone="sand" icon={<Crown className="h-3 w-3" />}>Membership Eligible</Badge> : <Badge tone="neutral">Regular Booking only</Badge>}
              </div>
              <h1 className="mt-3 font-display text-3xl font-semibold text-ink-900 sm:text-4xl">{hotel.name}</h1>
              <p className="mt-2 flex items-start gap-1.5 text-ink-600"><MapPin className="mt-0.5 h-4 w-4 shrink-0" />{hotel.address}, {hotel.city}{hotel.state ? `, ${hotel.state}` : ''}, {hotel.country}</p>
              <RatingPill value={hotel.rating_avg} count={hotel.review_count} className="mt-3" />
            </div>
            {user && (
              <Button variant="outline" onClick={() => save.mutate()} loading={save.isPending} icon={<Heart className={cn('h-4 w-4', data?.is_saved && 'fill-coral-500 text-coral-500')} />}>
                {data?.is_saved ? 'Saved' : 'Save'}
              </Button>
            )}
          </div>

          <section className="mt-8">
            <h2 className="font-display text-2xl font-semibold text-ink-900">About this stay</h2>
            {hotel.description?.split('\n\n').map((p, i) => <p key={i} className="mt-3 leading-relaxed text-ink-700">{p}</p>)}
          </section>

          {hotel.amenities?.length ? (
            <section className="mt-10">
              <h2 className="font-display text-2xl font-semibold text-ink-900">Amenities</h2>
              <ul className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
                {hotel.amenities.map((a) => <li key={a.id} className="flex items-center gap-2.5 text-sm text-ink-700"><span className="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-50 text-brand-600"><AmenityIcon slug={a.slug} /></span>{a.name}</li>)}
              </ul>
            </section>
          ) : null}

          {/* Rooms */}
          <section className="mt-12" id="rooms" aria-labelledby="rooms-h">
            <div className="flex flex-wrap items-end justify-between gap-4">
              <div>
                <h2 id="rooms-h" className="font-display text-2xl font-semibold text-ink-900">Choose your room</h2>
                <p className="mt-1 text-sm text-ink-500">Prices for {fmtDate(checkIn, 'EEE d MMM')} – {fmtDate(checkOut, 'EEE d MMM')} · {plural(nights, 'night')}</p>
              </div>
            </div>
            <div className="card mt-4 grid gap-3 p-4 sm:grid-cols-4">
              <label className="text-xs font-semibold text-ink-500">Check-in<input type="date" className="input mt-1" min={addDaysIso(0)} value={checkIn} onChange={(e) => setDates('check_in', e.target.value)} /></label>
              <label className="text-xs font-semibold text-ink-500">Check-out<input type="date" className="input mt-1" min={checkIn} value={checkOut} onChange={(e) => setDates('check_out', e.target.value)} /></label>
              <label className="text-xs font-semibold text-ink-500">Guests<select className="input mt-1" value={guests} onChange={(e) => setDates('guests', e.target.value)}>{Array.from({ length: 10 }, (_, i) => <option key={i} value={i + 1}>{i + 1}</option>)}</select></label>
              <label className="text-xs font-semibold text-ink-500">Rooms<select className="input mt-1" value={rooms} onChange={(e) => setDates('rooms', e.target.value)}>{Array.from({ length: 5 }, (_, i) => <option key={i} value={i + 1}>{i + 1}</option>)}</select></label>
            </div>
            <div className="mt-5 space-y-5">
              {hotel.rooms?.map((room) => <RoomCard key={room.id} room={room} hotel={hotel} nights={nights} rooms={rooms} guests={guests} checkIn={checkIn} checkOut={checkOut} onBook={bookRoom} />)}
            </div>
          </section>

          {/* Policies */}
          {hotel.policy && (
            <section className="mt-12">
              <h2 className="font-display text-2xl font-semibold text-ink-900">House rules & policies</h2>
              <dl className="mt-4 grid gap-4 sm:grid-cols-2">
                <Policy icon={<Clock className="h-5 w-5" />} title="Check-in / check-out">Check-in from {hotel.check_in_time} · Check-out until {hotel.check_out_time}</Policy>
                <Policy icon={<ShieldCheck className="h-5 w-5" />} title="Cancellation">{hotel.policy.cancellation_policy}</Policy>
                <Policy icon={<Baby className="h-5 w-5" />} title="Children">{hotel.policy.children_policy}</Policy>
                <Policy icon={<PawPrint className="h-5 w-5" />} title="Pets">{hotel.policy.pet_policy}</Policy>
                <Policy icon={<CigaretteOff className="h-5 w-5" />} title="Smoking">{hotel.policy.smoking_policy}</Policy>
                <Policy icon={<Info className="h-5 w-5" />} title="Good to know">{hotel.policy.extra_policies}</Policy>
              </dl>
            </section>
          )}

          {/* Map */}
          {hotel.latitude && hotel.longitude && (
            <section className="mt-12">
              <h2 className="font-display text-2xl font-semibold text-ink-900">Location</h2>
              <p className="mt-1 text-sm text-ink-500">{hotel.address}, {hotel.city}{hotel.distance_from_center_km !== null ? ` · ${hotel.distance_from_center_km} km from the city centre` : ''}</p>
              <Suspense fallback={<Skeleton className="mt-4 h-72 rounded-2xl" />}>
                <MapView className="mt-4 h-72 overflow-hidden rounded-2xl" points={[{ lat: hotel.latitude, lng: hotel.longitude, label: hotel.name, sub: hotel.city }]} />
              </Suspense>
            </section>
          )}

          {/* Reviews */}
          <section className="mt-12" aria-labelledby="reviews-h">
            <h2 id="reviews-h" className="font-display text-2xl font-semibold text-ink-900">Guest reviews</h2>
            {hotel.rating_breakdown && hotel.review_count > 0 && (
              <div className="card mt-4 grid gap-6 p-5 sm:grid-cols-[180px_1fr]">
                <div className="text-center sm:border-r sm:border-ink-100">
                  <p className="text-5xl font-bold text-brand-700">{hotel.rating_avg.toFixed(1)}</p>
                  <Stars value={hotel.rating_avg} className="mt-2" />
                  <p className="mt-1 text-sm text-ink-500">{plural(hotel.review_count, 'verified review')}</p>
                </div>
                <div className="grid gap-3 sm:grid-cols-2">
                  {(['cleanliness', 'location', 'service', 'value'] as const).map((k) => (
                    <div key={k}>
                      <div className="flex justify-between text-sm"><span className="capitalize text-ink-700">{k}</span><span className="font-semibold">{Number(hotel.rating_breakdown?.[k] ?? 0).toFixed(1)}</span></div>
                      <div className="mt-1.5 h-2 rounded-full bg-ink-100"><div className="h-2 rounded-full bg-brand-500" style={{ width: `${(Number(hotel.rating_breakdown?.[k] ?? 0) / 5) * 100}%` }} /></div>
                    </div>
                  ))}
                </div>
              </div>
            )}
            <div className="mt-5 space-y-4">
              {reviews.isLoading ? <Spinner /> : !reviews.data?.items.length ? (
                <EmptyState title="No reviews yet" description="Be among the first to stay and share your experience." />
              ) : reviews.data.items.map((r) => (
                <article key={r.id} className="card p-5">
                  <div className="flex items-start justify-between gap-3">
                    <div className="flex items-center gap-3">
                      <Avatar name={r.author?.name} src={r.author?.avatar_url} />
                      <div><p className="font-semibold text-ink-900">{r.author?.name}</p><p className="text-xs text-ink-500">Stayed {r.stay_month}</p></div>
                    </div>
                    <span className="rounded-lg bg-brand-700 px-2 py-0.5 text-sm font-bold text-white">{r.overall}.0</span>
                  </div>
                  <h3 className="mt-3 font-sans font-semibold text-ink-900">{r.title}</h3>
                  <p className="mt-1.5 text-sm leading-relaxed text-ink-600">{r.comment}</p>
                </article>
              ))}
              <Pagination meta={reviews.data?.pagination} onPage={setReviewPage} />
            </div>
          </section>
        </div>

        {/* Sticky summary */}
        <aside className="lg:sticky lg:top-24 lg:self-start">
          <div className="card p-6">
            <p className="text-sm text-ink-500">Regular price from</p>
            <p className="text-3xl font-bold text-ink-900">{money(hotel.from_price)}<span className="text-base font-medium text-ink-500"> / night</span></p>
            {hotel.is_membership_eligible && (
              <div className="mt-4 rounded-2xl bg-sand-50 p-4 ring-1 ring-sand-200">
                <p className="flex items-center gap-2 font-semibold text-sand-800"><Crown className="h-4 w-4" /> Membership eligible</p>
                <p className="mt-1 text-sm text-sand-900/80">Members can cover the room rate with night credits on eligible rooms — you pay only taxes and a booking fee.</p>
                {!user && <Link to="/membership" className="mt-2 inline-block text-sm font-semibold text-sand-800 underline">How membership works</Link>}
              </div>
            )}
            <a href="#rooms" className="mt-5 flex h-11 items-center justify-center rounded-xl bg-coral-500 font-semibold text-white transition hover:bg-coral-600">See available rooms</a>
            <ul className="mt-5 space-y-2 text-sm text-ink-600">
              <li className="flex items-center gap-2"><Check className="h-4 w-4 text-success-600" /> Instant confirmation after payment</li>
              <li className="flex items-center gap-2"><Check className="h-4 w-4 text-success-600" /> Free cancellation on most rooms</li>
              {hotel.phone && <li className="flex items-center gap-2"><Phone className="h-4 w-4 text-ink-400" /> {hotel.phone}</li>}
            </ul>
          </div>
        </aside>
      </div>

      {data?.similar.length ? (
        <section className="container-page mt-16">
          <h2 className="font-display text-2xl font-semibold text-ink-900">More stays in {hotel.destination?.name ?? hotel.city}</h2>
          <div className="mt-6 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">{data.similar.map((h) => <HotelCard key={h.id} hotel={h} />)}</div>
        </section>
      ) : null}

      <Modal open={gallery !== null} onClose={() => setGallery(null)} title={hotel.name} size="xl">
        {gallery !== null && images[gallery] && (
          <div>
            <div className="relative">
              <Img src={images[gallery].url} alt={`${hotel.name} photo ${gallery + 1}`} className="h-[55vh] w-full rounded-2xl" />
              <button onClick={() => setGallery((g) => ((g ?? 0) - 1 + images.length) % images.length)} className="absolute left-3 top-1/2 -translate-y-1/2 rounded-full bg-white/90 p-2 shadow" aria-label="Previous photo"><ChevronLeft className="h-5 w-5" /></button>
              <button onClick={() => setGallery((g) => ((g ?? 0) + 1) % images.length)} className="absolute right-3 top-1/2 -translate-y-1/2 rounded-full bg-white/90 p-2 shadow" aria-label="Next photo"><ChevronRight className="h-5 w-5" /></button>
            </div>
            <p className="mt-3 text-center text-sm text-ink-500">{gallery + 1} / {images.length}</p>
          </div>
        )}
      </Modal>
    </div>
  );
}

function Policy({ icon, title, children }: { icon: React.ReactNode; title: string; children: React.ReactNode }) {
  if (!children) return null;
  return (
    <div className="flex gap-3 rounded-2xl border border-ink-200 bg-white p-4">
      <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600">{icon}</span>
      <div><dt className="font-semibold text-ink-900">{title}</dt><dd className="mt-1 text-sm text-ink-600">{children}</dd></div>
    </div>
  );
}

function RoomCard({ room, hotel, nights, rooms, guests, checkIn, checkOut, onBook }: {
  room: Room; hotel: Hotel; nights: number; rooms: number; guests: number; checkIn: string; checkOut: string; onBook: (r: Room, t: 'membership' | 'regular') => void;
}) {
  const { user } = useAuth();
  const [photo, setPhoto] = useState(0);
  const available = room.available_count ?? null;
  const fits = guests <= room.max_guests * rooms;
  const soldOut = available !== null && available < rooms;
  const memberEligible = hotel.is_membership_eligible && room.is_membership_eligible;
  const stay = room.stay_price ?? room.base_price * nights;
  const quote = useQuery({
    queryKey: ['quote', room.id, checkIn, checkOut, rooms, 'membership', user?.id],
    queryFn: () => post<HotelQuote>('/hotels/quote', { hotel_room_id: room.id, check_in: checkIn, check_out: checkOut, rooms, adults: Math.max(rooms, Math.min(guests, room.max_adults * rooms)), children: 0, booking_type: 'membership' }).then((r) => r.data),
    enabled: !!user && memberEligible && !soldOut && fits,
    retry: false,
  });
  const images = room.images ?? [];
  const memberMsg = useMemo(() => {
    if (!memberEligible) return null;
    if (!user) return 'Log in as a member to use night credits';
    if (quote.isLoading) return 'Checking your membership…';
    if (quote.isError) return (quote.error as Error).message;
    if (quote.data) return `Pay ${money(quote.data.total_amount)} (taxes & fee) using ${plural(quote.data.membership_nights_used, 'night')}`;
    return null;
  }, [memberEligible, user, quote.isLoading, quote.isError, quote.error, quote.data]);

  return (
    <article className="card overflow-hidden md:grid md:grid-cols-[280px_1fr]">
      <div className="relative h-56 md:h-full">
        <Img src={images[photo]?.url} alt={`${room.name} at ${hotel.name}`} className="h-full w-full" />
        {images.length > 1 && (
          <div className="absolute bottom-3 left-1/2 flex -translate-x-1/2 gap-1.5">
            {images.map((_, i) => <button key={i} onClick={() => setPhoto(i)} aria-label={`Show room photo ${i + 1}`} className={cn('h-2 w-2 rounded-full', i === photo ? 'bg-white' : 'bg-white/50')} />)}
          </div>
        )}
      </div>
      <div className="flex flex-col p-5">
        <div className="flex flex-wrap items-start justify-between gap-2">
          <h3 className="font-display text-xl font-semibold text-ink-900">{room.name}</h3>
          {memberEligible ? <Badge tone="sand" icon={<Crown className="h-3 w-3" />}>Membership Eligible</Badge> : <Badge tone="neutral">Regular Booking</Badge>}
        </div>
        <ul className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm text-ink-600">
          <li className="flex items-center gap-1.5"><Users className="h-4 w-4" /> Sleeps {room.max_guests}</li>
          <li className="flex items-center gap-1.5"><BedDouble className="h-4 w-4" /> {room.bed_type} bed</li>
          {room.size_sqft && <li className="flex items-center gap-1.5"><Maximize2 className="h-4 w-4" /> {room.size_sqft} sq ft</li>}
          {room.breakfast_included && <li className="flex items-center gap-1.5 text-success-700"><Coffee className="h-4 w-4" /> Breakfast included</li>}
        </ul>
        <p className="mt-3 text-sm text-ink-600">{room.description}</p>
        {room.amenities && <p className="mt-2 text-xs text-ink-500">{room.amenities.map((a) => a.name).join(' · ')}</p>}
        <p className={cn('mt-3 flex items-start gap-1.5 text-sm font-medium', room.free_cancellation ? 'text-success-700' : 'text-ink-600')}><ShieldCheck className="mt-0.5 h-4 w-4 shrink-0" />{room.cancellation_policy}</p>

        <div className="mt-5 grid gap-4 border-t border-ink-100 pt-4 sm:grid-cols-2">
          <div className="rounded-2xl border border-ink-200 p-4">
            <p className="text-xs font-bold uppercase tracking-wider text-ink-500">Regular booking</p>
            <p className="mt-1 text-2xl font-bold text-ink-900">{money(stay * rooms)}</p>
            <p className="text-xs text-ink-500">{money(stay / Math.max(1, nights))} avg/night × {plural(rooms, 'room')} · + taxes & fees</p>
            <Button className="mt-3 w-full" disabled={soldOut || !fits || nights < 1} onClick={() => onBook(room, 'regular')}>
              {soldOut ? 'Sold out for these dates' : !fits ? 'Too many guests' : 'Book now'}
            </Button>
          </div>
          {memberEligible ? (
            <div className="rounded-2xl bg-sand-50 p-4 ring-1 ring-sand-200">
              <p className="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-sand-800"><Crown className="h-3.5 w-3.5" /> Membership booking</p>
              <p className="mt-1 text-sm font-medium text-sand-900">{memberMsg}</p>
              <Button variant="accent" className="mt-3 w-full" disabled={soldOut || !fits || nights < 1 || (!!user && !quote.data)} onClick={() => onBook(room, 'membership')}>
                {!user ? 'Log in to use nights' : 'Book with membership'}
              </Button>
            </div>
          ) : (
            <div className="flex items-center rounded-2xl bg-ink-50 p-4 text-sm text-ink-500">This room type isn't part of the membership programme — book it as a regular stay.</div>
          )}
        </div>
        {available !== null && !soldOut && available <= 5 && <p className="mt-3 text-sm font-semibold text-coral-600">Only {available} room{available === 1 ? '' : 's'} left at this price</p>}
      </div>
    </article>
  );
}
