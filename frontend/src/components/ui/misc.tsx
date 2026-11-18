import { useEffect, useRef, useState, type ReactNode } from 'react';
import { AlertTriangle, ChevronLeft, ChevronRight, ImageOff, Inbox, RefreshCw, Star, X } from 'lucide-react';
import { cn, initials } from '@/lib/format';
import { Button } from './Button';
import type { Pagination as PaginationT } from '@/lib/api';

// ------------------------------------------------------------------ Badge
const badgeTones = {
  brand: 'bg-brand-50 text-brand-700 ring-brand-600/15',
  coral: 'bg-coral-50 text-coral-700 ring-coral-600/15',
  sand: 'bg-sand-50 text-sand-800 ring-sand-600/20',
  neutral: 'bg-ink-100 text-ink-700 ring-ink-500/10',
  success: 'bg-success-50 text-success-700 ring-success-600/20',
  warning: 'bg-warning-50 text-warning-700 ring-warning-600/20',
  danger: 'bg-danger-50 text-danger-700 ring-danger-600/20',
  info: 'bg-info-50 text-info-700 ring-info-600/20',
};
export type Tone = keyof typeof badgeTones;

export function Badge({ tone = 'neutral', className, children, icon }: { tone?: Tone; className?: string; children: ReactNode; icon?: ReactNode }) {
  return (
    <span className={cn('inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset', badgeTones[tone], className)}>
      {icon}
      {children}
    </span>
  );
}

export function statusTone(status?: string): Tone {
  switch (status) {
    case 'confirmed': case 'paid': case 'active': case 'approved': case 'processed': case 'completed': return 'success';
    case 'payment_pending': case 'pending': case 'processing': case 'partially_refunded': return 'warning';
    case 'cancelled': case 'failed': case 'rejected': case 'suspended': return 'danger';
    case 'refunded': case 'expired': case 'hidden': case 'inactive': return 'neutral';
    default: return 'info';
  }
}

export function StatusBadge({ status, label }: { status: string; label?: string }) {
  return <Badge tone={statusTone(status)}>{label ?? status.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())}</Badge>;
}

// ------------------------------------------------------------------ Skeleton
export function Skeleton({ className }: { className?: string }) {
  return <div className={cn('skeleton', className)} aria-hidden />;
}

export function CardSkeleton({ count = 3, className }: { count?: number; className?: string }) {
  return (
    <>
      {Array.from({ length: count }).map((_, i) => (
        <div key={i} className={cn('card overflow-hidden', className)} aria-hidden>
          <Skeleton className="h-48 rounded-none" />
          <div className="space-y-3 p-5">
            <Skeleton className="h-5 w-3/4" />
            <Skeleton className="h-4 w-1/2" />
            <Skeleton className="h-4 w-2/3" />
          </div>
        </div>
      ))}
    </>
  );
}

// ------------------------------------------------------------------ Spinner
export function Spinner({ className, label = 'Loading' }: { className?: string; label?: string }) {
  return (
    <div role="status" className={cn('flex items-center justify-center p-8', className)}>
      <span className="h-8 w-8 animate-spin rounded-full border-[3px] border-brand-200 border-t-brand-600" />
      <span className="sr-only">{label}</span>
    </div>
  );
}

// ------------------------------------------------------------------ Empty / Error states
export function EmptyState({ icon, title, description, action, className }: { icon?: ReactNode; title: string; description?: ReactNode; action?: ReactNode; className?: string }) {
  return (
    <div className={cn('flex flex-col items-center justify-center rounded-2xl border border-dashed border-ink-200 bg-white px-6 py-14 text-center', className)}>
      <div className="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-600">{icon ?? <Inbox className="h-7 w-7" />}</div>
      <h3 className="font-display text-xl font-semibold text-ink-900">{title}</h3>
      {description && <p className="mt-2 max-w-md text-sm text-ink-500">{description}</p>}
      {action && <div className="mt-6">{action}</div>}
    </div>
  );
}

