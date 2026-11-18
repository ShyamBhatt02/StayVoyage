import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Area, AreaChart, Bar, BarChart, CartesianGrid, Cell, Legend, Line, LineChart, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { BedDouble, CalendarCheck, Crown, Hotel, IndianRupee, MessageSquare, RotateCcw, Route, Star, Users } from 'lucide-react';
import { get } from '@/lib/api';
import { fmtDate, money } from '@/lib/format';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { ErrorState, Skeleton, Stat } from '@/components/ui/misc';
import { AdminHeader } from '@/components/admin/kit';
import { BookingRow } from '@/components/account';
import type { Booking } from '@/types';

// Chart colours come from the brand tokens (CSS variables) — no one-off colours.
const C = { brand: 'rgb(var(--brand-600))', coral: 'rgb(var(--coral-500))', sand: 'rgb(var(--sand-400))', brandLight: 'rgb(var(--brand-300))', ink: 'rgb(var(--ink-400))' };
const PIE = [C.brand, C.coral, C.sand, C.brandLight, 'rgb(var(--brand-800))', C.ink];

interface Dash {
  stats: Record<string, number>;
  charts: {
    revenue_trend: { month: string; hotel: number; tour: number; membership: number }[];
    booking_trend: { date: string; hotel: number; tour: number }[];
    membership_sales: { month: string; sold: number }[];
    hotel_performance: { name: string; bookings: number; room_nights: number; revenue: number }[];
    popular_destinations: { name: string; bookings: number }[];
    tour_sales: { month: string; revenue: number }[];
  };
  upcoming: Booking[];
}

const mlabel = (m: string) => fmtDate(m + '-01', 'MMM yy');
const short = (v: number) => (v >= 1e5 ? `₹${(v / 1e5).toFixed(1)}L` : v >= 1e3 ? `₹${Math.round(v / 1e3)}k` : `₹${v}`);

