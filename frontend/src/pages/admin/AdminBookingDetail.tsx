import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { ArrowLeft, Crown, XCircle } from 'lucide-react';
import { get, post } from '@/lib/api';
import { fmtDate, fmtDateTime, money, plural, titleCase } from '@/lib/format';
import { useAuth } from '@/providers/AuthProvider';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { Button } from '@/components/ui/Button';
import { Textarea } from '@/components/ui/Field';
import { Badge, ConfirmDialog, ErrorState, Img, Skeleton, Spinner, StatusBadge, Switch } from '@/components/ui/misc';
import { LineItems } from '@/components/checkout';
import type { Booking } from '@/types';

interface Preview { allowed: boolean; reason: string | null; within_free_window: boolean; refund_amount: number; penalty_amount: number; nights_returned: number; nights_forfeited: number; policy: string }

export default function AdminBookingDetail({ partner = false }: { partner?: boolean }) {
  const { id = '' } = useParams();
  const base = partner ? '/partner' : '/admin';
  const { can } = useAuth();
  const qc = useQueryClient();
  const { data: b, isLoading, isError, refetch } = useQuery({ queryKey: ['admin', 'booking', base, id], queryFn: () => get<Booking>(`${base}/bookings/${id}`) });
  usePageMeta(b ? `Booking ${b.booking_number}` : 'Booking');
  const [cancelOpen, setCancelOpen] = useState(false);
  const [waive, setWaive] = useState(false);
  const [reason, setReason] = useState('');
  const preview = useQuery({ queryKey: ['admin', 'cancel-preview', id, waive], queryFn: () => get<Preview>(`/admin/bookings/${id}/cancellation-preview`, { waive_penalty: waive ? 1 : 0 }), enabled: cancelOpen });
  const cancel = useMutation({
    mutationFn: () => post(`/admin/bookings/${id}/cancel`, { reason, waive_penalty: waive }),
    onSuccess: (r) => { toast.success(r.message); setCancelOpen(false); qc.invalidateQueries({ queryKey: ['admin'] }); },
    onError: (e) => toast.error((e as Error).message),
  });

  if (isLoading) return <Skeleton className="h-96 rounded-2xl" />;
  if (isError || !b) return <ErrorState onRetry={() => refetch()} />;
  const canCancel = !partner && can('bookings.manage') && ['payment_pending', 'confirmed', 'pending'].includes(b.status);

  return (
    <div className="space-y-6">
      <Link to={`${base}/bookings`} className="inline-flex items-center gap-1.5 text-sm font-semibold text-ink-500 hover:text-brand-700"><ArrowLeft className="h-4 w-4" /> All bookings</Link>
      <div className="card flex flex-wrap items-start gap-5 p-6">
        <Img src={b.type === 'hotel' ? b.hotel?.cover_url : b.trip?.cover_url} alt="" className="h-24 w-32 rounded-xl" />
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap gap-2"><StatusBadge status={b.status} label={b.status_label} /><StatusBadge status={b.payment_status} label={`Payment: ${b.payment_status_label}`} />{b.booking_type === 'membership' && <Badge tone="sand" icon={<Crown className="h-3 w-3" />}>Membership · {b.membership_nights_used} nights</Badge>}</div>
          <h1 className="mt-2 font-display text-2xl font-semibold">{b.type === 'hotel' ? b.hotel?.name : b.trip?.title}</h1>
          <p className="text-sm text-ink-500"><span className="font-mono font-semibold text-ink-800">{b.booking_number}</span> · created {fmtDateTime(b.created_at)}</p>
          <p className="mt-2 text-sm">{fmtDate(b.check_in, 'EEE d MMM yyyy')} → {fmtDate(b.check_out, 'EEE d MMM yyyy')} · {plural(b.adults, 'adult')}{b.children ? `, ${plural(b.children, 'child', 'children')}` : ''}{b.type === 'hotel' ? ` · ${plural(b.rooms_count, 'room')}` : ''}</p>
        </div>
        {canCancel && <Button variant="outline" className="text-danger-600" onClick={() => setCancelOpen(true)} icon={<XCircle className="h-4 w-4" />}>Cancel booking</Button>}
      </div>

      <div className="grid gap-6 lg:grid-cols-[1fr_380px]">
        <div className="space-y-6">
          <section className="card p-5">
            <h2 className="font-sans font-bold">Guest</h2>
            <p className="mt-2 text-sm">{b.customer?.name} · {b.customer?.email}{!partner && b.customer && <> · <Link to={`/admin/users/${b.customer.id}`} className="link">profile</Link></>}</p>
            {b.contact && <p className="text-sm text-ink-600">Contact: {b.contact.name}, {b.contact.email}, {b.contact.phone}</p>}
            <ul className="mt-3 grid gap-1 text-sm sm:grid-cols-2">{b.guests?.map((g) => <li key={g.id}>{g.full_name} <span className="text-xs text-ink-400">{g.guest_type}{g.is_primary ? ' · lead' : ''}</span></li>)}</ul>
            {b.special_requests && <p className="mt-3 rounded-xl bg-ink-50 p-3 text-sm">Special requests: {b.special_requests}</p>}
          </section>
          {b.rooms?.length ? (
            <section className="card p-5"><h2 className="font-sans font-bold">Rooms reserved</h2>
              <ul className="mt-2 space-y-2 text-sm">{b.rooms.map((r) => <li key={r.id} className="flex justify-between gap-3"><span>{r.hotel_name} — {r.room_name} × {r.quantity} · {fmtDate(r.check_in, 'd MMM')}–{fmtDate(r.check_out, 'd MMM')}</span><span className="font-semibold">{money(r.amount)}</span></li>)}</ul></section>
          ) : null}
          {b.itinerary && (
            <section className="card p-5"><h2 className="font-sans font-bold">Itinerary</h2>
              {b.itinerary.days.map((d) => <div key={d.day_number} className="mt-3"><p className="text-sm font-semibold">Day {d.day_number} · {d.title} ({fmtDate(d.date, 'd MMM')})</p><ul className="ml-4 list-disc text-sm text-ink-600">{d.items.map((i, k) => <li key={k}>{i.start_time ? `${i.start_time} ` : ''}{i.title} — {money(i.total_price)}</li>)}</ul></div>)}</section>
          )}
        </div>
        <aside className="space-y-6">
          <section className="card p-5"><h2 className="mb-3 font-sans font-bold">Price</h2>
            <LineItems total={b.total_amount} items={[
              { label: 'Room / itinerary amount', amount: b.room_amount },
              ...(b.membership_covered_amount > 0 ? [{ label: 'Membership coverage', amount: -b.membership_covered_amount }] : []),
              ...(b.discount_amount > 0 ? [{ label: `Coupon ${b.coupon_code}`, amount: -b.discount_amount }] : []),
              { label: 'Taxes', amount: b.tax_amount }, { label: 'Fees', amount: b.service_fee },
            ]} />
            <p className="mt-3 text-sm">Paid {money(b.amount_paid, { exact: true })}{b.amount_refunded > 0 ? ` · refunded ${money(b.amount_refunded, { exact: true })}` : ''}</p>
          </section>
          <section className="card p-5"><h2 className="mb-3 font-sans font-bold">Payments</h2>
            <ul className="space-y-2 text-sm">{b.payments?.map((p) => <li key={p.id} className="flex justify-between gap-2"><span><span className="font-mono text-xs">{p.reference}</span><br />{titleCase(p.gateway)} {p.gateway_payment_id && <span className="text-xs text-ink-500">· {p.gateway_payment_id}</span>}</span><span className="text-right">{money(p.amount)}<br /><StatusBadge status={p.status} label={p.status_label} /></span></li>)}</ul>
            {!partner && can('refunds.manage') && <Link to="/admin/payments" className="link mt-3 inline-block text-sm">Issue a manual refund</Link>}
          </section>
          {b.cancellation && <section className="card p-5 text-sm"><h2 className="mb-2 font-sans font-bold">Cancellation</h2><p>By {b.cancellation.cancelled_by_role} · refund {money(b.cancellation.refund_amount)} · penalty {money(b.cancellation.penalty_amount)}</p><p>Nights returned {b.cancellation.nights_returned} · forfeited {b.cancellation.nights_forfeited}</p>{b.cancellation.reason && <p className="mt-1 text-ink-500">“{b.cancellation.reason}”</p>}</section>}
        </aside>
      </div>

      <ConfirmDialog open={cancelOpen} onClose={() => setCancelOpen(false)} onConfirm={() => cancel.mutate()} loading={cancel.isPending} tone="danger" confirmLabel="Cancel booking" title={`Cancel ${b.booking_number}?`}>
        <div className="space-y-4 text-sm">
          <Switch checked={waive} onChange={setWaive} label="Waive cancellation penalty (full refund, return nights)" />
          {preview.isLoading ? <Spinner /> : preview.data && (
            <dl className="space-y-1.5 rounded-xl bg-ink-50 p-3">
              <div className="flex justify-between"><dt>Refund</dt><dd className="font-bold">{money(preview.data.refund_amount, { exact: true })}</dd></div>
              <div className="flex justify-between"><dt>Penalty</dt><dd>{money(preview.data.penalty_amount, { exact: true })}</dd></div>
              <div className="flex justify-between"><dt>Nights returned / forfeited</dt><dd>{preview.data.nights_returned} / {preview.data.nights_forfeited}</dd></div>
            </dl>
          )}
          <Textarea label="Reason (required, visible to the guest)" rows={2} value={reason} onChange={(e) => setReason(e.target.value)} />
          {reason.trim().length < 5 && <p className="text-xs text-ink-500">Enter at least 5 characters.</p>}
        </div>
      </ConfirmDialog>
    </div>
  );
}
