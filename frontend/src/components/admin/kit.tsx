import { useEffect, useState, type ReactNode } from 'react';
import { cn } from '@/lib/format';
import { Button } from '@/components/ui/Button';
import { Checkbox, Input, Select, Textarea } from '@/components/ui/Field';
import { EmptyState, ErrorState, Modal, Pagination, Skeleton } from '@/components/ui/misc';
import type { Pagination as P } from '@/lib/api';

export interface Column<T> { key: string; header: ReactNode; cell: (row: T) => ReactNode; className?: string }

export function DataTable<T extends { id: number | string }>({ columns, rows, loading, error, onRetry, empty, pagination, onPage, onRowClick }: {
  columns: Column<T>[]; rows?: T[]; loading?: boolean; error?: unknown; onRetry?: () => void; empty?: ReactNode; pagination?: P; onPage?: (p: number) => void; onRowClick?: (row: T) => void;
}) {
  if (error) return <ErrorState message={(error as Error).message} onRetry={onRetry} />;
  return (
    <div>
      <div className="card overflow-x-auto">
        <table className="w-full min-w-[720px] text-sm">
          <thead>
            <tr className="border-b border-ink-200 bg-ink-50 text-left text-xs uppercase tracking-wider text-ink-500">
              {columns.map((c) => <th key={c.key} scope="col" className={cn('px-4 py-3 font-semibold', c.className)}>{c.header}</th>)}
            </tr>
          </thead>
          <tbody>
            {loading ? Array.from({ length: 6 }).map((_, i) => (
              <tr key={i} className="border-b border-ink-100">{columns.map((c) => <td key={c.key} className="px-4 py-3"><Skeleton className="h-4" /></td>)}</tr>
            )) : rows?.map((r) => (
              <tr key={r.id} onClick={onRowClick ? () => onRowClick(r) : undefined} className={cn('border-b border-ink-100 last:border-0', onRowClick && 'cursor-pointer hover:bg-brand-50/40')}>
                {columns.map((c) => <td key={c.key} className={cn('px-4 py-3 align-middle', c.className)}>{c.cell(r)}</td>)}
              </tr>
            ))}
          </tbody>
        </table>
        {!loading && !rows?.length && <div className="p-6">{empty ?? <EmptyState title="Nothing here yet" className="border-0" />}</div>}
      </div>
      {onPage && <Pagination className="mt-6" meta={pagination} onPage={onPage} />}
    </div>
  );
}

