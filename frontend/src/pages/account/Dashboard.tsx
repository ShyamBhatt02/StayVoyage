import { Link } from 'react-router-dom';
import { useMutation, useQuery } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Bell, CalendarCheck, Crown, Heart, Hotel, MailWarning, Map, Route, Search, Wallet } from 'lucide-react';
import { get, post } from '@/lib/api';
import { fmtDate, fromNow, money } from '@/lib/format';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { Button, ButtonLink } from '@/components/ui/Button';
import { EmptyState, ErrorState, Skeleton, StatusBadge } from '@/components/ui/misc';
import { BookingRow, LedgerTable, MembershipCard } from '@/components/account';
import type { AppNotification, Booking, Membership, MembershipTransaction, Payment, Trip } from '@/types';

interface DashboardData {
  user: { name: string; first_name: string; email_verified: boolean };
  membership: Membership | null;
  stats: { total_bookings: number; nights_stayed: number; saved_trips: number; saved_hotels: number };
  upcoming_hotel_bookings: Booking[];
  upcoming_tours: Booking[];
  recent_bookings: Booking[];
  recent_payments: Payment[];
  membership_transactions: MembershipTransaction[];
  saved_trips: Trip[];
  notifications: AppNotification[];
  unread_notifications: number;
}

const ACTIONS = [
  { to: '/hotels', label: 'Search Hotels', icon: Search },
  { to: '/hotels?membership=1', label: 'Book Hotel', icon: Hotel },
  { to: '/tour-builder', label: 'Build Tour', icon: Route },
  { to: '/account/bookings', label: 'My Bookings', icon: CalendarCheck },
  { to: '/account/membership', label: 'Membership', icon: Crown },
  { to: '/account/trips', label: 'Saved Trips', icon: Map },
];

