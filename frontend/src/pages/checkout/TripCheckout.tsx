import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useFieldArray, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { toast } from 'sonner';
import { AlertTriangle, CalendarDays, Tag, Users } from 'lucide-react';
import { ApiError, get, post } from '@/lib/api';
import { fmtDate, money } from '@/lib/format';
import { startPayment } from '@/lib/payments';
import { useAuth } from '@/providers/AuthProvider';
import { useConfig } from '@/hooks/useConfig';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { Button } from '@/components/ui/Button';
import { Checkbox, Input, Select, Textarea } from '@/components/ui/Field';
import { ErrorState, Skeleton, Spinner } from '@/components/ui/misc';
import { GatewayPicker, LineItems, Steps, TrustNote } from '@/components/checkout';
import { GROUP_LABELS } from '@/pages/builder/builderShared';
import type { Booking, Checkout, Trip } from '@/types';

interface Quote {
  subtotal: number; discount_amount: number; tax_amount: number; service_fee: number; total_amount: number; coupon_code: string | null;
  groups: Record<string, number>; tax_percent: number; service_fee_percent: number; travelers: number; start_date: string; end_date: string;
  bookable: boolean; unavailable_items: { id: number; title: string; note: string }[];
}

const schema = z.object({
  contact_name: z.string().trim().min(2).max(80),
  contact_email: z.string().trim().email(),
  contact_phone: z.string().trim().regex(/^\+?[\d\s-]{10,18}$/, 'Enter a valid phone number'),
  special_requests: z.string().max(1000).optional(),
  travelers: z.array(z.object({
    full_name: z.string().trim().min(2, 'Enter full name').max(80),
    traveler_type: z.enum(['adult', 'child']),
    age: z.coerce.number().int().min(0).max(120).optional(),
    nationality: z.string().max(60).optional(),
  })),
  accept_terms: z.literal(true, { errorMap: () => ({ message: 'Please accept the booking terms' }) }),
});
type Form = z.infer<typeof schema>;

