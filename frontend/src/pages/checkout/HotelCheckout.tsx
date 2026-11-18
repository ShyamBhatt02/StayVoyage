import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useFieldArray, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { toast } from 'sonner';
import { AlertTriangle, CalendarDays, Crown, Moon, Tag, Users } from 'lucide-react';
import { ApiError, post } from '@/lib/api';
import { fmtDate, money, plural } from '@/lib/format';
import { startPayment } from '@/lib/payments';
import { useAuth } from '@/providers/AuthProvider';
import { useConfig } from '@/hooks/useConfig';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { Button } from '@/components/ui/Button';
import { Checkbox, Input, Textarea } from '@/components/ui/Field';
import { Badge, ErrorState, Img, Skeleton } from '@/components/ui/misc';
import { GatewayPicker, LineItems, Steps, TrustNote } from '@/components/checkout';
import type { Booking, Checkout, HotelQuote } from '@/types';

const schema = z.object({
  contact_name: z.string().trim().min(2, 'Enter the lead guest name').max(80),
  contact_email: z.string().trim().email('Enter a valid email'),
  contact_phone: z.string().trim().regex(/^\+?[\d\s-]{10,18}$/, 'Enter a valid phone number'),
  special_requests: z.string().max(1000).optional(),
  guests: z.array(z.object({ full_name: z.string().trim().min(2, 'Enter full name').max(80) })),
  accept_terms: z.literal(true, { errorMap: () => ({ message: 'Please accept the booking terms' }) }),
});
type Form = z.infer<typeof schema>;

