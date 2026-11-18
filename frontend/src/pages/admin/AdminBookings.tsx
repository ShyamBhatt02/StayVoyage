import { useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Crown, Route } from 'lucide-react';
import { getPage } from '@/lib/api';
import { fmtDate, money } from '@/lib/format';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { StatusBadge } from '@/components/ui/misc';
import { AdminHeader, DataTable, Toolbar } from '@/components/admin/kit';
import type { Booking } from '@/types';

export default function AdminBookings({ partner = false }: { partner?: boolean }) {
  usePageMeta(partner ? 'Bookings — Partner' : 'Bookings — Admin');
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const base = partner ? '/partner' : '/admin';
  const [f, setF] = useState({ q: '', type: '', status: params.get('status') ?? '', booking_type: '', from: '', to: '' });
  const [page, setPage] = useState(1);
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['admin', 'bookings', base, f, page],
    queryFn: () => getPage<Booking>(`${base}/bookings`, { ...Object.fromEntries(Object.entries(f).filter(([, v]) => v)), page }),
    placeholderData: keepPreviousData,
  });
  const set = (k: keyof typeof f, v: string) => { setF((s) => ({ ...s, [k]: v })); setPage(1); };
  return (
    <div>
      <AdminHeader title="Bookings" description={partner ? 'Bookings at your properties.' : 'Every hotel stay and trip, with payment status.'} />
      <Toolbar>
        <input className="input w-60" placeholder="Booking no., name or email" value={f.q} onChange={(e) => set('q', e.target.value)} aria-label="Search bookings" />
        {!partner && <select className="input w-auto" value={f.type} onChange={(e) => set('type', e.target.value)} aria-label="Type"><option value="">Hotels & trips</option><option value="hotel">Hotels</option><option value="tour">Trips</option></select>}
        <select className="input w-auto" value={f.status} onChange={(e) => set('status', e.target.value)} aria-label="Status"><option value="">All statuses</option>{['payment_pending', 'confirmed', 'completed', 'cancelled', 'failed'].map((s) => <option key={s} value={s}>{s.replace('_', ' ')}</option>)}</select>
        <select className="input w-auto" value={f.booking_type} onChange={(e) => set('booking_type', e.target.value)} aria-label="Booking type"><option value="">Member & regular</option><option value="membership">Membership</option><option value="regular">Regular</option></select>
        <label className="flex items-center gap-1 text-sm text-ink-600">Check-in from <input type="date" className="input h-10 w-auto py-1" value={f.from} onChange={(e) => set('from', e.target.value)} /></label>
        <label className="flex items-center gap-1 text-sm text-ink-600">to <input type="date" className="input h-10 w-auto py-1" value={f.to} onChange={(e) => set('to', e.target.value)} /></label>
      </Toolbar>
      <DataTable rows={data?.items} loading={isLoading} error={error} onRetry={refetch} pagination={data?.pagination} onPage={setPage} onRowClick={(b) => navigate(`${base}/bookings/${b.id}`)}
        columns={[
          { key: 'no', header: 'Booking', cell: (b) => <div><p className="font-mono text-xs font-semibold">{b.booking_number}</p><p className="text-xs text-ink-500">{fmtDate(b.created_at)}</p></div> },
          { key: 'what', header: 'Stay / trip', cell: (b) => <div className="flex items-center gap-1.5">{b.type === 'tour' ? <Route className="h-4 w-4 text-brand-600" /> : null}{b.booking_type === 'membership' ? <Crown className="h-4 w-4 text-sand-600" /> : null}<span className="font-medium">{b.type === 'hotel' ? b.hotel?.name : b.trip?.title}</span></div> },
          { key: 'cust', header: 'Guest', cell: (b) => b.customer?.name ?? '—' },
          { key: 'dates', header: 'Dates', cell: (b) => `${fmtDate(b.check_in, 'd MMM')} – ${fmtDate(b.check_out, 'd MMM yy')}` },
          { key: 'amt', header: 'Total', cell: (b) => <div><p className="font-semibold">{money(b.total_amount)}</p>{b.membership_nights_used > 0 && <p className="text-xs text-sand-700">{b.membership_nights_used} nights</p>}</div> },
          { key: 'status', header: 'Status', cell: (b) => <div className="space-y-1"><StatusBadge status={b.status} label={b.status_label} /><br /><StatusBadge status={b.payment_status} label={b.payment_status_label} /></div> },
        ]} />
    </div>
  );
}
