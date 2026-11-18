import { Link } from 'react-router-dom';
import { Mail, MapPin, Phone, ShieldCheck } from 'lucide-react';
import { Logo } from '@/components/brand/Logo';
import { useSetting } from '@/hooks/useConfig';

const COLS = [
  { title: 'Explore', links: [['Hotels', '/hotels'], ['Tour packages', '/tours'], ['Tour Builder', '/tour-builder'], ['Destinations', '/destinations'], ['Offers', '/offers']] },
  { title: 'Membership', links: [['How it works', '/membership'], ['Membership hotels', '/hotels?membership=1'], ['My wallet', '/account/membership'], ['FAQ', '/faq']] },
  { title: 'Company', links: [['About us', '/about'], ['Contact us', '/contact'], ['Help centre', '/faq'], ['Partner with us', '/contact?topic=partner']] },
];

export function Footer() {
  const email = useSetting('site.support_email', 'support@stayvoyage.test');
  const phone = useSetting('site.support_phone', '+91 79 4000 1234');
  const address = useSetting('site.address', 'Ahmedabad, Gujarat');

  return (
    <footer className="mt-24 bg-brand-950 text-brand-100">
      <div className="container-page grid gap-12 py-16 lg:grid-cols-[1.4fr_repeat(3,1fr)]">
        <div>
          <Logo light />
          <p className="mt-4 max-w-sm text-sm leading-relaxed text-brand-200/80">
            Book hand-picked stays, unlock up to 30 hotel nights a month with membership, and build trips your way — day by day.
          </p>
          <ul className="mt-6 space-y-2.5 text-sm text-brand-200/90">
            <li className="flex items-start gap-2.5"><MapPin className="mt-0.5 h-4 w-4 shrink-0 text-coral-400" />{String(address)}</li>
            <li className="flex items-center gap-2.5"><Phone className="h-4 w-4 text-coral-400" /><a href={`tel:${String(phone).replace(/\s/g, '')}`} className="hover:text-white">{String(phone)}</a></li>
            <li className="flex items-center gap-2.5"><Mail className="h-4 w-4 text-coral-400" /><a href={`mailto:${email}`} className="hover:text-white">{String(email)}</a></li>
          </ul>
        </div>
        {COLS.map((c) => (
          <div key={c.title}>
            <h3 className="font-sans text-sm font-bold uppercase tracking-widest text-white">{c.title}</h3>
            <ul className="mt-4 space-y-2.5">
              {c.links.map(([label, to]) => (
                <li key={label}><Link to={to!} className="text-sm text-brand-200/85 transition hover:text-white">{label}</Link></li>
              ))}
            </ul>
          </div>
        ))}
      </div>
      <div className="border-t border-white/10">
        <div className="container-page flex flex-col gap-3 py-6 text-xs text-brand-200/70 sm:flex-row sm:items-center sm:justify-between">
          <p>© {new Date().getFullYear()} StayVoyage Travel Pvt. Ltd. All rights reserved.</p>
          <p className="flex items-center gap-1.5"><ShieldCheck className="h-4 w-4 text-brand-300" /> Secure payments · We never store your card details</p>
        </div>
      </div>
    </footer>
  );
}