export function ErrorState({ message, onRetry, className }: { message?: string; onRetry?: () => void; className?: string }) {
  return (
    <div role="alert" className={cn('flex flex-col items-center justify-center rounded-2xl border border-danger-600/20 bg-danger-50/60 px-6 py-12 text-center', className)}>
      <AlertTriangle className="mb-3 h-8 w-8 text-danger-600" aria-hidden />
      <p className="font-semibold text-ink-900">We couldn't load this</p>
      <p className="mt-1 max-w-md text-sm text-ink-600">{message ?? 'Please check your connection and try again.'}</p>
      {onRetry && (
        <Button variant="outline" size="sm" className="mt-5" onClick={onRetry} icon={<RefreshCw className="h-4 w-4" />}>
          Try again
        </Button>
      )}
    </div>
  );
}

// ------------------------------------------------------------------ Stars
export function Stars({ value, size = 'h-4 w-4', className, label }: { value: number; size?: string; className?: string; label?: string }) {
  return (
    <span className={cn('inline-flex items-center gap-0.5', className)} aria-label={label ?? `${value} out of 5 stars`} role="img">
      {Array.from({ length: 5 }).map((_, i) => (
        <Star key={i} className={cn(size, i < Math.round(value) ? 'fill-sand-400 text-sand-400' : 'fill-ink-200 text-ink-200')} aria-hidden />
      ))}
    </span>
  );
}

export function RatingPill({ value, count, className }: { value: number; count?: number; className?: string }) {
  if (!value) return <span className={cn('text-xs font-medium text-ink-500', className)}>New</span>;
  const label = value >= 4.6 ? 'Exceptional' : value >= 4.2 ? 'Excellent' : value >= 3.8 ? 'Very good' : value >= 3 ? 'Good' : 'Fair';
  return (
    <span className={cn('inline-flex items-center gap-2', className)}>
      <span className="rounded-lg bg-brand-700 px-2 py-0.5 text-sm font-bold text-white">{value.toFixed(1)}</span>
      <span className="text-sm font-semibold text-ink-800">{label}</span>
      {count !== undefined && <span className="text-xs text-ink-500">({count} review{count === 1 ? '' : 's'})</span>}
    </span>
  );
}

// ------------------------------------------------------------------ Image with graceful fallback
export function Img({ src, alt, className, eager }: { src?: string | null; alt: string; className?: string; eager?: boolean }) {
  const [failed, setFailed] = useState(false);
  if (!src || failed) {
    return (
      <div className={cn('flex items-center justify-center bg-gradient-to-br from-brand-100 to-brand-200 text-brand-600', className)} role="img" aria-label={alt}>
        <ImageOff className="h-6 w-6 opacity-60" aria-hidden />
      </div>
    );
  }
  return <img src={src} alt={alt} loading={eager ? 'eager' : 'lazy'} decoding="async" onError={() => setFailed(true)} className={cn('object-cover', className)} />;
}

// ------------------------------------------------------------------ Avatar
export function Avatar({ name, src, size = 'h-10 w-10', className }: { name?: string | null; src?: string | null; size?: string; className?: string }) {
  const [failed, setFailed] = useState(false);
  if (src && !failed) return <img src={src} alt="" onError={() => setFailed(true)} className={cn('rounded-full object-cover ring-2 ring-white', size, className)} />;
  return (
    <span className={cn('inline-flex items-center justify-center rounded-full bg-gradient-to-br from-brand-500 to-brand-700 text-sm font-bold text-white ring-2 ring-white', size, className)} aria-hidden>
      {initials(name)}
    </span>
  );
}

