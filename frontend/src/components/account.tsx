import { Link } from 'react-router-dom';
import { CalendarDays, Crown, MapPin, Moon, Route } from 'lucide-react';
import { Img, StatusBadge } from '@/components/ui/misc';
import { cn, fmtDate, money, plural } from '@/lib/format';
import type { Booking, Membership, MembershipTransaction } from '@/types';

export function PageHeader({ title, description, action }: { title: string; description?: string; action?: React.ReactNode }) {
  return (
    <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 className="font-display text-2xl font-semibold text-ink-900 sm:text-3xl">{title}</h1>
        {description && <p className="mt-1 text-ink-500">{description}</p>}
      </div>
      {action}
    </div>
  );
}

/** linkBase: '/admin/bookings' or '/partner/bookings' for staff views; customer links use the booking number. */
export function BookingRow({ b, linkBase }: { b: Booking; linkBase?: string }) {
  const admin = !!linkBase;
  const to = linkBase ? `${linkBase}/${b.id}` : `/account/bookings/${b.booking_number}`;
  const title = b.type === 'hotel' ? b.hotel?.name : b.trip?.title ?? 'Custom trip';
  const image = b.type === 'hotel' ? b.hotel?.cover_url : b.trip?.cover_url;
  return (
    <Link to={to} className="card flex gap-4 p-3 transition hover:shadow-lift sm:p-4">
      <Img src={image} alt="" className="h-20 w-24 shrink-0 rounded-xl sm:h-24 sm:w-32" />
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-2">
          <StatusBadge status={b.status} label={b.status_label} />
          {b.booking_type === 'membership' && <span className="inline-flex items-center gap-1 text-xs font-semibold text-sand-700"><Crown className="h-3.5 w-3.5" /> Membership</span>}
          {b.type === 'tour' && <span className="inline-flex items-center gap-1 text-xs font-semibold text-brand-700"><Route className="h-3.5 w-3.5" /> Trip</span>}
        </div>
        <p className="mt-1 truncate font-semibold text-ink-900">{title}</p>
        <p className="flex flex-wrap items-center gap-x-3 text-sm text-ink-500">
          <span className="flex items-center gap-1"><CalendarDays className="h-3.5 w-3.5" />{fmtDate(b.check_in, 'd MMM')} – {fmtDate(b.check_out, 'd MMM yyyy')}</span>
          {b.type === 'hotel' && b.hotel?.city && <span className="flex items-center gap-1"><MapPin className="h-3.5 w-3.5" />{b.hotel.city}</span>}
          {b.membership_nights_used > 0 && <span className="flex items-center gap-1"><Moon className="h-3.5 w-3.5" />{plural(b.membership_nights_used, 'night')} used</span>}
        </p>
        <p className="mt-1 text-xs text-ink-400">{b.booking_number}{admin && b.customer ? ` · ${b.customer.name}` : ''}</p>
      </div>
      <div className="hidden text-right sm:block">
        <p className="font-bold text-ink-900">{money(b.total_amount)}</p>
        <p className="text-xs text-ink-500">{b.payment_status_label}</p>
      </div>
    </Link>
  );
}

export function MembershipCard({ m, compact }: { m: Membership; compact?: boolean }) {
  const pct = m.total_nights ? Math.round((m.used_nights / m.total_nights) * 100) : 0;
  return (
    <div className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-brand-700 via-brand-800 to-brand-950 p-6 text-white shadow-lift">
      <div className="absolute -right-10 -top-10 h-44 w-44 rounded-full bg-sand-400/20 blur-2xl" aria-hidden />
      <div className="relative">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <p className="flex items-center gap-1.5 text-sm font-semibold text-sand-200"><Crown className="h-4 w-4" /> {m.plan_name}</p>
            <p className="mt-1 text-xs text-brand-200">{m.membership_number}</p>
          </div>
          <span className={cn('rounded-full px-3 py-1 text-xs font-bold', m.is_usable ? 'bg-success-600 text-white' : 'bg-white/20')}>{m.is_usable ? 'Active' : m.status_label}</span>
        </div>
        <div className={cn('mt-6 grid gap-4', compact ? 'grid-cols-3' : 'grid-cols-3 sm:grid-cols-5')}>
          <div><p className="text-xs text-brand-200">Total nights</p><p className="text-3xl font-bold">{m.total_nights}</p></div>
          <div><p className="text-xs text-brand-200">Used</p><p className="text-3xl font-bold">{m.used_nights}</p></div>
          <div><p className="text-xs text-brand-200">Remaining</p><p className="text-3xl font-bold text-sand-300">{m.remaining_nights}</p></div>
          {!compact && <div><p className="text-xs text-brand-200">Started</p><p className="font-semibold">{fmtDate(m.starts_at)}</p></div>}
          {!compact && <div><p className="text-xs text-brand-200">Expires</p><p className="font-semibold">{fmtDate(m.expires_at)}</p></div>}
        </div>
        <div className="mt-5">
          <div className="h-2.5 rounded-full bg-white/15" role="progressbar" aria-valuenow={pct} aria-valuemin={0} aria-valuemax={100} aria-label="Nights used">
            <div className="h-2.5 rounded-full bg-gradient-to-r from-sand-300 to-coral-400" style={{ width: `${pct}%` }} />
          </div>
          <p className="mt-2 text-xs text-brand-200">{m.is_usable ? `${m.days_remaining} day${m.days_remaining === 1 ? '' : 's'} left · expires ${fmtDate(m.expires_at)}` : `Ended ${fmtDate(m.expires_at)}`}</p>
        </div>
      </div>
    </div>
  );
}

export function LedgerTable({ rows }: { rows: MembershipTransaction[] }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[560px] text-sm">
        <thead><tr className="border-b border-ink-200 text-left text-xs uppercase tracking-wider text-ink-500"><th className="py-2.5 pr-3 font-semibold">Date</th><th className="pr-3 font-semibold">Transaction</th><th className="pr-3 text-right font-semibold">Nights</th><th className="pr-3 text-right font-semibold">Before</th><th className="text-right font-semibold">After</th></tr></thead>
        <tbody>
          {rows.map((t) => (
            <tr key={t.id} className="border-b border-ink-100 last:border-0">
              <td className="whitespace-nowrap py-3 pr-3 text-ink-500">{fmtDate(t.created_at)}</td>
              <td className="pr-3"><p className="font-semibold text-ink-800">{t.type_label}</p><p className="text-xs text-ink-500">{t.description}</p></td>
              <td className={cn('pr-3 text-right font-bold tabular-nums', t.nights > 0 ? 'text-success-700' : 'text-coral-600')}>{t.nights > 0 ? `+${t.nights}` : t.nights}</td>
              <td className="pr-3 text-right tabular-nums text-ink-500">{t.balance_before}</td>
              <td className="text-right font-semibold tabular-nums text-ink-900">{t.balance_after}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