export function AdminHeader({ title, description, actions }: { title: string; description?: string; actions?: ReactNode }) {
  return (
    <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
      <div><h1 className="font-display text-2xl font-semibold text-ink-900 sm:text-3xl">{title}</h1>{description && <p className="mt-1 text-sm text-ink-500">{description}</p>}</div>
      {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
    </div>
  );
}

export function Toolbar({ children }: { children: ReactNode }) {
  return <div className="mb-4 flex flex-wrap items-center gap-2">{children}</div>;
}

// ------------------------------------------------------------------ schema-driven form modal
export type FieldDef =
  | { name: string; label: string; type: 'text' | 'number' | 'email' | 'date' | 'time' | 'datetime-local' | 'password'; required?: boolean; hint?: string; span?: 2; step?: string; min?: number; max?: number }
  | { name: string; label: string; type: 'textarea'; required?: boolean; hint?: string; span?: 2; rows?: number }
  | { name: string; label: string; type: 'select'; options: { value: string | number; label: string }[]; required?: boolean; hint?: string; span?: 2; placeholder?: string }
  | { name: string; label: string; type: 'checkbox'; hint?: string; span?: 2 }
  | { name: string; label: string; type: 'list'; hint?: string; span?: 2 } // newline-separated → string[]
  | { name: string; label: string; type: 'multiselect'; options: { value: number | string; label: string }[]; hint?: string; span?: 2 }
  | { name: string; label: string; type: 'file'; hint?: string; span?: 2; accept?: string };

export function FormModal({ open, onClose, title, fields, initial, onSubmit, submitting, errors = {}, size = 'lg' }: {
  open: boolean; onClose: () => void; title: string; fields: FieldDef[]; initial?: Record<string, unknown>; onSubmit: (values: Record<string, unknown>) => void;
  submitting?: boolean; errors?: Record<string, string[]>; size?: 'md' | 'lg' | 'xl';
}) {
  const [values, setValues] = useState<Record<string, unknown>>({});
  useEffect(() => {
    if (!open) return;
    const v: Record<string, unknown> = {};
    fields.forEach((f) => {
      const raw = initial?.[f.name];
      if (f.type === 'list') v[f.name] = Array.isArray(raw) ? (raw as string[]).join('\n') : '';
      else if (f.type === 'checkbox') v[f.name] = !!raw;
      else if (f.type === 'multiselect') v[f.name] = Array.isArray(raw) ? raw : [];
      else if (f.type === 'file') v[f.name] = null;
      else v[f.name] = raw ?? '';
    });
    setValues(v);
  }, [open, initial, fields]);

  const set = (k: string, val: unknown) => setValues((s) => ({ ...s, [k]: val }));
  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    const out: Record<string, unknown> = {};
    fields.forEach((f) => {
      const v = values[f.name];
      if (f.type === 'list') out[f.name] = String(v ?? '').split('\n').map((s) => s.trim()).filter(Boolean);
      else if (f.type === 'number') out[f.name] = v === '' || v === null ? null : Number(v);
      else if (f.type === 'file') { if (v) out[f.name] = v; }
      else out[f.name] = v === '' ? null : v;
    });
    onSubmit(out);
  };
  const err = (n: string) => errors[n]?.[0];

  return (
    <Modal open={open} onClose={onClose} title={title} size={size}
      footer={<><Button variant="outline" onClick={onClose}>Cancel</Button><Button type="submit" form="admin-form" loading={submitting}>Save</Button></>}>
      <form id="admin-form" onSubmit={submit} className="grid gap-4 sm:grid-cols-2" noValidate>
        {fields.map((f) => {
          const wrap = f.span === 2 || f.type === 'textarea' || f.type === 'list' || f.type === 'multiselect' ? 'sm:col-span-2' : '';
          if (f.type === 'textarea' || f.type === 'list') return <Textarea key={f.name} wrapClassName={wrap} label={f.label} hint={f.type === 'list' ? (f.hint ?? 'One per line') : f.hint} rows={f.type === 'textarea' ? f.rows ?? 4 : 4} value={String(values[f.name] ?? '')} onChange={(e) => set(f.name, e.target.value)} error={err(f.name)} />;
          if (f.type === 'select') return (
            <Select key={f.name} wrapClassName={wrap} label={f.label} hint={f.hint} required={f.required} value={String(values[f.name] ?? '')} onChange={(e) => set(f.name, e.target.value)} error={err(f.name)}>
              <option value="">{f.placeholder ?? 'Select…'}</option>
              {f.options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
            </Select>
          );
          if (f.type === 'checkbox') return <div key={f.name} className={cn('flex items-end', wrap)}><Checkbox label={f.label} checked={!!values[f.name]} onChange={(e) => set(f.name, e.target.checked)} /></div>;
          if (f.type === 'multiselect') {
            const sel = (values[f.name] as (number | string)[]) ?? [];
            return (
              <fieldset key={f.name} className={wrap}>
                <legend className="label">{f.label}</legend>
                <div className="flex max-h-44 flex-wrap gap-1.5 overflow-y-auto rounded-xl border border-ink-200 p-2">
                  {f.options.map((o) => {
                    const on = sel.map(String).includes(String(o.value));
                    return <button type="button" key={o.value} onClick={() => set(f.name, on ? sel.filter((x) => String(x) !== String(o.value)) : [...sel, o.value])} className={cn('chip text-xs', on && 'chip-active')}>{o.label}</button>;
                  })}
                </div>
                {f.hint && <p className="mt-1 text-xs text-ink-500">{f.hint}</p>}
                {err(f.name) && <p className="mt-1 text-xs text-danger-600">{err(f.name)}</p>}
              </fieldset>
            );
          }
          if (f.type === 'file') return (
            <label key={f.name} className={cn('block', wrap)}><span className="label">{f.label}</span>
              <input type="file" accept={f.accept ?? 'image/jpeg,image/png,image/webp'} onChange={(e) => set(f.name, e.target.files?.[0] ?? null)} className="block w-full text-sm" />
              {f.hint && <span className="mt-1 block text-xs text-ink-500">{f.hint}</span>}
              {err(f.name) && <span className="mt-1 block text-xs text-danger-600">{err(f.name)}</span>}
            </label>
          );
          return <Input key={f.name} wrapClassName={wrap} label={f.label} type={f.type} hint={f.hint} required={f.required} step={'step' in f ? f.step : undefined} min={'min' in f ? f.min : undefined} max={'max' in f ? f.max : undefined}
            value={String(values[f.name] ?? '')} onChange={(e) => set(f.name, e.target.value)} error={err(f.name)} />;
        })}
      </form>
    </Modal>
  );
}