// ------------------------------------------------------------------ Pagination
export function Pagination({ meta, onPage, className }: { meta?: PaginationT; onPage: (p: number) => void; className?: string }) {
  if (!meta || meta.last_page <= 1) return null;
  const { current_page: page, last_page: last } = meta;
  const pages = Array.from(new Set([1, page - 1, page, page + 1, last].filter((p) => p >= 1 && p <= last))).sort((a, b) => a - b);
  return (
    <nav aria-label="Pagination" className={cn('flex items-center justify-center gap-1.5', className)}>
      <Button variant="outline" size="icon" onClick={() => onPage(page - 1)} disabled={page <= 1} aria-label="Previous page"><ChevronLeft className="h-4 w-4" /></Button>
      {pages.map((p, i) => (
        <span key={p} className="flex items-center gap-1.5">
          {i > 0 && p - pages[i - 1]! > 1 && <span className="px-1 text-ink-400">…</span>}
          <button
            onClick={() => onPage(p)}
            aria-current={p === page ? 'page' : undefined}
            className={cn('h-10 min-w-10 rounded-xl px-3 text-sm font-semibold transition', p === page ? 'bg-brand-600 text-white' : 'bg-white text-ink-700 hover:bg-ink-100 border border-ink-200')}
          >
            {p}
          </button>
        </span>
      ))}
      <Button variant="outline" size="icon" onClick={() => onPage(page + 1)} disabled={page >= last} aria-label="Next page"><ChevronRight className="h-4 w-4" /></Button>
    </nav>
  );
}

// ------------------------------------------------------------------ Modal (accessible dialog)
export function Modal({ open, onClose, title, description, children, footer, size = 'md' }: {
  open: boolean; onClose: () => void; title: ReactNode; description?: ReactNode; children?: ReactNode; footer?: ReactNode; size?: 'sm' | 'md' | 'lg' | 'xl';
}) {
  const ref = useRef<HTMLDivElement>(null);
  useEffect(() => {
    if (!open) return;
    const prevFocus = document.activeElement as HTMLElement | null;
    const prevOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    const node = ref.current;
    const focusables = () => Array.from(node?.querySelectorAll<HTMLElement>('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])') ?? []).filter((el) => !el.hasAttribute('disabled'));
    setTimeout(() => (focusables()[1] ?? focusables()[0])?.focus(), 30);
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
      if (e.key === 'Tab') {
        const els = focusables();
        if (!els.length) return;
        const first = els[0]!;
        const last = els[els.length - 1]!;
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
    };
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = prevOverflow;
      prevFocus?.focus();
    };
  }, [open, onClose]);

  if (!open) return null;
  const widths = { sm: 'max-w-md', md: 'max-w-lg', lg: 'max-w-2xl', xl: 'max-w-4xl' };
  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4">
      <div className="absolute inset-0 bg-ink-950/50 backdrop-blur-[2px] animate-[fade-up_.15s_ease-out]" onClick={onClose} aria-hidden />
      <div ref={ref} role="dialog" aria-modal="true" aria-labelledby="modal-title"
        className={cn('relative flex max-h-[92vh] w-full flex-col overflow-hidden rounded-t-3xl bg-white shadow-lift animate-scale-in sm:rounded-3xl', widths[size])}>
        <div className="flex items-start justify-between gap-4 border-b border-ink-100 px-6 py-5">
          <div>
            <h2 id="modal-title" className="font-display text-xl font-semibold text-ink-900">{title}</h2>
            {description && <p className="mt-1 text-sm text-ink-500">{description}</p>}
          </div>
          <button onClick={onClose} className="rounded-lg p-1.5 text-ink-500 hover:bg-ink-100 hover:text-ink-800" aria-label="Close dialog"><X className="h-5 w-5" /></button>
        </div>
        <div className="flex-1 overflow-y-auto px-6 py-5">{children}</div>
        {footer && <div className="flex flex-wrap justify-end gap-3 border-t border-ink-100 bg-ink-50/60 px-6 py-4">{footer}</div>}
      </div>
    </div>
  );
}