export default function AdminDashboard() {
  usePageMeta('Admin dashboard');
  const { data, isLoading, isError, refetch } = useQuery({ queryKey: ['admin', 'dashboard'], queryFn: () => get<Dash>('/admin/dashboard'), refetchInterval: 120_000 });
  if (isError) return <ErrorState onRetry={() => refetch()} />;
  if (isLoading || !data) return <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">{Array.from({ length: 8 }).map((_, i) => <Skeleton key={i} className="h-28 rounded-2xl" />)}</div>;
  const s = data.stats;

  return (
    <div className="space-y-8">
      <AdminHeader title="Dashboard" description="Live overview of sales, members and inventory." />
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Stat label="Total revenue (net)" value={money(s.total_revenue)} icon={<IndianRupee className="h-5 w-5" />} hint={`Hotels ${money(s.hotel_revenue)} · Tours ${money(s.tour_revenue)}`} />
        <Stat label="Membership sales" value={s.membership_sales} icon={<Crown className="h-5 w-5" />} tone="sand" hint={`${money(s.membership_revenue)} revenue · ${s.active_members} active members`} />
        <Stat label="Hotel bookings" value={s.hotel_bookings} icon={<CalendarCheck className="h-5 w-5" />} hint={`${s.upcoming_bookings} upcoming · ${s.pending_payments} awaiting payment`} />
        <Stat label="Tour bookings" value={s.tour_bookings} icon={<Route className="h-5 w-5" />} tone="coral" hint={`${money(s.tour_revenue)} revenue`} />
        <Stat label="Customers" value={s.total_users} icon={<Users className="h-5 w-5" />} tone="ink" />
        <Stat label="Hotels" value={`${s.active_hotels} / ${s.total_hotels}`} icon={<Hotel className="h-5 w-5" />} hint="active / total" tone="ink" />
        <Stat label="Room inventory" value={s.total_rooms} icon={<BedDouble className="h-5 w-5" />} hint="rooms across active room types" tone="ink" />
        <Stat label="Refunds" value={money(s.refunds)} icon={<RotateCcw className="h-5 w-5" />} hint={`${s.refund_count} processed`} tone="coral" />
      </div>
      {(s.pending_reviews > 0 || s.new_messages > 0) && (
        <div className="flex flex-wrap gap-3">
          {s.pending_reviews > 0 && <Link to="/admin/reviews?status=pending" className="chip"><Star className="h-4 w-4 text-sand-500" /> {s.pending_reviews} review(s) awaiting moderation</Link>}
          {s.new_messages > 0 && <Link to="/admin/content" className="chip"><MessageSquare className="h-4 w-4 text-brand-600" /> {s.new_messages} new contact message(s)</Link>}
        </div>
      )}

      <div className="grid gap-6 xl:grid-cols-2">
        <ChartCard title="Revenue trends" subtitle="Net of refunds, last 12 months">
          <ResponsiveContainer width="100%" height={280}>
            <BarChart data={data.charts.revenue_trend.map((r) => ({ ...r, month: mlabel(r.month) }))}>
              <CartesianGrid strokeDasharray="3 3" stroke="rgb(var(--ink-200))" vertical={false} />
              <XAxis dataKey="month" tick={{ fontSize: 12 }} /><YAxis tickFormatter={short} tick={{ fontSize: 12 }} width={60} />
              <Tooltip formatter={(v) => money(Number(v))} /><Legend />
              <Bar dataKey="hotel" name="Hotels" stackId="a" fill={C.brand} radius={[0, 0, 0, 0]} />
              <Bar dataKey="tour" name="Tours" stackId="a" fill={C.coral} />
              <Bar dataKey="membership" name="Memberships" stackId="a" fill={C.sand} radius={[6, 6, 0, 0]} />
            </BarChart>
          </ResponsiveContainer>
        </ChartCard>
        <ChartCard title="Booking trends" subtitle="New bookings per day, last 30 days">
          <ResponsiveContainer width="100%" height={280}>
            <AreaChart data={data.charts.booking_trend.map((r) => ({ ...r, date: fmtDate(r.date, 'd MMM') }))}>
              <CartesianGrid strokeDasharray="3 3" stroke="rgb(var(--ink-200))" vertical={false} />
              <XAxis dataKey="date" tick={{ fontSize: 11 }} interval={4} /><YAxis allowDecimals={false} tick={{ fontSize: 12 }} width={30} />
              <Tooltip /><Legend />
              <Area type="monotone" dataKey="hotel" name="Hotel" stroke={C.brand} fill={C.brand} fillOpacity={0.15} strokeWidth={2} />
              <Area type="monotone" dataKey="tour" name="Tour" stroke={C.coral} fill={C.coral} fillOpacity={0.15} strokeWidth={2} />
            </AreaChart>
          </ResponsiveContainer>
        </ChartCard>
        <ChartCard title="Membership sales" subtitle="Memberships activated per month">
          <ResponsiveContainer width="100%" height={260}>
            <BarChart data={data.charts.membership_sales.map((r) => ({ ...r, month: mlabel(r.month) }))}>
              <CartesianGrid strokeDasharray="3 3" stroke="rgb(var(--ink-200))" vertical={false} />
              <XAxis dataKey="month" tick={{ fontSize: 12 }} /><YAxis allowDecimals={false} tick={{ fontSize: 12 }} width={30} />
              <Tooltip /><Bar dataKey="sold" name="Memberships" fill={C.sand} radius={[6, 6, 0, 0]} />
            </BarChart>
          </ResponsiveContainer>
        </ChartCard>
        <ChartCard title="Hotel performance" subtitle="Top hotels by room nights sold">
          <ResponsiveContainer width="100%" height={260}>
            <BarChart data={data.charts.hotel_performance} layout="vertical" margin={{ left: 10 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="rgb(var(--ink-200))" horizontal={false} />
              <XAxis type="number" tick={{ fontSize: 12 }} /><YAxis type="category" dataKey="name" width={150} tick={{ fontSize: 11 }} />
              <Tooltip formatter={(v, n) => (n === "Revenue" ? money(Number(v)) : String(v))} />
              <Bar dataKey="room_nights" name="Room nights" fill={C.brand} radius={[0, 6, 6, 0]} />
            </BarChart>
          </ResponsiveContainer>
        </ChartCard>
        <ChartCard title="Popular destinations" subtitle="Confirmed hotel bookings">
          <ResponsiveContainer width="100%" height={260}>
            <PieChart>
              <Pie data={data.charts.popular_destinations} dataKey="bookings" nameKey="name" innerRadius={55} outerRadius={95} paddingAngle={2}>
                {data.charts.popular_destinations.map((_, i) => <Cell key={i} fill={PIE[i % PIE.length]} />)}
              </Pie>
              <Tooltip /><Legend />
            </PieChart>
          </ResponsiveContainer>
        </ChartCard>
        <ChartCard title="Tour sales" subtitle="Tour booking revenue per month">
          <ResponsiveContainer width="100%" height={260}>
            <LineChart data={data.charts.tour_sales.map((r) => ({ ...r, month: mlabel(r.month) }))}>
              <CartesianGrid strokeDasharray="3 3" stroke="rgb(var(--ink-200))" vertical={false} />
              <XAxis dataKey="month" tick={{ fontSize: 12 }} /><YAxis tickFormatter={short} tick={{ fontSize: 12 }} width={60} />
              <Tooltip formatter={(v) => money(Number(v))} /><Line type="monotone" dataKey="revenue" name="Tour revenue" stroke={C.coral} strokeWidth={2.5} dot={{ r: 3 }} />
            </LineChart>
          </ResponsiveContainer>
        </ChartCard>
      </div>

      <section>
        <div className="mb-3 flex items-center justify-between"><h2 className="font-display text-xl font-semibold">Upcoming bookings</h2><Link to="/admin/bookings?status=confirmed" className="link text-sm">All bookings</Link></div>
        <div className="space-y-3">{data.upcoming.map((b) => <BookingRow key={b.id} b={b} linkBase="/admin/bookings" />)}</div>
      </section>
    </div>
  );
}

function ChartCard({ title, subtitle, children }: { title: string; subtitle?: string; children: React.ReactNode }) {
  return (
    <section className="card p-5">
      <h2 className="font-sans font-bold text-ink-900">{title}</h2>
      {subtitle && <p className="text-xs text-ink-500">{subtitle}</p>}
      <div className="mt-4">{children}</div>
    </section>
  );
}
