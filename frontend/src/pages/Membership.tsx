import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { BadgeCheck, CalendarX2, Clock, Crown, Hotel, Moon, Receipt, RotateCcw, Users } from 'lucide-react';
import { get } from '@/lib/api';
import { fmtDate, money } from '@/lib/format';
import { useAuth } from '@/providers/AuthProvider';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { ButtonLink } from '@/components/ui/Button';
import { Badge, ErrorState, Skeleton } from '@/components/ui/misc';
import type { Membership as MembershipT, MembershipPlan } from '@/types';

export default function MembershipPage() {
  usePageMeta('StayVoyage Monthly Membership — 30 hotel nights', 'One monthly membership, up to 30 nights at eligible StayVoyage hotels.');
  const { user } = useAuth();
  const plans = useQuery({ queryKey: ['public', 'plans'], queryFn: () => get<{ plans: MembershipPlan[]; eligible_hotels: number }>('/membership/plans') });
  const current = useQuery({
    queryKey: ['membership', 'current'],
    queryFn: () => get<{ membership: MembershipT | null; queued_renewal: MembershipT | null }>('/membership/current'),
    enabled: !!user,
  });
  const active = current.data?.membership?.is_usable ? current.data.membership : null;
  const featured = plans.data?.plans.find((p) => p.is_featured) ?? plans.data?.plans[0];

  return (
    <>
      <section className="relative isolate overflow-hidden bg-brand-950 text-white">
        <img src="/storage/seed/hotels/cliffside-pool.jpg" alt="" className="absolute inset-0 -z-10 h-full w-full object-cover opacity-35" />
        <div className="absolute inset-0 -z-10 bg-gradient-to-r from-brand-950 via-brand-950/80 to-transparent" />
        <div className="container-page py-20 sm:py-28">
          <Badge tone="sand" icon={<Crown className="h-3.5 w-3.5" />}>StayVoyage Membership</Badge>
          <h1 className="mt-5 max-w-2xl font-display text-4xl font-semibold leading-tight sm:text-6xl">Up to 30 hotel nights. Every month.</h1>
          <p className="mt-5 max-w-xl text-lg text-brand-100">One membership, {plans.data?.eligible_hotels ?? 'dozens of'} participating hotels. Cover the room rate with night credits and pay only taxes and a small booking fee when you stay.</p>
          {active ? (
            <div className="mt-8 inline-flex flex-wrap items-center gap-4 rounded-2xl bg-white/10 p-4 ring-1 ring-white/20 backdrop-blur">
              <div><p className="text-sm text-brand-200">Your {active.plan_name}</p><p className="text-2xl font-bold">{active.remaining_nights} of {active.total_nights} nights left</p><p className="text-sm text-brand-200">Expires {fmtDate(active.expires_at)}</p></div>
              <ButtonLink to="/account/membership" variant="white">Open wallet</ButtonLink>
            </div>
          ) : (
            <div className="mt-8 flex flex-wrap gap-3">
              <a href="#plans" className="inline-flex h-12 items-center rounded-xl bg-coral-500 px-6 font-semibold text-white hover:bg-coral-600">See plans</a>
              <ButtonLink to="/hotels?membership=1" variant="white" size="lg">Browse member hotels</ButtonLink>
            </div>
          )}
        </div>
      </section>

      {/* Example calculation */}
      <section className="container-page py-20">
        <div className="grid items-center gap-12 lg:grid-cols-2">
          <div>
            <p className="eyebrow">How nights work</p>
            <h2 className="section-title mt-2">You pay the taxes. Your membership pays the room.</h2>
            <p className="mt-4 text-ink-600">Each night in each room uses one credit. Your membership covers the room rate; GST on the stay and a flat booking fee are charged at checkout. Every credit is tracked in a transparent ledger in your wallet.</p>
            <ul className="mt-6 space-y-3 text-ink-700">
              {['Book a stay at any “Membership Eligible” room', 'Nights are deducted the moment your booking is placed', 'Cancel before the deadline and nights return instantly', 'Unused nights lapse when the membership period ends'].map((t) => (
                <li key={t} className="flex gap-2.5"><BadgeCheck className="mt-0.5 h-5 w-5 shrink-0 text-brand-600" />{t}</li>
              ))}
            </ul>
          </div>
          <div className="card p-6 sm:p-8">
            <p className="text-sm font-semibold text-ink-500">Example — 4-night stay</p>
            <dl className="mt-4 space-y-3 text-sm">
              {[
                ['Nights', '4'], ['Membership nights used', '4'], ['Hotel room amount', money(20000)], ['Covered by membership', `− ${money(20000)}`],
                ['Taxes (GST)', money(2000)], ['Booking fee', money(500)],
              ].map(([k, v]) => <div key={k} className="flex justify-between"><dt className="text-ink-600">{k}</dt><dd className={`font-semibold ${v.startsWith('−') ? 'text-success-700' : 'text-ink-900'}`}>{v}</dd></div>)}
            </dl>
            <div className="mt-4 flex items-end justify-between border-t border-ink-200 pt-4"><span className="font-semibold">Total payable</span><span className="text-3xl font-bold text-brand-700">{money(2500)}</span></div>
            <p className="mt-2 text-xs text-ink-500">Illustrative. Exact taxes and fees are calculated at checkout.</p>
          </div>
        </div>
      </section>

      {/* Plans */}
      <section id="plans" className="bg-white py-20">
        <div className="container-page">
          <div className="mx-auto max-w-2xl text-center"><p className="eyebrow">Plans</p><h2 className="section-title mt-2">Choose your membership</h2></div>
          {plans.isError && <ErrorState className="mt-10" onRetry={() => plans.refetch()} />}
          <div className="mx-auto mt-10 grid max-w-5xl gap-6 md:grid-cols-2">
            {plans.isLoading ? <><Skeleton className="h-[560px] rounded-3xl" /><Skeleton className="h-[560px] rounded-3xl" /></> : plans.data?.plans.map((p) => (
              <article key={p.id} className={`relative flex flex-col rounded-3xl border p-8 ${p.id === featured?.id ? 'border-brand-600 bg-brand-950 text-white shadow-lift' : 'border-ink-200 bg-white'}`}>
                {p.id === featured?.id && <span className="absolute -top-3 left-8 rounded-full bg-coral-500 px-3 py-1 text-xs font-bold uppercase tracking-wide text-white">Most popular</span>}
                <h3 className="font-display text-2xl font-semibold">{p.name}</h3>
                <p className={p.id === featured?.id ? 'mt-1 text-brand-200' : 'mt-1 text-ink-500'}>{p.tagline}</p>
                <p className="mt-6 text-5xl font-bold">{money(p.price)}</p>
                <p className={p.id === featured?.id ? 'text-brand-200' : 'text-ink-500'}>for {p.duration_days} days · + GST</p>
                <div className={`mt-6 grid grid-cols-3 gap-3 rounded-2xl p-4 text-center ${p.id === featured?.id ? 'bg-white/10' : 'bg-ink-50'}`}>
                  <div><p className="text-2xl font-bold">{p.included_nights}</p><p className="text-xs opacity-75">nights</p></div>
                  <div><p className="text-2xl font-bold">{p.max_nights_per_booking}</p><p className="text-xs opacity-75">max / stay</p></div>
                  <div><p className="text-2xl font-bold">{money(p.price_per_night ?? 0)}</p><p className="text-xs opacity-75">per night</p></div>
                </div>
                <ul className="mt-6 flex-1 space-y-3">
                  {p.features.map((f) => <li key={f} className="flex gap-2.5 text-sm"><BadgeCheck className={`mt-0.5 h-5 w-5 shrink-0 ${p.id === featured?.id ? 'text-sand-300' : 'text-brand-600'}`} />{f}</li>)}
                </ul>
                <ButtonLink to={user ? `/checkout/membership/${p.id}` : `/login?redirect=${encodeURIComponent(`/checkout/membership/${p.id}`)}`}
                  variant={p.id === featured?.id ? 'accent' : 'primary'} size="lg" className="mt-8 w-full">
                  {active ? 'Renew / extend' : `Join ${p.name}`}
                </ButtonLink>
                {active && <p className={`mt-2 text-center text-xs ${p.id === featured?.id ? 'text-brand-200' : 'text-ink-500'}`}>Your new period starts when your current one ends ({fmtDate(active.expires_at)}).</p>}
              </article>
            ))}
          </div>
        </div>
      </section>

      {/* Rules */}
      {featured && (
        <section className="container-page py-20">
          <h2 className="section-title">Membership rules, clearly</h2>
          <p className="mt-2 text-ink-600">The rules below apply to {featured.name}. They're frozen at the moment you buy — later changes never affect your plan.</p>
          <div className="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {[
              [Moon, 'Nights per booking', `Up to ${featured.max_nights_per_booking} consecutive nights per membership booking.`],
              [Users, 'Rooms & guests', `${featured.max_rooms_per_booking} room and up to ${featured.max_guests_per_booking} guests per booking.`],
              [Clock, 'Booking window', `Book ${featured.min_advance_booking_days ? `${featured.min_advance_booking_days}+ days ahead` : 'as late as today'} and up to ${featured.max_advance_booking_days} days in advance, within your membership period.`],
              [RotateCcw, 'Cancellation', `Cancel up to ${featured.cancellation_deadline_hours} hours before check-in and your nights return instantly. Later: ${featured.late_cancellation_policy === 'forfeit_nights' ? 'nights and fees are forfeited' : 'nights return, fees are kept'}.`],
              [Hotel, 'Eligible rooms', `${featured.eligible_room_types?.length ? featured.eligible_room_types.map((t) => t[0]!.toUpperCase() + t.slice(1)).join(', ') : 'All'} rooms at hotels marked “Membership Eligible”.`],
              [Receipt, 'What you pay', `${featured.charge_taxes_on_covered_amount ? 'GST on the covered stay' : 'No taxes'} plus a flat booking fee per stay.`],
            ].map(([Icon, t, b]) => {
              const I = Icon as typeof Moon;
              return <div key={t as string} className="card p-5"><I className="h-6 w-6 text-brand-600" /><h3 className="mt-3 font-sans font-semibold text-ink-900">{t as string}</h3><p className="mt-1 text-sm text-ink-600">{b as string}</p></div>;
            })}
          </div>
          {!!featured.blackout_dates?.length && (
            <div className="card mt-6 flex flex-wrap items-start gap-4 p-5">
              <CalendarX2 className="h-6 w-6 text-coral-600" />
              <div>
                <h3 className="font-sans font-semibold text-ink-900">Blackout dates</h3>
                <ul className="mt-1 space-y-0.5 text-sm text-ink-600">{featured.blackout_dates.map((b) => <li key={b.id}>{fmtDate(b.start_date)} – {fmtDate(b.end_date)}{b.reason ? ` · ${b.reason}` : ''}</li>)}</ul>
                <p className="mt-1 text-xs text-ink-500">You can still book these dates as a regular stay.</p>
              </div>
            </div>
          )}
          <p className="mt-6 text-sm text-ink-500">Have a question? <Link to="/faq" className="link">Read the membership FAQ</Link>.</p>
        </section>
      )}
    </>
  );
}