export function ConfirmDialog({ open, onClose, onConfirm, title, description, confirmLabel = 'Confirm', tone = 'primary', loading, children }: {
  open: boolean; onClose: () => void; onConfirm: () => void; title: string; description?: ReactNode; confirmLabel?: string; tone?: 'primary' | 'danger'; loading?: boolean; children?: ReactNode;
}) {
  return (
    <Modal open={open} onClose={onClose} title={title} description={description} size="sm"
      footer={<>
        <Button variant="outline" onClick={onClose} disabled={loading}>Keep it</Button>
        <Button variant={tone === 'danger' ? 'danger' : 'primary'} onClick={onConfirm} loading={loading}>{confirmLabel}</Button>
      </>}>
      {children}
    </Modal>
  );
}

// ------------------------------------------------------------------ Tabs
export function Tabs<T extends string>({ tabs, value, onChange, className }: { tabs: { value: T; label: ReactNode }[]; value: T; onChange: (v: T) => void; className?: string }) {
  return (
    <div role="tablist" className={cn('flex gap-1 overflow-x-auto rounded-xl bg-ink-100 p-1 scrollbar-none', className)}>
      {tabs.map((t) => (
        <button key={t.value} role="tab" aria-selected={value === t.value} onClick={() => onChange(t.value)}
          className={cn('whitespace-nowrap rounded-lg px-4 py-2 text-sm font-semibold transition', value === t.value ? 'bg-white text-brand-700 shadow-sm' : 'text-ink-600 hover:text-ink-900')}>
          {t.label}
        </button>
      ))}
    </div>
  );
}

// ------------------------------------------------------------------ Switch
export function Switch({ checked, onChange, label, disabled }: { checked: boolean; onChange: (v: boolean) => void; label: ReactNode; disabled?: boolean }) {
  return (
    <label className={cn('inline-flex cursor-pointer items-center gap-3', disabled && 'cursor-not-allowed opacity-60')}>
      <button type="button" role="switch" aria-checked={checked} disabled={disabled} onClick={() => onChange(!checked)}
        className={cn('relative h-6 w-11 shrink-0 rounded-full transition', checked ? 'bg-brand-600' : 'bg-ink-300')}>
        <span className={cn('absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition-all', checked ? 'left-[22px]' : 'left-0.5')} />
      </button>
      <span className="text-sm font-medium text-ink-700">{label}</span>
    </label>
  );
}

// ------------------------------------------------------------------ Stat card
export function Stat({ label, value, icon, hint, tone = 'brand' }: { label: string; value: ReactNode; icon?: ReactNode; hint?: ReactNode; tone?: 'brand' | 'coral' | 'sand' | 'ink' }) {
  const tones = { brand: 'bg-brand-50 text-brand-700', coral: 'bg-coral-50 text-coral-600', sand: 'bg-sand-50 text-sand-700', ink: 'bg-ink-100 text-ink-700' };
  return (
    <div className="card p-5">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-sm font-medium text-ink-500">{label}</p>
          <p className="mt-1.5 truncate text-2xl font-bold text-ink-900">{value}</p>
          {hint && <p className="mt-1 text-xs text-ink-500">{hint}</p>}
        </div>
        {icon && <span className={cn('flex h-11 w-11 shrink-0 items-center justify-center rounded-xl', tones[tone])}>{icon}</span>}
      </div>
    </div>
  );
}

export function SectionHeader({ eyebrow, title, description, action, className }: { eyebrow?: string; title: ReactNode; description?: ReactNode; action?: ReactNode; className?: string }) {
  return (
    <div className={cn('mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between', className)}>
      <div className="max-w-2xl">
        {eyebrow && <p className="eyebrow mb-2">{eyebrow}</p>}
        <h2 className="section-title">{title}</h2>
        {description && <p className="mt-3 text-ink-600">{description}</p>}
      </div>
      {action}
    </div>
  );
}
