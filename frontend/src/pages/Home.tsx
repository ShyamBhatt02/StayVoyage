import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ArrowRight, BadgeCheck, CalendarCheck, Crown, Hotel, MapPinned, Moon, Quote, Route, Search, ShieldCheck, Sparkles, Wallet } from 'lucide-react';
import { get } from '@/lib/api';
import { money } from '@/lib/format';
import { SearchBar } from '@/components/search/SearchBar';
import { ButtonLink } from '@/components/ui/Button';
import { Avatar, CardSkeleton, ErrorState, SectionHeader, Skeleton, Stars } from '@/components/ui/misc';
import { DestinationCard, HotelCard, PackageCard } from '@/components/cards';
import { FaqList } from '@/pages/Faq';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import type { Destination, HotelCard as HotelCardT, MembershipPlan, Review, TourPackage } from '@/types';

interface HomeData {
  destinations: Destination[];
  featured_hotels: HotelCardT[];
  popular_tours: TourPackage[];
  reviews: Review[];
  faqs: { id: number; category: string; question: string; answer: string }[];
  membership_plan: MembershipPlan | null;
  stats: { hotels: number; destinations: number; membership_hotels: number; avg_rating: number };
}

export default function Home() {
  usePageMeta('StayVoyage — Book Stays. Build Trips. Travel Your Way.', 'Hand-picked hotels, up to 30 membership nights a month and a day-by-day Tour Builder.');
  const { data, isLoading, isError, refetch } = useQuery({ queryKey: ['public', 'home'], queryFn: () => get<HomeData>('/meta/home') });
  const plan = data?.membership_plan;

  return (
    <>
      {/* ---------------------------------------------------------------- Hero */}
      <section className="relative isolate overflow-hidden">
        <img src="/storage/seed/misc/hero-beach.jpg" alt="" className="absolute inset-0 -z-10 h-full w-full object-cover" fetchPriority="high" />
        <div className="absolute inset-0 -z-10 bg-gradient-to-b from-brand-950/70 via-brand-950/45 to-brand-950/80" />
        <div className="container-page pb-20 pt-36 sm:pb-28 sm:pt-44">
          <div className="max-w-3xl animate-fade-up">
            <p className="inline-flex items-center gap-2 rounded-full bg-white/15 px-3.5 py-1.5 text-xs font-bold uppercase tracking-widest text-white backdrop-blur">
              <Sparkles className="h-3.5 w-3.5 text-sand-300" /> Membership travel, reimagined
            </p>
            <h1 className="mt-5 font-display text-4xl font-semibold leading-[1.08] text-white sm:text-6xl">
              Book stays. Build trips. <span className="text-coral-300">Travel your way.</span>
            </h1>
            <p className="mt-5 max-w-xl text-lg text-white/85">
              Hand-picked hotels across India and beyond, up to 30 hotel nights a month with one membership, and a planner that turns ideas into a bookable itinerary.
            </p>
          </div>
          <div className="mt-10 animate-fade-up [animation-delay:120ms]">
            <SearchBar variant="hero" />
            <div className="mt-4 flex flex-wrap items-center gap-3">
              <ButtonLink to="/tour-builder" variant="white" icon={<Route className="h-4 w-4" />}>Build Your Trip</ButtonLink>
              <span className="text-sm text-white/80">or explore <Link to="/tours" className="font-semibold text-white underline-offset-4 hover:underline">ready-made tours</Link></span>
            </div>
          </div>
        </div>
      </section>

      {/* ---------------------------------------------------------------- Trust strip */}
      <section className="border-b border-ink-200 bg-white">
        <div className="container-page grid grid-cols-2 gap-6 py-6 md:grid-cols-4">
          {[
            [Hotel, data ? `${data.stats.hotels}+ hotels` : '—', 'Hand-picked & verified'],
            [MapPinned, data ? `${data.stats.destinations} destinations` : '—', 'India & international'],
            [Crown, data ? `${data.stats.membership_hotels} member hotels` : '—', 'Use your monthly nights'],
            [ShieldCheck, 'Secure payments', 'UPI, cards & netbanking'],
          ].map(([Icon, title, sub], i) => {
            const I = Icon as typeof Hotel;
            return (
              <div key={i} className="flex items-center gap-3">
                <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600"><I className="h-5 w-5" /></span>
                <div className="min-w-0"><p className="text-sm font-bold leading-tight text-ink-900 sm:text-base">{title as string}</p><p className="text-xs text-ink-500">{sub as string}</p></div>
              </div>
            );
          })}
        </div>
      </section>

      {isError && <div className="container-page py-12"><ErrorState onRetry={() => refetch()} /></div>}

      {/* ---------------------------------------------------------------- Membership promo */}
      <section className="container-page py-20">
        <div className="relative overflow-hidden rounded-[2rem] bg-gradient-to-br from-brand-700 via-brand-800 to-brand-950 p-8 text-white shadow-lift sm:p-12">
          <div className="absolute -right-24 -top-24 h-72 w-72 rounded-full bg-coral-500/25 blur-3xl" aria-hidden />
          <div className="absolute -bottom-32 left-1/3 h-72 w-72 rounded-full bg-sand-400/20 blur-3xl" aria-hidden />
          <div className="relative grid items-center gap-10 lg:grid-cols-[1.2fr_1fr]">
            <div>
              <p className="eyebrow text-sand-300">StayVoyage Membership</p>
              <h2 className="mt-3 font-display text-3xl font-semibold sm:text-5xl">30 hotel nights. One monthly membership.</h2>
              <p className="mt-4 max-w-xl text-brand-100">
                Join {plan?.name ?? 'StayVoyage Monthly'} and stay at participating hotels using night credits — pay only taxes and a small booking fee when you check in.
              </p>
              <div className="mt-8 flex flex-wrap gap-3">
                <ButtonLink to="/membership" variant="accent" size="lg" icon={<Crown className="h-5 w-5" />}>Explore membership</ButtonLink>
                <ButtonLink to="/hotels?membership=1" variant="white" size="lg">Browse member hotels</ButtonLink>
              </div>
            </div>
            <div className="rounded-3xl bg-white/10 p-6 backdrop-blur-md ring-1 ring-white/20">
              {plan ? (
                <>
                  <p className="text-sm font-semibold text-brand-100">{plan.name}</p>
                  <p className="mt-2 text-4xl font-bold">{money(plan.price)}<span className="text-base font-medium text-brand-200"> / {plan.duration_days} days</span></p>
                  <p className="mt-1 text-sm text-sand-200">That's about {money(plan.price_per_night ?? 0)} per night across {plan.included_nights} nights</p>
                  <ul className="mt-5 space-y-2.5">
                    {plan.features.slice(0, 5).map((f) => (
                      <li key={f} className="flex items-start gap-2.5 text-sm"><BadgeCheck className="mt-0.5 h-4.5 h-[18px] w-[18px] shrink-0 text-sand-300" />{f}</li>
                    ))}
                  </ul>
                </>
              ) : <Skeleton className="h-56 bg-white/20" />}
            </div>
          </div>
        </div>
      </section>

      {/* ---------------------------------------------------------------- Destinations */}
      <section className="container-page pb-20">
        <SectionHeader eyebrow="Where to next" title="Popular destinations" description="From Rajasthan's palaces to Maldivian lagoons — curated stays and experiences in every one."
          action={<ButtonLink to="/destinations" variant="outline" icon={<ArrowRight className="h-4 w-4" />}>All destinations</ButtonLink>} />
        <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
          {isLoading ? Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-64 rounded-3xl" />)
            : data?.destinations.slice(0, 8).map((d, i) => <div key={d.id} className={i === 0 ? 'sm:row-span-2' : ''}><DestinationCard d={d} tall={i === 0} /></div>)}
        </div>
      </section>

      {/* ---------------------------------------------------------------- Featured hotels */}
      <section className="bg-white py-20">
        <div className="container-page">
          <SectionHeader eyebrow="Stay somewhere special" title="Featured hotels" description="Five-star resorts, heritage havelis and beachfront hideaways our guests love."
            action={<ButtonLink to="/hotels" variant="outline" icon={<ArrowRight className="h-4 w-4" />}>Search all hotels</ButtonLink>} />
          <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
            {isLoading ? <CardSkeleton count={4} /> : data?.featured_hotels.slice(0, 8).map((h) => <HotelCard key={h.id} hotel={h} />)}
          </div>
        </div>
      </section>

      {/* ---------------------------------------------------------------- Tours */}
      <section className="container-page py-20">
        <SectionHeader eyebrow="Ready to go" title="Popular tours" description="Book them as they are — or tap “Customize This Tour” and make every day your own."
          action={<ButtonLink to="/tours" variant="outline" icon={<ArrowRight className="h-4 w-4" />}>All tours</ButtonLink>} />
        <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
          {isLoading ? <CardSkeleton count={3} /> : data?.popular_tours.map((t) => <PackageCard key={t.id} pkg={t} />)}
        </div>
      </section>

      {/* ---------------------------------------------------------------- How it works */}
      <section className="bg-brand-50/60 py-20">
        <div className="container-page">
          <SectionHeader eyebrow="Simple by design" title="How StayVoyage works" className="text-center sm:block [&>div]:mx-auto" />
          <ol className="grid gap-6 md:grid-cols-4">
            {[
              [Search, 'Search & compare', 'Filter hotels by price, rating, amenities and membership eligibility with live availability.'],
              [Wallet, 'Choose how to pay', 'Book as a regular paid stay, or use membership night credits and pay only taxes & a small fee.'],
              [Route, 'Build the whole trip', 'Add flights, transfers, activities, guides and meals day by day — priced live as you go.'],
              [CalendarCheck, 'Travel with confidence', 'Instant confirmation, downloadable invoices and easy cancellations from your dashboard.'],
            ].map(([Icon, title, body], i) => {
              const I = Icon as typeof Search;
              return (
                <li key={i} className="card relative p-6">
                  <span className="absolute right-5 top-5 font-display text-4xl font-semibold text-brand-100">{i + 1}</span>
                  <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-600 text-white"><I className="h-6 w-6" /></span>
                  <h3 className="mt-5 font-display text-lg font-semibold text-ink-900">{title as string}</h3>
                  <p className="mt-2 text-sm text-ink-600">{body as string}</p>
                </li>
              );
            })}
          </ol>
        </div>
      </section>

      {/* ---------------------------------------------------------------- Membership benefits */}
      <section className="container-page py-20">
        <div className="grid items-center gap-12 lg:grid-cols-2">
          <div className="relative">
            <img src="/storage/seed/hotels/infinity-deck.jpg" alt="Infinity pool deck overlooking the sea at a StayVoyage member resort" loading="lazy" className="h-[420px] w-full rounded-[2rem] object-cover shadow-lift" />
            <div className="absolute -bottom-6 -right-2 rounded-2xl bg-white p-4 shadow-lift sm:-right-6">
              <p className="text-xs font-semibold text-ink-500">Members used last month</p>
              <p className="flex items-center gap-2 text-2xl font-bold text-ink-900"><Moon className="h-5 w-5 text-sand-500" /> 12 nights avg.</p>
            </div>
          </div>
          <div>
            <p className="eyebrow">Membership benefits</p>
            <h2 className="section-title mt-2">More nights away, less spent on rooms</h2>
            <div className="mt-8 grid gap-5 sm:grid-cols-2">
              {[
                ['Up to 30 nights', 'Every month, at participating hotels in India, Dubai and Bali.'],
                ['Pay only taxes & fees', 'The room rate is covered — you pay GST and a flat booking fee.'],
                ['Flexible cancellation', 'Cancel up to 24 hours before check-in and your nights come straight back.'],
                ['A transparent wallet', 'Every night credited, used or returned is listed in your membership ledger.'],
              ].map(([t, b]) => (
                <div key={t} className="rounded-2xl border border-ink-200 bg-white p-5">
                  <BadgeCheck className="h-6 w-6 text-brand-600" />
                  <h3 className="mt-3 font-semibold text-ink-900">{t}</h3>
                  <p className="mt-1 text-sm text-ink-600">{b}</p>
                </div>
              ))}
            </div>
            <ButtonLink to="/membership" className="mt-8" icon={<Crown className="h-4 w-4" />}>See plans & rules</ButtonLink>
          </div>
        </div>
      </section>

      {/* ---------------------------------------------------------------- Tour builder CTA */}
      <section className="container-page pb-20">
        <div className="relative isolate overflow-hidden rounded-[2rem]">
          <img src="/storage/seed/misc/trip-planning.jpg" alt="" className="absolute inset-0 -z-10 h-full w-full object-cover" loading="lazy" />
          <div className="absolute inset-0 -z-10 bg-gradient-to-r from-ink-950/90 via-ink-950/70 to-ink-950/20" />
          <div className="max-w-xl p-8 text-white sm:p-14">
            <p className="eyebrow text-coral-300">Custom Tour Builder</p>
            <h2 className="mt-3 font-display text-3xl font-semibold sm:text-4xl">Your trip, day by day — priced live.</h2>
            <p className="mt-4 text-white/85">Drag in hotels from our inventory, add flights, private transfers, guided tours and dinners. Save it, share it on WhatsApp, and book everything in one checkout.</p>
            <div className="mt-8 flex flex-wrap gap-3">
              <ButtonLink to="/tour-builder" variant="accent" size="lg" icon={<Route className="h-5 w-5" />}>Start building</ButtonLink>
              <ButtonLink to="/tours" variant="white" size="lg">Customise a tour</ButtonLink>
            </div>
          </div>
        </div>
      </section>

      {/* ---------------------------------------------------------------- Reviews */}
      <section className="bg-white py-20">
        <div className="container-page">
          <SectionHeader eyebrow="Guest stories" title="Loved by travellers" description={data?.stats.avg_rating ? `Our hotels average ${data.stats.avg_rating} out of 5 from verified stays.` : undefined} />
          <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
            {isLoading ? Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-56 rounded-2xl" />)
              : data?.reviews.map((r) => (
                <figure key={r.id} className="card flex flex-col p-6">
                  <Quote className="h-7 w-7 text-brand-200" aria-hidden />
                  <Stars value={r.overall} className="mt-3" />
                  <blockquote className="mt-3 flex-1">
                    <p className="font-semibold text-ink-900">{r.title}</p>
                    <p className="mt-2 line-clamp-4 text-sm text-ink-600">{r.comment}</p>
                  </blockquote>
                  <figcaption className="mt-5 flex items-center gap-3 border-t border-ink-100 pt-4">
                    <Avatar name={r.author?.name} src={r.author?.avatar_url} />
                    <div>
                      <p className="text-sm font-semibold text-ink-900">{r.author?.name}</p>
                      <p className="text-xs text-ink-500">Stayed at <Link to={`/hotels/${r.hotel?.slug}`} className="link font-medium">{r.hotel?.name}</Link>{r.stay_month ? ` · ${r.stay_month}` : ''}</p>
                    </div>
                  </figcaption>
                </figure>
              ))}
          </div>
        </div>
      </section>

      {/* ---------------------------------------------------------------- FAQ */}
      <section className="container-page py-20">
        <div className="grid gap-10 lg:grid-cols-[1fr_1.6fr]">
          <div>
            <p className="eyebrow">Questions?</p>
            <h2 className="section-title mt-2">Frequently asked</h2>
            <p className="mt-3 text-ink-600">Everything about membership nights, bookings, payments and cancellations.</p>
            <ButtonLink to="/faq" variant="outline" className="mt-6">Visit help centre</ButtonLink>
          </div>
          {isLoading ? <Skeleton className="h-80 rounded-2xl" /> : <FaqList faqs={data?.faqs ?? []} />}
        </div>
      </section>
    </>
  );
}
