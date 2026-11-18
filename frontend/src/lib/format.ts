import { differenceInCalendarDays, format, formatDistanceToNowStrict, parseISO } from 'date-fns';
import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}

const inr = new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 });
const inrExact = new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', minimumFractionDigits: 2, maximumFractionDigits: 2 });

/** ₹6,500 (no decimals when whole) — Indian digit grouping. */
export function money(amount: number | null | undefined, opts: { exact?: boolean; currency?: string } = {}): string {
  const v = Number(amount ?? 0);
  if (opts.currency && opts.currency !== 'INR') {
    return new Intl.NumberFormat('en-IN', { style: 'currency', currency: opts.currency }).format(v);
  }
  if (opts.exact || !Number.isInteger(Math.round(v * 100) / 100)) return inrExact.format(v);
  return inr.format(v);
}

export function toDate(value?: string | Date | null): Date | null {
  if (!value) return null;
  if (value instanceof Date) return value;
  const d = value.length === 10 ? parseISO(value + 'T00:00:00') : parseISO(value);
  return isNaN(d.getTime()) ? null : d;
}

export function fmtDate(value?: string | Date | null, pattern = 'd MMM yyyy'): string {
  const d = toDate(value);
  return d ? format(d, pattern) : '—';
}

export function fmtDateTime(value?: string | Date | null): string {
  return fmtDate(value, 'd MMM yyyy, h:mm a');
}

export function fromNow(value?: string | null): string {
  const d = toDate(value);
  return d ? formatDistanceToNowStrict(d, { addSuffix: true }) : '';
}

export function nightsBetween(a?: string, b?: string): number {
  const da = toDate(a);
  const db = toDate(b);
  return da && db ? Math.max(0, differenceInCalendarDays(db, da)) : 0;
}

export function isoDate(d: Date): string {
  return format(d, 'yyyy-MM-dd');
}

export function addDaysIso(days: number, from: Date = new Date()): string {
  const d = new Date(from);
  d.setDate(d.getDate() + days);
  return isoDate(d);
}

export function plural(n: number, one: string, many = one + 's'): string {
  return `${n} ${n === 1 ? one : many}`;
}

export function initials(name?: string | null): string {
  return (name ?? '?')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0]!.toUpperCase())
    .join('');
}

export function titleCase(s?: string | null): string {
  return (s ?? '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

export function minutesLabel(min?: number | null): string {
  if (!min) return '';
  const h = Math.floor(min / 60);
  const m = min % 60;
  return h ? `${h}h${m ? ` ${m}m` : ''}` : `${m}m`;
}