export default function HotelCheckout() {
  usePageMeta('Complete your booking');
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const { user } = useAuth();
  const { data: config } = useConfig();
  const roomId = Number(params.get('room'));
  const base = {
    hotel_room_id: roomId, check_in: params.get('check_in') ?? '', check_out: params.get('check_out') ?? '',
    rooms: Number(params.get('rooms') ?? 1), adults: Number(params.get('adults') ?? 2), children: Number(params.get('children') ?? 0),
  };
  const [type, setType] = useState<'membership' | 'regular'>(params.get('type') === 'membership' ? 'membership' : 'regular');
  const [couponInput, setCouponInput] = useState('');
  const [coupon, setCoupon] = useState<string | null>(null);
  const [gateway, setGateway] = useState('');
  useEffect(() => { if (!gateway && config?.payment_gateways.length) setGateway(config.payment_gateways.find((g) => g.default)?.name ?? config.payment_gateways[0]!.name); }, [config, gateway]);

  const quote = useQuery({
    queryKey: ['checkout-quote', base, type, coupon],
    queryFn: () => post<HotelQuote>('/hotels/quote', { ...base, booking_type: type, coupon_code: coupon }).then((r) => r.data),
    enabled: !!roomId && !!base.check_in,
    retry: false,
  });
  // Display info comes with the quote; keep the last successful one while switching booking type.
  const [display, setDisplay] = useState<HotelQuote['display'] | null>(null);
  useEffect(() => { if (quote.data?.display) setDisplay(quote.data.display); }, [quote.data]);
  const regularFallback = useQuery({
    queryKey: ['checkout-quote-display', roomId, base.check_in, base.check_out],
    queryFn: () => post<HotelQuote>('/hotels/quote', { ...base, booking_type: 'regular' }).then((r) => r.data),
    enabled: !!roomId && !!base.check_in && !display,
    retry: false,
  });
  const hotelInfo = display ?? regularFallback.data?.display ?? null;

  // If membership quote fails because user has no membership, fall back gracefully.
  const memberError = type === 'membership' && quote.error instanceof ApiError ? quote.error : null;

  const { register, control, handleSubmit, setError, formState: { errors } } = useForm<Form>({
    resolver: zodResolver(schema),
    defaultValues: {
      contact_name: user?.name ?? '', contact_email: user?.email ?? '', contact_phone: user?.phone ?? '', special_requests: '',
      guests: Array.from({ length: Math.max(1, base.adults + base.children) }, (_, i) => ({ full_name: i === 0 ? user?.name ?? '' : '' })),
      accept_terms: false as unknown as true,
    },
  });
  const { fields } = useFieldArray({ control, name: 'guests' });

  const book = useMutation({
    mutationFn: (v: Form) => post<{ booking: Booking; checkout: Checkout | null }>('/bookings/hotel', {
      ...base, booking_type: type, coupon_code: type === 'regular' ? coupon : null, gateway,
      expected_total: quote.data?.total_amount, contact_name: v.contact_name, contact_email: v.contact_email, contact_phone: v.contact_phone.replace(/[\s-]/g, ''),
      special_requests: v.special_requests, accept_terms: v.accept_terms,
      guests: v.guests.map((g, i) => ({ full_name: g.full_name, guest_type: i < base.adults ? 'adult' : 'child' })),
    }),
    onSuccess: async ({ data, message }) => {
      toast.success(message);
      if (data.checkout) await startPayment(data.checkout, navigate);
      else navigate(`/account/bookings/${data.booking.booking_number}?confirmed=1`);
    },
    onError: (e) => {
      if (e instanceof ApiError) {
        Object.entries(e.errors).forEach(([k, v]) => { if (Array.isArray(v)) setError(k as keyof Form, { message: v[0] }); });
        if (e.code === 'price_changed') quote.refetch();
        toast.error(e.message);
      } else toast.error('Booking failed. Please try again.');
    },
  });

  const items = useMemo(() => quote.data?.line_items ?? [], [quote.data]);
  if (!roomId || !base.check_in) return <div className="container-page py-16"><ErrorState message="Your booking details are missing. Please choose a room again." /></div>;

  return (
    <div className="container-page py-8">
      <Steps steps={['Select room', 'Guest details', 'Review & pay', 'Confirmation']} current={1} />
      <h1 className="mt-6 font-display text-3xl font-semibold text-ink-900">Complete your booking</h1>

      <div className="mt-6 grid gap-8 lg:grid-cols-[1fr_400px]">
        <form id="checkout" onSubmit={handleSubmit((v) => book.mutate(v))} noValidate className="space-y-6">
          {/* Booking type */}
          <section className="card p-6">
            <h2 className="font-sans text-lg font-bold text-ink-900">How would you like to book?</h2>
            <div className="mt-4 grid gap-3 sm:grid-cols-2">
              {(['regular', 'membership'] as const).map((t) => (
                <label key={t} className={`flex cursor-pointer gap-3 rounded-2xl border p-4 transition ${type === t ? 'border-brand-500 bg-brand-50/50 ring-2 ring-brand-500/15' : 'border-ink-200 hover:border-ink-300'}`}>
                  <input type="radio" name="booking_type" className="mt-1 accent-brand-600" checked={type === t} onChange={() => { setType(t); setCoupon(null); }} />
                  <span>
                    <span className="flex items-center gap-1.5 font-semibold text-ink-900">{t === 'membership' && <Crown className="h-4 w-4 text-sand-600" />}{t === 'membership' ? 'Membership booking' : 'Regular paid booking'}</span>
                    <span className="mt-0.5 block text-sm text-ink-500">{t === 'membership' ? 'Use night credits; pay only taxes & a booking fee.' : 'Pay the room rate plus taxes & fees.'}</span>
                  </span>
                </label>
              ))}
            </div>
            {memberError && (
              <div role="alert" className="mt-4 flex gap-3 rounded-xl bg-warning-50 p-4 text-sm text-warning-700">
                <AlertTriangle className="h-5 w-5 shrink-0" />
                <div>
                  <p className="font-semibold">{memberError.message}</p>
                  <p className="mt-1">{memberError.code === 'membership_required' || memberError.code === 'membership_expired'
                    ? <><Link to="/membership" className="font-semibold underline">Get a membership</Link> or switch to a regular booking.</>
                    : 'You can still book this stay as a regular paid booking.'}</p>
                </div>
              </div>
            )}
          </section>

          {/* Guests */}
          <section className="card p-6">
            <h2 className="font-sans text-lg font-bold text-ink-900">Lead guest & contact</h2>
            <div className="mt-4 grid gap-4 sm:grid-cols-2">
              <Input label="Full name" required autoComplete="name" {...register('contact_name')} error={errors.contact_name?.message} />
              <Input label="Email" type="email" required autoComplete="email" {...register('contact_email')} error={errors.contact_email?.message} hint="We'll send your confirmation here" />
              <Input label="Mobile number" type="tel" required autoComplete="tel" {...register('contact_phone')} error={errors.contact_phone?.message} />
            </div>
            <h3 className="mt-6 font-sans text-sm font-bold text-ink-900">Guest names (as on ID)</h3>
            <div className="mt-3 grid gap-3 sm:grid-cols-2">
              {fields.map((f, i) => (
                <Input key={f.id} label={i < base.adults ? `Adult ${i + 1}` : `Child ${i - base.adults + 1}`} {...register(`guests.${i}.full_name` as const)} error={errors.guests?.[i]?.full_name?.message} />
              ))}
            </div>
            <Textarea wrapClassName="mt-4" label="Special requests (optional)" placeholder="e.g. high floor, early check-in, anniversary celebration" {...register('special_requests')} error={errors.special_requests?.message} />
          </section>

          {/* Payment */}
          <section className="card space-y-5 p-6">
            {type === 'regular' && (
              <div>
                <label className="label" htmlFor="coupon">Coupon code</label>
                <div className="flex gap-2">
                  <input id="coupon" className="input uppercase" value={couponInput} onChange={(e) => setCouponInput(e.target.value.toUpperCase())} placeholder="e.g. WELCOME10" />
                  {coupon ? <Button variant="outline" onClick={() => { setCoupon(null); setCouponInput(''); }}>Remove</Button>
                    : <Button variant="secondary" onClick={() => couponInput.trim() && setCoupon(couponInput.trim())} icon={<Tag className="h-4 w-4" />}>Apply</Button>}
                </div>
                {coupon && quote.isError && <p role="alert" className="mt-1.5 text-xs font-medium text-danger-600">{(quote.error as Error).message}</p>}
                {coupon && quote.data?.coupon_code && <p className="mt-1.5 text-xs font-semibold text-success-700">Coupon {quote.data.coupon_code} applied — you save {money(quote.data.discount_amount)}</p>}
              </div>
            )}
            <GatewayPicker value={gateway} onChange={setGateway} />
            <Checkbox {...register('accept_terms')} error={errors.accept_terms?.message}
              label={<>I agree to the booking terms, the room's cancellation policy and the <Link to="/faq" className="link">privacy policy</Link>.</>} />
          </section>
        </form>

        {/* Summary */}
        <aside className="lg:sticky lg:top-24 lg:self-start">
          <div className="card overflow-hidden">
            {hotelInfo ? (
              <div className="flex gap-4 border-b border-ink-100 p-5">
                <Img src={hotelInfo.image_url} alt="" className="h-20 w-24 shrink-0 rounded-xl" />
                <div className="min-w-0">
                  <Link to={`/hotels/${hotelInfo.hotel_slug}`} className="block truncate font-display text-lg font-semibold text-ink-900 hover:text-brand-700">{hotelInfo.hotel_name}</Link>
                  <p className="text-sm text-ink-600">{hotelInfo.room_name} · {hotelInfo.bed_type} bed</p>
                  <p className="text-xs text-ink-500">{hotelInfo.city} · Check-in from {hotelInfo.check_in_time}</p>
                </div>
              </div>
            ) : <Skeleton className="m-5 h-20" />}
            <div className="space-y-2 border-b border-ink-100 p-5 text-sm text-ink-700">
              <p className="flex items-center gap-2"><CalendarDays className="h-4 w-4 text-brand-600" />{fmtDate(base.check_in, 'EEE d MMM')} → {fmtDate(base.check_out, 'EEE d MMM yyyy')}</p>
              <p className="flex items-center gap-2"><Moon className="h-4 w-4 text-brand-600" />{plural(quote.data?.nights ?? 0, 'night')} · {plural(base.rooms, 'room')}</p>
              <p className="flex items-center gap-2"><Users className="h-4 w-4 text-brand-600" />{plural(base.adults, 'adult')}{base.children ? `, ${plural(base.children, 'child', 'children')}` : ''}</p>
            </div>
            <div className="p-5">
              {quote.isLoading ? <Skeleton className="h-40" /> : quote.data ? (
                <>
                  {quote.data.booking_type === 'membership' && quote.data.membership && (
                    <div className="mb-4 rounded-xl bg-sand-50 p-3 text-sm ring-1 ring-sand-200">
                      <p className="flex items-center gap-1.5 font-semibold text-sand-800"><Crown className="h-4 w-4" /> {plural(quote.data.membership_nights_used, 'membership night')} will be used</p>
                      <p className="mt-0.5 text-sand-900/80">Balance: {quote.data.membership.balance_before} → <strong>{quote.data.membership.balance_after}</strong> nights</p>
                    </div>
                  )}
                  {!quote.data.is_available && <p role="alert" className="mb-4 rounded-xl bg-danger-50 p-3 text-sm font-semibold text-danger-700">This room is no longer available for your dates.</p>}
                  <LineItems items={items} total={quote.data.total_amount} note="Prices include all mandatory fees. Taxes are calculated by StayVoyage." />
                  {quote.data.booking_type === 'membership' && <Badge tone="sand" className="mt-3">Room amount {money(quote.data.room_amount)} covered by membership</Badge>}
                </>
              ) : quote.isError && !memberError ? <ErrorState message={(quote.error as Error).message} onRetry={() => quote.refetch()} /> : <p className="text-sm text-ink-500">Choose a booking option to see the price.</p>}
              <Button type="submit" form="checkout" variant="accent" size="lg" className="mt-5 w-full" loading={book.isPending}
                disabled={!quote.data || !quote.data.is_available || !gateway}>
                {quote.data ? (quote.data.total_amount > 0 ? `Pay ${money(quote.data.total_amount, { exact: true })}` : 'Confirm booking') : 'Pay now'}
              </Button>
              <TrustNote />
            </div>
          </div>
        </aside>
      </div>
    </div>
  );
}
