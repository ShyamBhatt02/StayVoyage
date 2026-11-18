import { Link } from 'react-router-dom';
import { cn } from '@/lib/format';

/**
 * StayVoyage logo: a location pin holding a compass needle (stay + voyage).
 * Pure SVG + CSS animation (see .sv-logo in index.css): the pin outline draws in on load,
 * the needle settles to north, and swings playfully on hover/focus.
 */
export function LogoMark({ className, light }: { className?: string; light?: boolean }) {
  return (
    <svg viewBox="0 0 48 48" className={cn('h-9 w-9 shrink-0', className)} aria-hidden>
      <defs>
        <linearGradient id="sv-grad" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stopColor={light ? '#74C4CE' : '#1E8494'} />
          <stop offset="1" stopColor={light ? '#1E8494' : '#0E5563'} />
        </linearGradient>
      </defs>
      <path className="sv-pin-fill" d="M24 3C14.6 3 7 10.4 7 19.6 7 31.8 24 45 24 45s17-13.2 17-25.4C41 10.4 33.4 3 24 3z" fill="url(#sv-grad)" />
      <path className="sv-pin" d="M24 3C14.6 3 7 10.4 7 19.6 7 31.8 24 45 24 45s17-13.2 17-25.4C41 10.4 33.4 3 24 3z" fill="none" stroke={light ? '#A9DDE3' : '#0E6B7B'} strokeWidth="2" strokeLinejoin="round" />
      <circle cx="24" cy="19.5" r="9.5" fill="#fff" opacity=".96" />
      <g className="sv-needle">
        <path d="M24 11l3.2 8.5H20.8z" fill="#F2603F" />
        <path d="M24 28l-3.2-8.5h6.4z" fill="#C2CAD3" />
      </g>
      <circle cx="24" cy="19.5" r="1.7" fill="#0E5563" />
    </svg>
  );
}

export function Logo({ className, light, to = '/' }: { className?: string; light?: boolean; to?: string }) {
  return (
    <Link to={to} className={cn('sv-logo inline-flex items-center gap-2 rounded-lg', className)} aria-label="StayVoyage home">
      <LogoMark light={light} />
      <span className="font-display text-[1.45rem] font-semibold leading-none tracking-tight">
        <span className={cn('sv-word-a', light ? 'text-white' : 'text-brand-800')}>Stay</span>
        <span className="sv-word-b text-coral-500">Voyage</span>
      </span>
    </Link>
  );
}
