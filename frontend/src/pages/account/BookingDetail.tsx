import { lazy, Suspense, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { ArrowLeft, CalendarDays, CheckCircle2, Clock, Crown, Download, MapPin, Moon, Phone, RefreshCw, Star, Users, XCircle } from 'lucide-react';
import { download, get, post, upload } from '@/lib/api';
import { cn, fmtDate, fmtDateTime, money, plural, titleCase } from '@/lib/format';
import { startPayment } from '@/lib/payments';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { Button } from '@/components/ui/Button';
import { Textarea } from '@/components/ui/Field';
import { Badge, ConfirmDialog, ErrorState, Img, Modal, Skeleton, Spinner, StatusBadge } from '@/components/ui/misc';
import { LineItems } from '@/components/checkout';
import type { Booking, Checkout } from '@/types';

const MapView = lazy(() => import('@/components/MapView'));

interface Preview { allowed: boolean; reason: string | null; within_free_window: boolean; deadline: string | null; refund_amount: number; penalty_amount: number; nights_returned: number; nights_forfeited: number; policy: string }

export default function BookingDetail() {
  const { number = '' } = useParams();
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const qc = useQueryClient();
  usePageMeta(`Booking ${number}`);
  const { data: b, isLoading, isError, error, refetch } = useQuery({ queryKey: ['bookings', 'detail', number], queryFn: () => get<Booking>(`/bookings/${number}`) });
  const [cancelOpen, setCancelOpen] = useState(false);
  const [reason, setReason] = useState('');
  const [reviewOpen, setReviewOpen] = useState(false);

  const preview = useQuery({ queryKey: ['bookings', 'cancel-preview', number], queryFn: () => get<Preview>(`/bookings/${number}/cancellation-preview`), enabled: cancelOpen });
  const cancel = useMutation({
    mutationFn: () => post<Booking>(`/bookings/${number}/cancel`, { reason }),
    onSuccess: (r) => {
      toast.success(r.message);
      setCancelOpen(false);
      qc.invalidateQueries({ queryKey: ['bookings'] });
      qc.invalidateQueries({ queryKey: ['dashboard'] });
      qc.invalidateQueries({ queryKey: ['membership'] });
    },
    onError: (e) => toast.error((e as Error).message),
  });
  const pay = useMutation({
    mutationFn: () => post<{ checkout: Checkout }>(`/bookings/${number}/pay`, {}),
    onSuccess: ({ data }) => startPayment(data.checkout, navigate),
    onError: (e) => toast.error((e as Error).message),
  });
  const invoice = useMutation({ mutationFn: () => download(`/bookings/${number}/invoice`, `StayVoyage-${number}.pdf`), onError: (e) => toast.error((e as Error).message) });

  if (isLoading) return <div className="space-y-4"><Skeleton className="h-10 w-72" /><Skeleton className="h-64 rounded-2xl" /></div>;
  if (isError || !b) return <ErrorState message={(error as Error)?.message} onRetry={() => refetch()} />;

  const items = [
    { label: b.type === 'hotel' ? `Room charges (${plural(b.nights, 'night')})` : 'Itinerary subtotal', amount: b.room_amount },
    ...(b.membership_covered_amount > 0 ? [{ label: `Covered by membership (${plural(b.membership_nights_used, 'night')})`, amount: -b.membership_covered_amount }] : []),
    ...(b.discount_amount > 0 ? [{ label: `Coupon ${b.coupon_code ?? ''}`, amount: -b.discount_amount }] : []),
    { label: 'Taxes (GST)', amount: b.tax_amount },
    { label: b.booking_type === 'membership' ? 'Membership booking fee' : 'Service fee', amount: b.service_fee },
  ];

  return (
    <div className="space-y-6">
      <Link to="/account/bookings" className="inline-flex items-center gap-1.5 text-sm font-semibold text-ink-600 hover:text-brand-700"><ArrowLeft className="h-4 w-4" /> All bookings</Link>

      {params.get('confirmed') && b.status === 'confirmed' && (
        <div className="flex items-center gap-3 rounded-2xl bg-success-50 p-4 text-success-700 ring-1 ring-success-600/20"><CheckCircle2 className="h-6 w-6" /><p className="font-semibold">Your booking is confirmed. A confirmation email is on its way.</p></div>
      )}
      {b.status === 'payment_pending' && (
        <div role="alert" className="flex flex-wrap items-center gap-3 rounded-2xl bg-warning-50 p-4 text-warning-700 ring-1 ring-warning-600/20">
          <Clock className="h-6 w-6" />
          <div className="flex-1"><p className="font-semibold">Payment pending — your booking is not confirmed yet.</p>{b.hold_expires_at && <p className="text-sm">We're holding it until {fmtDateTime(b.hold_expires_at)}.</p>}</div>
          {b.can_pay && <Button onClick={() => pay.mutate()} loading={pay.isPending} icon={<RefreshCw className="h-4 w-4" />}>Complete payment</Button>}
        </div>
      )}

      <div className="card overflow-hidden">
        <div className="grid sm:grid-cols-[260px_1fr]">
          <Img src={b.type === 'hotel' ? b.hotel?.cover_url : b.trip?.cover_url} alt="" className="h-48 w-full sm:h-full" />
          <div className="p-6">
            <div className="flex flex-wrap items-center gap-2">
              <StatusBadge status={b.status} label={b.status_label} />
              <StatusBadge status={b.payment_status} label={`Payment: ${b.payment_status_label}`} />
              {b.booking_type === 'membership' && <Badge tone="sand" icon={<Crown className="h-3 w-3" />}>Membership booking</Badge>}
            </div>
            <h1 className="mt-3 font-display text-2xl font-semibold text-ink-900 sm:text-3xl">{b.type === 'hotel' ? b.hotel?.name : b.trip?.title}</h1>
            <p className="mt-1 text-sm text-ink-500">Booking <span className="font-mono font-semibold text-ink-800">{b.booking_number}</span> · booked {fmtDate(b.created_at)}</p>
            <dl className="mt-5 grid gap-4 text-sm sm:grid-cols-3">
              <div><dt className="flex items-center gap-1.5 text-ink-500"><CalendarDays className="h-4 w-4" /> {b.type === 'hotel' ? 'Check-in' : 'Starts'}</dt><dd className="mt-0.5 font-semibold text-ink-900">{fmtDate(b.check_in, 'EEE, d MMM yyyy')}{b.hotel ? ` · from ${b.hotel.check_in_time}` : ''}</dd></div>
              <div><dt className="flex items-center gap-1.5 text-ink-500"><CalendarDays className="h-4 w-4" /> {b.type === 'hotel' ? 'Check-out' : 'Ends'}</dt><dd className="mt-0.5 font-semibold text-ink-900">{fmtDate(b.check_out, 'EEE, d MMM yyyy')}{b.hotel ? ` · until ${b.hotel.check_out_time}` : ''}</dd></div>
              <div><dt className="flex items-center gap-1.5 text-ink-500"><Users className="h-4 w-4" /> Guests</dt><dd className="mt-0.5 font-semibold text-ink-900">{plural(b.adults, 'adult')}{b.children ? `, ${plural(b.children, 'child', 'children')}` : ''}{b.type === 'hotel' ? ` · ${plural(b.rooms_count, 'room')}` : ''}</dd></div>
            </dl>
            {b.membership_nights_used > 0 && <p className="mt-4 flex items-center gap-2 text-sm font-semibold text-sand-700"><Moon className="h-4 w-4" /> {plural(b.membership_nights_used, 'membership night')} used for this stay</p>}
            <div className="mt-6 flex flex-wrap gap-2">
              {b.has_invoice && <Button variant="outline" size="sm" onClick={() => invoice.mutate()} loading={invoice.isPending} icon={<Download className="h-4 w-4" />}>Download invoice</Button>}
              {b.hotel && <Link to={`/hotels/${b.hotel.slug}`} className="inline-flex h-9 items-center rounded-xl border border-ink-200 bg-white px-3.5 text-sm font-semibold text-ink-800 hover:border-brand-400">View hotel</Link>}
              {b.can_cancel && <Button variant="ghost" size="sm" className="text-danger-600 hover:bg-danger-50" onClick={() => setCancelOpen(true)} icon={<XCircle className="h-4 w-4" />}>Cancel booking</Button>}
              {b.can_review && !b.review && <Button variant="accent" size="sm" onClick={() => setReviewOpen(true)} icon={<Star className="h-4 w-4" />}>Write a review</Button>}
              {b.review && <Badge tone={b.review.status === 'approved' ? 'success' : 'warning'}>Your review: {b.review.status}</Badge>}
            </div>
          </div>
        </div>
      </div>

      <div className="grid gap-6 lg:grid-cols-[1fr_380px]">
        <div className="space-y-6">
          {b.type === 'hotel' && b.rooms?.map((r) => (
            <section key={r.id} className="card p-5">
              <h2 className="font-sans font-bold text-ink-900">Room</h2>
              <div className="mt-3 flex gap-4">
                <Img src={r.image_url} alt="" className="h-20 w-28 shrink-0 rounded-xl" />
                <div className="text-sm">
                  <p className="font-semibold text-ink-900">{r.room_name} × {r.quantity}</p>
                  <p className="text-ink-500">{r.bed_type} bed{r.breakfast_included ? ' · Breakfast included' : ''}</p>
                  <p className="mt-1 text-ink-500">{Object.entries(r.nightly_rates).map(([d, p]) => `${fmtDate(d, 'd MMM')}: ${money(p)}`).join(' · ')}</p>
                </div>
              </div>
            </section>
          ))}

          {b.type === 'tour' && b.itinerary && (
            <section className="card p-5">
              <h2 className="font-sans font-bold text-ink-900">Your itinerary</h2>
              <ol className="mt-4 space-y-5">
                {b.itinerary.days.map((d) => (
                  <li key={d.day_number} className="relative border-l-2 border-brand-200 pl-5">
                    <span className="absolute -left-[9px] top-0.5 h-4 w-4 rounded-full border-2 border-white bg-brand-600" />
                    <p className="font-semibold text-ink-900">Day {d.day_number} · {d.title} <span className="font-normal text-ink-500">{fmtDate(d.date, 'EEE d MMM')}</span></p>
                    <ul className="mt-2 space-y-1.5 text-sm">
                      {d.items.map((i, idx) => (
                        <li key={idx} className="flex justify-between gap-3"><span className="text-ink-700">{i.start_time && <span className="mr-2 font-mono text-xs text-ink-400">{i.start_time}</span>}{i.title}<span className="ml-1.5 text-xs text-ink-400">{titleCase(i.type)}</span></span><span className="shrink-0 font-semibold text-ink-800">{money(i.total_price)}</span></li>
                      ))}
                    </ul>
                  </li>
                ))}
              </ol>
            </section>
          )}

          {b.guests && (
            <section className="card p-5">
              <h2 className="font-sans font-bold text-ink-900">Guests & contact</h2>
              <ul className="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                {b.guests.map((g) => <li key={g.id} className="flex items-center gap-2"><Users className="h-4 w-4 text-ink-400" />{g.full_name} <span className="text-xs text-ink-400">{g.guest_type}{g.is_primary ? ' · lead' : ''}</span></li>)}
              </ul>
              {b.contact && <p className="mt-3 text-sm text-ink-600">{b.contact.name} · {b.contact.email} · {b.contact.phone}</p>}
              {b.special_requests && <p className="mt-3 rounded-xl bg-ink-50 p-3 text-sm text-ink-600"><span className="font-semibold">Special requests:</span> {b.special_requests}</p>}
            </section>
          )}

          {b.hotel?.latitude && b.hotel.longitude && (
            <section className="card p-5">
              <h2 className="font-sans font-bold text-ink-900">Getting there</h2>
              <p className="mt-1 flex items-center gap-1.5 text-sm text-ink-600"><MapPin className="h-4 w-4" />{b.hotel.address}, {b.hotel.city}</p>
              {b.hotel.phone && <p className="mt-1 flex items-center gap-1.5 text-sm text-ink-600"><Phone className="h-4 w-4" />{b.hotel.phone}</p>}
              <Suspense fallback={<Spinner />}><MapView className="mt-4 h-60 overflow-hidden rounded-2xl" points={[{ lat: b.hotel.latitude, lng: b.hotel.longitude, label: b.hotel.name }]} /></Suspense>
            </section>
          )}
        </div>

        <aside className="space-y-6">
          <section className="card p-5">
            <h2 className="mb-4 font-sans font-bold text-ink-900">Price summary</h2>
            <LineItems items={items} total={b.total_amount} />
            <dl className="mt-4 space-y-1.5 border-t border-ink-100 pt-4 text-sm">
              <div className="flex justify-between"><dt className="text-ink-600">Paid</dt><dd className="font-semibold">{money(b.amount_paid, { exact: true })}</dd></div>
              {b.amount_refunded > 0 && <div className="flex justify-between text-success-700"><dt>Refunded</dt><dd className="font-semibold">{money(b.amount_refunded, { exact: true })}</dd></div>}
            </dl>
          </section>
          {b.payments && b.payments.length > 0 && (
            <section className="card p-5">
              <h2 className="mb-3 font-sans font-bold text-ink-900">Payments</h2>
              <ul className="space-y-3 text-sm">
                {b.payments.map((p) => (
                  <li key={p.id} className="flex justify-between gap-3">
                    <div><p className="font-mono text-xs text-ink-500">{p.reference}</p><p className="text-ink-700">{titleCase(p.gateway)}{p.method ? ` · ${p.method.toUpperCase()}` : ''}</p>{p.failure_reason && <p className="text-xs text-danger-600">{p.failure_reason}</p>}</div>
                    <div className="text-right"><p className="font-semibold">{money(p.amount)}</p><StatusBadge status={p.status} label={p.status_label} /></div>
                  </li>
                ))}
              </ul>
            </section>
          )}
          {b.cancellation && (
            <section className="card p-5">
              <h2 className="mb-3 font-sans font-bold text-ink-900">Cancellation</h2>
              <dl className="space-y-1.5 text-sm">
                <div className="flex justify-between"><dt className="text-ink-600">Cancelled</dt><dd>{fmtDateTime(b.cancelled_at)}</dd></div>
                <div className="flex justify-between"><dt className="text-ink-600">Refund</dt><dd className="font-semibold">{money(b.cancellation.refund_amount, { exact: true })}</dd></div>
                {b.cancellation.penalty_amount > 0 && <div className="flex justify-between"><dt className="text-ink-600">Cancellation charge</dt><dd>{money(b.cancellation.penalty_amount, { exact: true })}</dd></div>}
                {b.cancellation.nights_returned > 0 && <div className="flex justify-between"><dt className="text-ink-600">Nights returned</dt><dd className="font-semibold text-success-700">+{b.cancellation.nights_returned}</dd></div>}
                {b.cancellation.nights_forfeited > 0 && <div className="flex justify-between"><dt className="text-ink-600">Nights forfeited</dt><dd>{b.cancellation.nights_forfeited}</dd></div>}
                {b.cancellation.reason && <p className="pt-2 text-ink-500">“{b.cancellation.reason}”</p>}
              </dl>
              {b.refunds?.map((r) => <p key={r.reference} className="mt-2 text-xs text-ink-500">Refund {r.reference}: {money(r.amount)} · {r.status}</p>)}
            </section>
          )}
        </aside>
      </div>

      <ConfirmDialog open={cancelOpen} onClose={() => setCancelOpen(false)} onConfirm={() => cancel.mutate()} loading={cancel.isPending} tone="danger" confirmLabel="Cancel booking" title="Cancel this booking?">
        {preview.isLoading ? <Spinner /> : preview.data ? (
          <div className="space-y-4 text-sm">
            {!preview.data.allowed ? <p className="rounded-xl bg-danger-50 p-3 font-semibold text-danger-700">{preview.data.reason}</p> : (
              <>
                <p className="text-ink-600">{preview.data.policy}</p>
                <dl className={cn('space-y-2 rounded-xl p-4', preview.data.within_free_window ? 'bg-success-50' : 'bg-warning-50')}>
                  <div className="flex justify-between"><dt>Refund to your payment method</dt><dd className="font-bold">{money(preview.data.refund_amount, { exact: true })}</dd></div>
                  {preview.data.penalty_amount > 0 && <div className="flex justify-between"><dt>Cancellation charge</dt><dd className="font-bold">{money(preview.data.penalty_amount, { exact: true })}</dd></div>}
                  {preview.data.nights_returned > 0 && <div className="flex justify-between"><dt>Membership nights returned</dt><dd className="font-bold">+{preview.data.nights_returned}</dd></div>}
                  {preview.data.nights_forfeited > 0 && <div className="flex justify-between"><dt>Membership nights forfeited</dt><dd className="font-bold">{preview.data.nights_forfeited}</dd></div>}
                </dl>
                <Textarea label="Reason (optional)" rows={2} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={250} />
              </>
            )}
          </div>
        ) : <ErrorState message={(preview.error as Error)?.message} />}
      </ConfirmDialog>

      <ReviewModal open={reviewOpen} onClose={() => setReviewOpen(false)} number={number} hotel={b.hotel?.name ?? ''} onDone={() => { setReviewOpen(false); refetch(); }} />
    </div>
  );
}

function ReviewModal({ open, onClose, number, hotel, onDone }: { open: boolean; onClose: () => void; number: string; hotel: string; onDone: () => void }) {
  const [scores, setScores] = useState({ overall: 5, cleanliness: 5, location: 5, service: 5, value: 5 });
  const [title, setTitle] = useState('');
  const [comment, setComment] = useState('');
  const [files, setFiles] = useState<File[]>([]);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const submit = useMutation({
    mutationFn: () => upload(`/bookings/${number}/review`, { ...scores, title, comment, images: files }),
    onSuccess: (r) => { toast.success(r.message); onDone(); },
    onError: (e: Error & { errors?: Record<string, string[]> }) => {
      toast.error(e.message);
      if (e.errors) setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]!])));
    },
  });
  const valid = title.trim().length >= 4 && comment.trim().length >= 20;
  return (
    <Modal open={open} onClose={onClose} title={`Review your stay at ${hotel}`} size="lg"
      footer={<><Button variant="outline" onClick={onClose}>Cancel</Button><Button onClick={() => submit.mutate()} loading={submit.isPending} disabled={!valid}>Submit review</Button></>}>
      <div className="space-y-5">
        <div className="grid gap-3 sm:grid-cols-2">
          {(Object.keys(scores) as (keyof typeof scores)[]).map((k) => (
            <div key={k} className="flex items-center justify-between rounded-xl bg-ink-50 px-3 py-2">
              <span className="text-sm font-semibold capitalize text-ink-700">{k}</span>
              <div className="flex" role="radiogroup" aria-label={`${k} rating`}>
                {[1, 2, 3, 4, 5].map((n) => (
                  <button key={n} type="button" role="radio" aria-checked={scores[k] === n} aria-label={`${n} star${n > 1 ? 's' : ''}`} onClick={() => setScores((s) => ({ ...s, [k]: n }))}>
                    <Star className={cn('h-6 w-6', n <= scores[k] ? 'fill-sand-400 text-sand-400' : 'text-ink-300')} />
                  </button>
                ))}
              </div>
            </div>
          ))}
        </div>
        <label className="block"><span className="label">Title</span><input className="input" value={title} onChange={(e) => setTitle(e.target.value)} maxLength={150} placeholder="Sum up your stay" />{errors.title && <span className="text-xs text-danger-600">{errors.title}</span>}</label>
        <Textarea label="Your review" value={comment} onChange={(e) => setComment(e.target.value)} maxLength={3000} hint={`${comment.trim().length}/20 characters minimum`} error={errors.comment} placeholder="What did you love? What could be better?" />
        <label className="block"><span className="label">Photos (optional, up to 5)</span>
          <input type="file" accept="image/jpeg,image/png,image/webp" multiple onChange={(e) => setFiles(Array.from(e.target.files ?? []).slice(0, 5))} className="text-sm" />
        </label>
      </div>
    </Modal>
  );
}