export default function TripCheckout() {
  usePageMeta('Book your trip');
  const { id = '' } = useParams();
  const navigate = useNavigate();
  const { user } = useAuth();
  const { data: config } = useConfig();
  const [couponInput, setCouponInput] = useState('');
  const [coupon, setCoupon] = useState<string | null>(null);
  const [gateway, setGateway] = useState('');
  useEffect(() => { if (!gateway && config?.payment_gateways.length) setGateway(config.payment_gateways.find((g) => g.default)?.name ?? config.payment_gateways[0]!.name); }, [config, gateway]);

  const trip = useQuery({ queryKey: ['trip', id], queryFn: () => get<Trip>(`/trips/${id}`) });
  const quote = useQuery({ queryKey: ['trip-quote', id, coupon], queryFn: () => post<Quote>(`/trips/${id}/quote`, { coupon_code: coupon }).then((r) => r.data), retry: false, enabled: trip.data?.status === 'draft' });

  const { register, control, handleSubmit, reset, setError, formState: { errors } } = useForm<Form>({ resolver: zodResolver(schema) as never });
  const { fields } = useFieldArray({ control, name: 'travelers' });
  useEffect(() => {
    if (!trip.data) return;
    reset({
      contact_name: user?.name ?? '', contact_email: user?.email ?? '', contact_phone: user?.phone ?? '', special_requests: '',
      travelers: [
        ...Array.from({ length: trip.data.adults }, (_, i) => ({ full_name: i === 0 ? user?.name ?? '' : '', traveler_type: 'adult' as const, nationality: 'Indian' })),
        ...Array.from({ length: trip.data.children }, () => ({ full_name: '', traveler_type: 'child' as const, nationality: 'Indian' })),
      ],
      accept_terms: false as unknown as true,
    });
  }, [trip.data, user, reset]);

  const book = useMutation({
    mutationFn: (v: Form) => post<{ booking: Booking; checkout: Checkout | null }>(`/trips/${id}/book`, {
      ...v, contact_phone: v.contact_phone.replace(/[\s-]/g, ''), coupon_code: coupon, gateway, expected_total: quote.data?.total_amount,
    }),
    onSuccess: async ({ data, message }) => {
      toast.success(message);
      if (data.checkout) await startPayment(data.checkout, navigate);
      else navigate(`/account/bookings/${data.booking.booking_number}?confirmed=1`);
    },
    onError: (e) => {
      if (e instanceof ApiError) {
        Object.entries(e.errors).forEach(([k, v]) => Array.isArray(v) && setError(k as keyof Form, { message: v[0] }));
        if (['price_changed', 'tour_item_unavailable'].includes(e.code ?? '')) quote.refetch();
      }
      toast.error((e as Error).message);
    },
  });

  if (trip.isLoading) return <Spinner className="min-h-[60vh]" />;
  if (trip.isError || !trip.data) return <div className="container-page py-16"><ErrorState message={(trip.error as Error)?.message} /></div>;
  if (trip.data.status !== 'draft') return <div className="container-page py-16"><ErrorState message="This trip has already been booked." /></div>;
  const t = trip.data;
  const q = quote.data;

  return (
    <div className="container-page py-8">
      <Steps steps={['Build trip', 'Review itinerary', 'Travellers', 'Payment', 'Confirmation']} current={2} />
      <h1 className="mt-6 font-display text-3xl font-semibold text-ink-900">Book “{t.title}”</h1>
      <div className="mt-6 grid gap-8 lg:grid-cols-[1fr_400px]">
        <form id="trip-checkout" onSubmit={handleSubmit((v) => book.mutate(v))} noValidate className="space-y-6">
          <section className="card p-6">
            <div className="flex items-center justify-between"><h2 className="font-sans text-lg font-bold">Itinerary review</h2><Link to={`/tour-builder/${id}`} className="link text-sm">Edit itinerary</Link></div>
            <ol className="mt-4 space-y-3">
              {t.days.map((d) => (
                <li key={d.id} className="rounded-xl bg-ink-50 p-3">
                  <p className="text-sm font-bold text-ink-900">Day {d.day_number} · {d.title} <span className="font-normal text-ink-500">{fmtDate(d.date, 'EEE d MMM')}</span></p>
                  <ul className="mt-1.5 space-y-1 text-sm">{d.items.map((i) => <li key={i.id} className="flex justify-between gap-2"><span className="text-ink-700">{i.start_time && <span className="mr-1.5 font-mono text-xs text-ink-400">{i.start_time}</span>}{i.title}{!i.is_available && <span className="ml-1 text-xs font-semibold text-warning-700">(unavailable)</span>}</span><span className="shrink-0 font-semibold">{money(i.total_price)}</span></li>)}
                    {!d.items.length && <li className="text-ink-400">Free day</li>}</ul>
                </li>
              ))}
            </ol>
          </section>

          <section className="card p-6">
            <h2 className="font-sans text-lg font-bold">Lead traveller & contact</h2>
            <div className="mt-4 grid gap-4 sm:grid-cols-2">
              <Input label="Full name" {...register('contact_name')} error={errors.contact_name?.message} />
              <Input label="Email" type="email" {...register('contact_email')} error={errors.contact_email?.message} />
              <Input label="Mobile number" type="tel" {...register('contact_phone')} error={errors.contact_phone?.message} />
            </div>
            <h3 className="mt-6 flex items-center gap-2 font-sans text-sm font-bold"><Users className="h-4 w-4" /> Travellers (as on passport / ID)</h3>
            <div className="mt-3 space-y-3">
              {fields.map((f, i) => (
                <div key={f.id} className="grid gap-3 rounded-xl border border-ink-200 p-3 sm:grid-cols-[1.5fr_0.7fr_0.6fr_1fr]">
                  <Input label={`${f.traveler_type === 'adult' ? 'Adult' : 'Child'} ${i + 1}`} {...register(`travelers.${i}.full_name` as const)} error={errors.travelers?.[i]?.full_name?.message} />
                  <Select label="Type" {...register(`travelers.${i}.traveler_type` as const)} disabled><option value="adult">Adult</option><option value="child">Child</option></Select>
                  <Input label="Age" type="number" min={0} max={120} {...register(`travelers.${i}.age` as const)} error={errors.travelers?.[i]?.age?.message} />
                  <Input label="Nationality" {...register(`travelers.${i}.nationality` as const)} />
                </div>
              ))}
            </div>
            {errors.travelers?.message && <p className="mt-2 text-sm text-danger-600">{errors.travelers.message}</p>}
            <Textarea wrapClassName="mt-4" label="Special requests (optional)" {...register('special_requests')} placeholder="Dietary needs, celebrations, accessibility…" />
          </section>

          <section className="card space-y-5 p-6">
            <div>
              <label className="label" htmlFor="t-coupon">Coupon code</label>
              <div className="flex gap-2">
                <input id="t-coupon" className="input uppercase" value={couponInput} onChange={(e) => setCouponInput(e.target.value.toUpperCase())} placeholder="e.g. TRIP15" />
                {coupon ? <Button variant="outline" onClick={() => { setCoupon(null); setCouponInput(''); }}>Remove</Button> : <Button variant="secondary" onClick={() => couponInput && setCoupon(couponInput)} icon={<Tag className="h-4 w-4" />}>Apply</Button>}
              </div>
              {coupon && quote.isError && <p role="alert" className="mt-1.5 text-xs font-medium text-danger-600">{(quote.error as Error).message}</p>}
              {q?.coupon_code && <p className="mt-1.5 text-xs font-semibold text-success-700">Coupon {q.coupon_code} applied — you save {money(q.discount_amount)}</p>}
            </div>
            <GatewayPicker value={gateway} onChange={setGateway} />
            <Checkbox {...register('accept_terms')} error={errors.accept_terms?.message} label="I agree to the booking terms and the trip cancellation policy (free cancellation up to 72 hours before departure)." />
          </section>
        </form>

        <aside className="lg:sticky lg:top-24 lg:self-start">
          <div className="card p-6">
            <h2 className="font-sans text-lg font-bold">Price summary</h2>
            <p className="mt-1 flex items-center gap-2 text-sm text-ink-600"><CalendarDays className="h-4 w-4" />{fmtDate(t.start_date)} – {fmtDate(t.end_date)} · {t.travelers} travellers</p>
            <div className="mt-4">
              {quote.isLoading ? <Skeleton className="h-48" /> : q ? (
                <>
                  {!q.bookable && (
                    <div role="alert" className="mb-4 rounded-xl bg-warning-50 p-3 text-sm text-warning-700">
                      <p className="flex items-center gap-1.5 font-semibold"><AlertTriangle className="h-4 w-4" /> Some items are unavailable</p>
                      <ul className="mt-1 list-inside list-disc">{q.unavailable_items.map((u) => <li key={u.id}>{u.title}: {u.note}</li>)}</ul>
                      <Link to={`/tour-builder/${id}`} className="mt-2 inline-block font-semibold underline">Fix in Tour Builder</Link>
                    </div>
                  )}
                  <LineItems total={q.total_amount} items={[
                    ...Object.entries(q.groups).filter(([, v]) => v > 0).map(([k, v]) => ({ label: GROUP_LABELS[k] ?? k, amount: v })),
                    ...(q.discount_amount > 0 ? [{ label: `Coupon ${q.coupon_code}`, amount: -q.discount_amount }] : []),
                    { label: `Taxes (${q.tax_percent}% GST)`, amount: q.tax_amount },
                    { label: `Service fee (${q.service_fee_percent}%)`, amount: q.service_fee },
                  ]} />
                </>
              ) : quote.isError && !coupon ? <ErrorState message={(quote.error as Error).message} /> : null}
            </div>
            <Button type="submit" form="trip-checkout" variant="accent" size="lg" className="mt-5 w-full" loading={book.isPending} disabled={!q || !q.bookable || !gateway}>
              {q ? `Pay ${money(q.total_amount, { exact: true })}` : 'Pay now'}
            </Button>
            <TrustNote />
          </div>
        </aside>
      </div>
    </div>
  );
}
