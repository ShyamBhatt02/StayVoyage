import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery } from '@tanstack/react-query';
import { toast } from 'sonner';
import { CalendarRange, Crown, Moon, Tag } from 'lucide-react';
import { get, post } from '@/lib/api';
import { fmtDate, money } from '@/lib/format';
import { startPayment } from '@/lib/payments';
import { useConfig } from '@/hooks/useConfig';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { Button } from '@/components/ui/Button';
import { ErrorState, Skeleton, Switch } from '@/components/ui/misc';
import { GatewayPicker, LineItems, Steps, TrustNote } from '@/components/checkout';
import type { Checkout, Membership, MembershipPlan } from '@/types';

interface Quote {
  plan_id: number; plan_name: string; currency: string; price: number; discount_amount: number; coupon_code: string | null;
  tax_percent: number; tax_amount: number; total_amount: number; included_nights: number; duration_days: number;
  is_renewal: boolean; starts_at: string; expires_at: string;
}

export default function MembershipCheckout() {
  usePageMeta('Join StayVoyage Membership');
  const { planId = '' } = useParams();
  const navigate = useNavigate();
  const { data: config } = useConfig();
  const [couponInput, setCouponInput] = useState('');
  const [coupon, setCoupon] = useState<string | null>(null);
  const [autoRenew, setAutoRenew] = useState(true);
  const [accept, setAccept] = useState(false);
  const [gateway, setGateway] = useState('');
  useEffect(() => { if (!gateway && config?.payment_gateways.length) setGateway(config.payment_gateways.find((g) => g.default)?.name ?? config.payment_gateways[0]!.name); }, [config, gateway]);

  const plans = useQuery({ queryKey: ['public', 'plans'], queryFn: () => get<{ plans: MembershipPlan[] }>('/membership/plans') });
  const plan = plans.data?.plans.find((p) => String(p.id) === planId);
  const quote = useQuery({
    queryKey: ['membership-quote', planId, coupon],
    queryFn: () => post<Quote>('/membership/quote', { membership_plan_id: Number(planId), coupon_code: coupon }).then((r) => r.data),
    retry: false,
  });

  const buy = useMutation({
    mutationFn: () => post<{ membership: Membership; checkout: Checkout | null }>('/membership/purchase', {
      membership_plan_id: Number(planId), coupon_code: coupon, auto_renew: autoRenew, gateway, accept_terms: accept,
    }),
    onSuccess: async ({ data, message }) => {
      toast.success(message);
      if (data.checkout) await startPayment(data.checkout, navigate);
      else navigate('/account/membership');
    },
    onError: (e) => toast.error((e as Error).message),
  });

  if (plans.isError) return <div className="container-page py-16"><ErrorState onRetry={() => plans.refetch()} /></div>;

  return (
    <div className="container-page py-8">
      <Steps steps={['Choose plan', 'Review & pay', 'Activated']} current={1} />
      <h1 className="mt-6 font-display text-3xl font-semibold text-ink-900">{quote.data?.is_renewal ? 'Renew your membership' : 'Join StayVoyage Membership'}</h1>
      <div className="mt-6 grid gap-8 lg:grid-cols-[1fr_400px]">
        <div className="space-y-6">
          <section className="card p-6">
            {!plan ? <Skeleton className="h-32" /> : (
              <div className="flex flex-wrap items-start gap-5">
                <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-sand-100 text-sand-700"><Crown className="h-7 w-7" /></span>
                <div className="flex-1">
                  <h2 className="font-display text-2xl font-semibold text-ink-900">{plan.name}</h2>
                  <p className="text-ink-600">{plan.tagline}</p>
                  <ul className="mt-4 grid gap-2 text-sm text-ink-700 sm:grid-cols-2">
                    {plan.features.map((f) => <li key={f}>• {f}</li>)}
                  </ul>
                </div>
              </div>
            )}
          </section>
          {quote.data && (
            <section className="card grid gap-4 p-6 sm:grid-cols-3">
              <div className="flex gap-3"><Moon className="h-5 w-5 text-brand-600" /><div><p className="text-xs text-ink-500">Nights included</p><p className="font-bold">{quote.data.included_nights}</p></div></div>
              <div className="flex gap-3"><CalendarRange className="h-5 w-5 text-brand-600" /><div><p className="text-xs text-ink-500">Starts</p><p className="font-bold">{fmtDate(quote.data.starts_at)}</p></div></div>
              <div className="flex gap-3"><CalendarRange className="h-5 w-5 text-brand-600" /><div><p className="text-xs text-ink-500">Expires</p><p className="font-bold">{fmtDate(quote.data.expires_at)}</p></div></div>
              {quote.data.is_renewal && <p className="sm:col-span-3 rounded-xl bg-brand-50 p-3 text-sm text-brand-800">You already have an active membership — this renewal starts the moment it ends, so you don't lose a single day.</p>}
            </section>
          )}
          <section className="card space-y-5 p-6">
            <div>
              <label className="label" htmlFor="m-coupon">Coupon code</label>
              <div className="flex gap-2">
                <input id="m-coupon" className="input uppercase" value={couponInput} onChange={(e) => setCouponInput(e.target.value.toUpperCase())} placeholder="e.g. MEMBER1000" />
                {coupon ? <Button variant="outline" onClick={() => { setCoupon(null); setCouponInput(''); }}>Remove</Button>
                  : <Button variant="secondary" onClick={() => couponInput && setCoupon(couponInput)} icon={<Tag className="h-4 w-4" />}>Apply</Button>}
              </div>
              {coupon && quote.isError && <p role="alert" className="mt-1.5 text-xs font-medium text-danger-600">{(quote.error as Error).message}</p>}
              {quote.data?.coupon_code && <p className="mt-1.5 text-xs font-semibold text-success-700">Coupon {quote.data.coupon_code} applied</p>}
            </div>
            {plan?.auto_renew_available && <Switch checked={autoRenew} onChange={setAutoRenew} label="Remind me to renew 3 days before expiry (auto-renew)" />}
            <GatewayPicker value={gateway} onChange={setGateway} />
            <label className="flex cursor-pointer items-start gap-2.5 text-sm text-ink-700">
              <input type="checkbox" checked={accept} onChange={(e) => setAccept(e.target.checked)} className="mt-0.5 h-[18px] w-[18px] accent-brand-600" />
              <span>I understand the <Link to="/membership" className="link">membership rules</Link>, including that unused nights lapse at the end of the period.</span>
            </label>
          </section>
        </div>
        <aside className="lg:sticky lg:top-24 lg:self-start">
          <div className="card p-6">
            <h2 className="font-sans text-lg font-bold text-ink-900">Order summary</h2>
            <div className="mt-4">
              {quote.isLoading ? <Skeleton className="h-36" /> : quote.data ? (
                <LineItems total={quote.data.total_amount} items={[
                  { label: `${quote.data.plan_name} (${quote.data.duration_days} days)`, amount: quote.data.price },
                  ...(quote.data.discount_amount > 0 ? [{ label: `Coupon ${quote.data.coupon_code}`, amount: -quote.data.discount_amount }] : []),
                  { label: `GST (${quote.data.tax_percent}%)`, amount: quote.data.tax_amount },
                ]} />
              ) : quote.isError && !coupon ? <ErrorState message={(quote.error as Error).message} /> : null}
            </div>
            <Button variant="accent" size="lg" className="mt-5 w-full" disabled={!quote.data || !accept || !gateway} loading={buy.isPending} onClick={() => buy.mutate()}>
              {quote.data ? `Pay ${money(quote.data.total_amount, { exact: true })}` : 'Pay now'}
            </Button>
            {!accept && <p className="mt-2 text-center text-xs text-ink-500">Accept the membership rules to continue.</p>}
            <TrustNote />
          </div>
        </aside>
      </div>
    </div>
  );
}
