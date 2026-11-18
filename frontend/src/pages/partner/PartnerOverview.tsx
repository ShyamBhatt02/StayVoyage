import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ArrowRight, Hotel as HotelIcon } from 'lucide-react';
import { getPage } from '@/lib/api';
import { money } from '@/lib/format';
import { useAuth } from '@/providers/AuthProvider';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { EmptyState, ErrorState, Img, Skeleton, Stars, StatusBadge } from '@/components/ui/misc';
import { AdminHeader } from '@/components/admin/kit';
import { BookingRow } from '@/components/account';
import type { Booking, Hotel } from '@/types';

export default function PartnerOverview() {
  usePageMeta('Partner portal');
  const { user } = useAuth();
  const hotels = useQuery({ queryKey: ['partner', 'hotels'], queryFn: () => getPage<Hotel>('/partner/hotels') });
  const bookings = useQuery({ queryKey: ['partner', 'bookings', 'recent'], queryFn: () => getPage<Booking>('/partner/bookings', { status: 'confirmed' }) });
  return (
    <div className="space-y-8">
      <AdminHeader title={`Welcome, ${user?.name.split(' ')[0]}`} description="Manage your property's rooms, photos, prices, availability and bookings." />
      <section>
        <h2 className="mb-3 font-display text-xl font-semibold">Your properties</h2>
        {hotels.isError ? <ErrorState onRetry={() => hotels.refetch()} /> : hotels.isLoading ? <Skeleton className="h-40 rounded-2xl" /> : !hotels.data?.items.length ? (
          <EmptyState icon={<HotelIcon className="h-7 w-7" />} title="No hotels linked to your account" description="Ask the StayVoyage team to link your property." />
        ) : (
          <div className="grid gap-5 md:grid-cols-2">
            {hotels.data.items.map((h) => (
              <Link key={h.id} to={`/partner/hotels/${h.id}`} className="card flex gap-4 p-4 transition hover:shadow-lift">
                <Img src={h.cover_url} alt="" className="h-28 w-36 shrink-0 rounded-xl" />
                <div className="min-w-0 flex-1">
                  <Stars value={h.star_rating} size="h-3 w-3" />
                  <p className="mt-1 font-display text-lg font-semibold">{h.name}</p>
                  <p className="text-sm text-ink-500">{h.city} · from {money(h.from_price)} · {h.rating_avg.toFixed(1)}★ ({h.review_count})</p>
                  <div className="mt-2 flex items-center justify-between"><StatusBadge status={h.status ?? 'active'} /><span className="flex items-center gap-1 text-sm font-semibold text-brand-700">Manage <ArrowRight className="h-4 w-4" /></span></div>
                </div>
              </Link>
            ))}
          </div>
        )}
      </section>
      <section>
        <div className="mb-3 flex items-center justify-between"><h2 className="font-display text-xl font-semibold">Confirmed bookings</h2><Link to="/partner/bookings" className="link text-sm">All bookings</Link></div>
        {bookings.isLoading ? <Skeleton className="h-32" /> : bookings.data?.items.length ? <div className="space-y-3">{bookings.data.items.slice(0, 6).map((b) => <BookingRow key={b.id} b={b} linkBase="/partner/bookings" />)}</div> : <p className="text-ink-500">No confirmed bookings yet.</p>}
      </section>
    </div>
  );
}