export default function Dashboard() {
  usePageMeta('My dashboard');
  const { data, isLoading, isError, refetch } = useQuery({ queryKey: ['dashboard'], queryFn: () => get<DashboardData>('/dashboard') });
  const resend = useMutation({ mutationFn: () => post('/auth/email/resend'), onSuccess: (r) => toast.success(r.message), onError: (e) => toast.error((e as Error).message) });

  if (isError) return <ErrorState onRetry={() => refetch()} />;
  if (isLoading || !data) return <div className="space-y-6"><Skeleton className="h-10 w-64" /><Skeleton className="h-52 rounded-3xl" /><Skeleton className="h-40" /></div>;

  return (
    <div className="space-y-8">
      <div>
        <h1 className="font-display text-3xl font-semibold text-ink-900">Hello, {data.user.first_name} 👋</h1>
        <p className="mt-1 text-ink-500">Here's everything about your stays, trips and membership.</p>
      </div>

      {!data.user.email_verified && (
        <div role="alert" className="flex flex-wrap items-center gap-3 rounded-2xl bg-warning-50 p-4 text-sm text-warning-700 ring-1 ring-warning-600/20">
          <MailWarning className="h-5 w-5" />
          <p className="flex-1">Please verify your email address to receive booking confirmations.</p>
          <Button size="sm" variant="outline" loading={resend.isPending} onClick={() => resend.mutate()}>Resend link</Button>
        </div>
      )}

      {/* Membership */}
      {data.membership ? (
        <div className="grid gap-6 xl:grid-cols-[1.4fr_1fr]">
          <MembershipCard m={data.membership} />
          <div className="card flex flex-col p-5">
            <div className="flex items-center justify-between"><h2 className="font-sans font-bold text-ink-900">Membership transactions</h2><Link to="/account/membership" className="text-sm font-semibold text-brand-700 hover:underline">Full ledger</Link></div>
            {data.membership_transactions.length ? (
              <ul className="mt-3 divide-y divide-ink-100">
                {data.membership_transactions.slice(0, 5).map((t) => (
                  <li key={t.id} className="flex items-center justify-between gap-3 py-2.5 text-sm">
                    <div className="min-w-0"><p className="truncate font-medium text-ink-800">{t.type_label}</p><p className="truncate text-xs text-ink-500">{t.booking_number ?? fmtDate(t.created_at)}</p></div>
                    <span className={`font-bold tabular-nums ${t.nights > 0 ? 'text-success-700' : 'text-coral-600'}`}>{t.nights > 0 ? `+${t.nights}` : t.nights}</span>
                  </li>
                ))}
              </ul>
            ) : <p className="mt-3 text-sm text-ink-500">No transactions yet.</p>}
          </div>
        </div>
      ) : (
        <div className="flex flex-wrap items-center gap-5 rounded-3xl bg-sand-50 p-6 ring-1 ring-sand-200">
          <Crown className="h-10 w-10 text-sand-600" />
          <div className="flex-1"><p className="font-display text-xl font-semibold text-ink-900">Get up to 30 hotel nights a month</p><p className="text-sm text-ink-600">Join StayVoyage Membership and pay only taxes & fees on eligible stays.</p></div>
          <ButtonLink to="/membership" variant="accent">Explore membership</ButtonLink>
        </div>
      )}

      {/* Quick actions */}
      <section aria-labelledby="qa-h">
        <h2 id="qa-h" className="sr-only">Quick actions</h2>
        <div className="grid grid-cols-3 gap-3 sm:grid-cols-6">
          {ACTIONS.map((a) => (
            <Link key={a.label} to={a.to} className="card flex flex-col items-center gap-2 p-4 text-center transition hover:-translate-y-0.5 hover:shadow-lift">
              <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-50 text-brand-600"><a.icon className="h-5 w-5" /></span>
              <span className="text-xs font-semibold text-ink-700 sm:text-sm">{a.label}</span>
            </Link>
          ))}
        </div>
      </section>

      <div className="grid gap-4 sm:grid-cols-4">
        {[['Confirmed bookings', data.stats.total_bookings], ['Nights stayed', data.stats.nights_stayed], ['Saved trips', data.stats.saved_trips], ['Wishlist hotels', data.stats.saved_hotels]].map(([l, v]) => (
          <div key={l as string} className="card p-4"><p className="text-xs font-medium text-ink-500">{l}</p><p className="mt-1 text-2xl font-bold text-ink-900">{v}</p></div>
        ))}
      </div>

      <div className="grid gap-8 xl:grid-cols-2">
        <section>
          <h2 className="mb-3 font-display text-xl font-semibold text-ink-900">Upcoming hotel bookings</h2>
          <div className="space-y-3">
            {data.upcoming_hotel_bookings.length ? data.upcoming_hotel_bookings.map((b) => <BookingRow key={b.id} b={b} />)
              : <EmptyState icon={<Hotel className="h-7 w-7" />} title="No upcoming stays" description="Your next getaway is a search away." action={<ButtonLink to="/hotels" size="sm">Search hotels</ButtonLink>} className="py-8" />}
          </div>
        </section>
        <section>
          <h2 className="mb-3 font-display text-xl font-semibold text-ink-900">Upcoming tours</h2>
          <div className="space-y-3">
            {data.upcoming_tours.length ? data.upcoming_tours.map((b) => <BookingRow key={b.id} b={b} />)
              : <EmptyState icon={<Route className="h-7 w-7" />} title="No trips booked" description="Design a day-by-day trip in the Tour Builder." action={<ButtonLink to="/tour-builder" size="sm">Build a trip</ButtonLink>} className="py-8" />}
          </div>
        </section>
      </div>

      <section>
        <div className="mb-3 flex items-center justify-between"><h2 className="font-display text-xl font-semibold text-ink-900">Recent bookings</h2><Link to="/account/bookings" className="text-sm font-semibold text-brand-700 hover:underline">View all</Link></div>
        <div className="space-y-3">{data.recent_bookings.map((b) => <BookingRow key={b.id} b={b} />)}</div>
      </section>

      <div className="grid gap-8 xl:grid-cols-2">
        <section className="card p-5">
          <div className="mb-3 flex items-center justify-between"><h2 className="flex items-center gap-2 font-sans font-bold text-ink-900"><Wallet className="h-4 w-4 text-brand-600" /> Recent payments</h2><Link to="/account/payments" className="text-sm font-semibold text-brand-700 hover:underline">All payments</Link></div>
          <ul className="divide-y divide-ink-100">
            {data.recent_payments.map((p) => (
              <li key={p.id} className="flex items-center justify-between gap-3 py-3 text-sm">
                <div className="min-w-0"><p className="truncate font-medium text-ink-800">{p.payable_reference ?? p.reference}</p><p className="text-xs text-ink-500">{fmtDate(p.paid_at ?? p.created_at)} · {p.method?.toUpperCase() ?? p.gateway}</p></div>
                <div className="text-right"><p className="font-bold text-ink-900">{money(p.amount)}</p><StatusBadge status={p.status} label={p.status_label} /></div>
              </li>
            ))}
            {!data.recent_payments.length && <li className="py-3 text-sm text-ink-500">No payments yet.</li>}
          </ul>
        </section>
        <section className="card p-5">
          <div className="mb-3 flex items-center justify-between"><h2 className="flex items-center gap-2 font-sans font-bold text-ink-900"><Bell className="h-4 w-4 text-brand-600" /> Notifications {data.unread_notifications > 0 && <span className="rounded-full bg-coral-500 px-2 py-0.5 text-xs text-white">{data.unread_notifications}</span>}</h2><Link to="/account/notifications" className="text-sm font-semibold text-brand-700 hover:underline">See all</Link></div>
          <ul className="divide-y divide-ink-100">
            {data.notifications.map((n) => (
              <li key={n.id} className="py-3">
                <Link to={n.action_path ?? '/account/notifications'} className="block">
                  <p className={`text-sm ${n.read_at ? 'text-ink-700' : 'font-semibold text-ink-900'}`}>{n.title}</p>
                  <p className="line-clamp-1 text-xs text-ink-500">{n.message}</p>
                  <p className="mt-0.5 text-[11px] text-ink-400">{fromNow(n.created_at)}</p>
                </Link>
              </li>
            ))}
            {!data.notifications.length && <li className="py-3 text-sm text-ink-500">You're all caught up.</li>}
          </ul>
        </section>
      </div>

      {data.saved_trips.length > 0 && (
        <section>
          <div className="mb-3 flex items-center justify-between"><h2 className="font-display text-xl font-semibold text-ink-900">Saved trips</h2><Link to="/account/trips" className="text-sm font-semibold text-brand-700 hover:underline">All trips</Link></div>
          <div className="grid gap-4 sm:grid-cols-3">
            {data.saved_trips.map((t) => (
              <Link key={t.id} to={`/tour-builder/${t.id}`} className="card p-4 transition hover:shadow-lift">
                <div className="flex items-center justify-between"><StatusBadge status={t.status === 'booked' ? 'confirmed' : 'pending'} label={t.status === 'booked' ? 'Booked' : 'Draft'} /><Heart className="h-4 w-4 text-coral-400" /></div>
                <p className="mt-2 font-semibold text-ink-900">{t.title}</p>
                <p className="text-sm text-ink-500">{t.duration_days} days · {t.travelers} travellers{t.start_date ? ` · ${fmtDate(t.start_date, 'd MMM')}` : ''}</p>
                <p className="mt-2 font-bold text-ink-900">{money(t.total_amount)}</p>
              </Link>
            ))}
          </div>
        </section>
      )}

      {data.membership && data.membership_transactions.length > 5 && (
        <section className="card p-5"><h2 className="mb-2 font-sans font-bold">All recent ledger entries</h2><LedgerTable rows={data.membership_transactions} /></section>
      )}
    </div>
  );
}
