import { Compass, HeartHandshake, Leaf, ShieldCheck } from 'lucide-react';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { ButtonLink } from '@/components/ui/Button';

export default function About() {
  usePageMeta('About us', 'Meet StayVoyage — a membership-first travel platform built in Ahmedabad.');
  return (
    <>
      <section className="relative isolate overflow-hidden">
        <img src="/storage/seed/misc/hero-lake.jpg" alt="" className="absolute inset-0 -z-10 h-full w-full object-cover" />
        <div className="absolute inset-0 -z-10 bg-brand-950/70" />
        <div className="container-page py-24 text-white">
          <p className="eyebrow text-sand-300">About StayVoyage</p>
          <h1 className="mt-3 max-w-3xl font-display text-4xl font-semibold sm:text-5xl">We believe great trips should be easy to plan and fair to pay for.</h1>
        </div>
      </section>
      <section className="container-page grid gap-12 py-20 lg:grid-cols-2">
        <div>
          <h2 className="section-title">Our story</h2>
          <div className="mt-5 space-y-4 leading-relaxed text-ink-700">
            <p>StayVoyage started in Ahmedabad in 2024 with a simple frustration: frequent travellers were paying full price for every single night, and planning a multi-city trip meant juggling a dozen tabs and bookings.</p>
            <p>So we built two things. A monthly membership that turns hotel stays into night credits — so the people who travel most get the most value. And a Tour Builder that lets anyone design a trip day by day, with live prices and one checkout for everything.</p>
            <p>Today our team of 40 works with hand-picked hotels across India, the UAE, Southeast Asia, the Maldives and Europe, personally visiting properties before they join the platform.</p>
          </div>
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          {[
            [Compass, 'Curated, not crowded', 'Every hotel is visited and reviewed by our team before it goes live.'],
            [ShieldCheck, 'Honest pricing', 'Taxes and fees are shown upfront and verified by our system at payment.'],
            [HeartHandshake, 'Real support', 'Travel experts available 9 am – 9 pm IST, every day of the year.'],
            [Leaf, 'Travel lighter', 'We favour locally owned stays, guides and restaurants wherever we can.'],
          ].map(([Icon, t, b]) => {
            const I = Icon as typeof Compass;
            return <div key={t as string} className="card p-6"><I className="h-7 w-7 text-brand-600" /><h3 className="mt-3 font-sans font-semibold">{t as string}</h3><p className="mt-1 text-sm text-ink-600">{b as string}</p></div>;
          })}
        </div>
      </section>
      <section className="bg-white py-16">
        <div className="container-page grid gap-6 text-center sm:grid-cols-4">
          {[['24', 'hand-picked hotels'], ['13', 'destinations'], ['4.6★', 'average guest rating'], ['24×7', 'booking support']].map(([n, l]) => (
            <div key={l}><p className="font-display text-4xl font-semibold text-brand-700">{n}</p><p className="mt-1 text-sm text-ink-600">{l}</p></div>
          ))}
        </div>
      </section>
      <section className="container-page py-16 text-center">
        <h2 className="section-title">Ready to travel your way?</h2>
        <div className="mt-6 flex flex-wrap justify-center gap-3"><ButtonLink to="/membership" variant="accent">Explore membership</ButtonLink><ButtonLink to="/contact" variant="outline">Talk to us</ButtonLink></div>
      </section>
    </>
  );
}
