import { Check, CreditCard, Lock, ShieldCheck } from 'lucide-react';
import { cn, money } from '@/lib/format';
import { useConfig } from '@/hooks/useConfig';

export function Steps({ steps, current }: { steps: string[]; current: number }) {
  return (
    <ol className="flex items-center gap-2 overflow-x-auto pb-1 text-sm scrollbar-none" aria-label="Checkout progress">
      {steps.map((s, i) => (
        <li key={s} className="flex shrink-0 items-center gap-2">
          <span className={cn('flex h-7 w-7 items-center justify-center rounded-full text-xs font-bold', i < current ? 'bg-success-600 text-white' : i === current ? 'bg-brand-600 text-white' : 'bg-ink-200 text-ink-600')}
            aria-current={i === current ? 'step' : undefined}>
            {i < current ? <Check className="h-4 w-4" /> : i + 1}
          </span>
          <span className={cn('font-semibold', i === current ? 'text-ink-900' : 'text-ink-500')}>{s}</span>
          {i < steps.length - 1 && <span className="mx-1 h-px w-6 bg-ink-300 sm:w-10" aria-hidden />}
        </li>
      ))}
    </ol>
  );
}

export function LineItems({ items, total, currency = 'INR', note }: { items: { label: string; amount: number; muted?: boolean }[]; total: number; currency?: string; note?: string }) {
  return (
    <div>
      <dl className="space-y-2.5 text-sm">
        {items.map((i, idx) => (
          <div key={idx} className="flex justify-between gap-4">
            <dt className={cn('text-ink-600', i.muted && 'text-ink-400')}>{i.label}</dt>
            <dd className={cn('font-semibold tabular-nums', i.amount < 0 ? 'text-success-700' : 'text-ink-900')}>{i.amount < 0 ? '−' : ''}{money(Math.abs(i.amount), { exact: true, currency })}</dd>
          </div>
        ))}
      </dl>
      <div className="mt-4 flex items-end justify-between border-t border-ink-200 pt-4">
        <span className="font-semibold text-ink-900">Total payable</span>
        <span className="text-2xl font-bold text-ink-900 tabular-nums">{money(total, { exact: true, currency })}</span>
      </div>
      {note && <p className="mt-2 text-xs text-ink-500">{note}</p>}
    </div>
  );
}

export function GatewayPicker({ value, onChange }: { value: string; onChange: (v: string) => void }) {
  const { data } = useConfig();
  const gateways = data?.payment_gateways ?? [];
  return (
    <fieldset>
      <legend className="label">Payment method</legend>
      <div className="grid gap-2">
        {gateways.map((g) => (
          <label key={g.name} className={cn('flex cursor-pointer items-center gap-3 rounded-xl border p-3.5 transition', value === g.name ? 'border-brand-500 bg-brand-50/60 ring-2 ring-brand-500/15' : 'border-ink-200 hover:border-ink-300')}>
            <input type="radio" name="gateway" value={g.name} checked={value === g.name} onChange={() => onChange(g.name)} className="accent-brand-600" />
            <CreditCard className="h-5 w-5 text-brand-600" />
            <span className="text-sm font-semibold text-ink-800">{g.label}</span>
            {g.name === 'sandbox' && <span className="ml-auto rounded-full bg-info-50 px-2 py-0.5 text-[11px] font-bold text-info-700">TEST MODE</span>}
          </label>
        ))}
      </div>
      <p className="mt-2 flex items-center gap-1.5 text-xs text-ink-500"><Lock className="h-3.5 w-3.5" /> Payments are processed by the provider. StayVoyage never stores card details.</p>
    </fieldset>
  );
}

export function TrustNote() {
  return (
    <div className="mt-4 flex items-start gap-2.5 rounded-xl bg-brand-50 p-3 text-xs text-brand-800">
      <ShieldCheck className="h-4 w-4 shrink-0" />
      <p>Your price is calculated and verified by StayVoyage at the moment you pay. Your booking is confirmed only after the payment provider confirms the payment.</p>
    </div>
  );
}
