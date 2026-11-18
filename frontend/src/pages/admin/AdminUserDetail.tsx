import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { ArrowLeft, Ban, CheckCircle2 } from 'lucide-react';
import { get, post } from '@/lib/api';
import { fmtDate, fmtDateTime, money } from '@/lib/format';
import { useAuth } from '@/providers/AuthProvider';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { Button } from '@/components/ui/Button';
import { Textarea } from '@/components/ui/Field';
import { Avatar, Badge, ErrorState, Modal, Skeleton, StatusBadge, Tabs } from '@/components/ui/misc';
import { BookingRow } from '@/components/account';
import type { Booking, Membership, Payment, Trip, User } from '@/types';

interface Detail { user: User; memberships: Membership[]; bookings: Booking[]; payments: Payment[]; trips: Trip[]; hotels: { id: number; name: string }[] }

export default function AdminUserDetail() {
  const { id = '' } = useParams();
  const { can, user: me } = useAuth();
  const qc = useQueryClient();
  const [tab, setTab] = useState<'bookings' | 'memberships' | 'payments' | 'trips'>('bookings');
  const [suspendOpen, setSuspendOpen] = useState(false);
  const [reason, setReason] = useState('');
  const { data, isLoading, isError, refetch } = useQuery({ queryKey: ['admin', 'user', id], queryFn: () => get<Detail>(`/admin/users/${id}`) });
  usePageMeta(data ? `${data.user.name} — Admin` : 'User');
  const act = useMutation({
    mutationFn: (action: 'suspend' | 'activate') => post(`/admin/users/${id}/${action}`, action === 'suspend' ? { reason } : {}),
    onSuccess: (r) => { toast.success(r.message); setSuspendOpen(false); qc.invalidateQueries({ queryKey: ['admin', 'user', id] }); qc.invalidateQueries({ queryKey: ['admin', 'users'] }); },
    onError: (e) => toast.error((e as Error).message),
  });
  if (isLoading) return <Skeleton className="h-96 rounded-2xl" />;
  if (isError || !data) return <ErrorState onRetry={() => refetch()} />;
  const u = data.user;
  return (
    <div className="space-y-6">
      <Link to="/admin/users" className="inline-flex items-center gap-1.5 text-sm font-semibold text-ink-500 hover:text-brand-700"><ArrowLeft className="h-4 w-4" /> All users</Link>
      <div className="card flex flex-wrap items-center gap-5 p-6">
        <Avatar name={u.name} src={u.avatar_url} size="h-16 w-16 text-lg" />
        <div className="flex-1">
          <h1 className="font-display text-2xl font-semibold">{u.name}</h1>
          <p className="text-sm text-ink-500">{u.email} · {u.phone ?? 'no phone'} · joined {fmtDate(u.created_at)} · last login {fmtDateTime(u.last_login_at)}</p>
          <div className="mt-2 flex flex-wrap gap-1.5"><StatusBadge status={u.status} />{u.roles?.map((r) => <Badge key={r}>{r}</Badge>)}{u.email_verified ? <Badge tone="success">Email verified</Badge> : <Badge tone="warning">Unverified</Badge>}{u.two_factor_enabled && <Badge tone="info">2FA on</Badge>}</div>
          {data.hotels.length > 0 && <p className="mt-2 text-sm">Manages: {data.hotels.map((h) => <Link key={h.id} to={`/admin/hotels/${h.id}`} className="link mr-2">{h.name}</Link>)}</p>}
        </div>
        {can('users.manage') && u.id !== me?.id && !u.roles?.includes('super-admin') && (
          u.status === 'active'
            ? <Button variant="outline" className="text-danger-600" onClick={() => setSuspendOpen(true)} icon={<Ban className="h-4 w-4" />}>Suspend</Button>
            : <Button onClick={() => act.mutate('activate')} loading={act.isPending} icon={<CheckCircle2 className="h-4 w-4" />}>Re-activate</Button>
        )}
      </div>
      <Tabs className="w-fit" value={tab} onChange={setTab} tabs={[{ value: 'bookings', label: `Bookings (${data.bookings.length})` }, { value: 'memberships', label: `Memberships (${data.memberships.length})` }, { value: 'payments', label: `Payments (${data.payments.length})` }, { value: 'trips', label: `Saved trips (${data.trips.length})` }]} />
      {tab === 'bookings' && <div className="space-y-3">{data.bookings.map((b) => <BookingRow key={b.id} b={b} linkBase="/admin/bookings" />)}{!data.bookings.length && <p className="text-ink-500">No bookings.</p>}</div>}
      {tab === 'memberships' && <ul className="card divide-y divide-ink-100">{data.memberships.map((m) => <li key={m.id} className="flex flex-wrap justify-between gap-2 p-4 text-sm"><span><span className="font-mono font-semibold">{m.membership_number}</span> · {m.plan_name}</span><span>{fmtDate(m.starts_at)} – {fmtDate(m.expires_at)}</span><span>{m.used_nights}/{m.total_nights} used</span><StatusBadge status={m.status} label={m.status_label} /></li>)}{!data.memberships.length && <li className="p-4 text-ink-500">No memberships.</li>}</ul>}
      {tab === 'payments' && <ul className="card divide-y divide-ink-100">{data.payments.map((p) => <li key={p.id} className="flex flex-wrap justify-between gap-2 p-4 text-sm"><span className="font-mono">{p.reference}</span><span>{p.payable_reference}</span><span>{fmtDate(p.paid_at ?? p.created_at)}</span><span className="font-semibold">{money(p.amount)}</span><StatusBadge status={p.status} label={p.status_label} /></li>)}{!data.payments.length && <li className="p-4 text-ink-500">No payments.</li>}</ul>}
      {tab === 'trips' && <ul className="card divide-y divide-ink-100">{data.trips.map((t) => <li key={t.id} className="flex flex-wrap justify-between gap-2 p-4 text-sm"><span className="font-semibold">{t.title}</span><span>{t.duration_days} days · {t.travelers} travellers</span><span>{money(t.total_amount)}</span><StatusBadge status={t.status === 'booked' ? 'confirmed' : 'pending'} label={t.status} /></li>)}{!data.trips.length && <li className="p-4 text-ink-500">No trips.</li>}</ul>}
      <Modal open={suspendOpen} onClose={() => setSuspendOpen(false)} title={`Suspend ${u.name}?`} description="They'll be signed out of every device and won't be able to log in until re-activated." size="sm"
        footer={<><Button variant="outline" onClick={() => setSuspendOpen(false)}>Cancel</Button><Button variant="danger" loading={act.isPending} disabled={reason.trim().length < 5} onClick={() => act.mutate('suspend')}>Suspend</Button></>}>
        <Textarea label="Reason (audited)" rows={3} value={reason} onChange={(e) => setReason(e.target.value)} />
      </Modal>
    </div>
  );
}
