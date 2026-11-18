import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { ApiError, get, put } from '@/lib/api';
import { titleCase } from '@/lib/format';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { Button } from '@/components/ui/Button';
import { Badge, ErrorState, Skeleton, Switch } from '@/components/ui/misc';
import { AdminHeader } from '@/components/admin/kit';

interface SettingsData { values: Record<string, unknown>; groups: Record<string, string[]>; gateway_status: Record<string, boolean>; mail_driver: string }

const LABELS: Record<string, string> = {
  site: 'Website', pricing: 'Taxes & fees', booking: 'Booking rules', tours: 'Tour rules', payments: 'Payments', security: 'Security', mail: 'Email',
};

export default function AdminSettings() {
  usePageMeta('Settings — Admin');
  const qc = useQueryClient();
  const { data, isLoading, isError, refetch } = useQuery({ queryKey: ['admin', 'settings'], queryFn: () => get<SettingsData>('/admin/settings') });
  const [v, setV] = useState<Record<string, unknown>>({});
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  useEffect(() => { if (data) setV(data.values); }, [data]);
  const save = useMutation({
    mutationFn: () => put('/admin/settings', { values: v }),
    onSuccess: (r) => { toast.success(r.message); setErrors({}); qc.invalidateQueries({ queryKey: ['admin', 'settings'] }); qc.invalidateQueries({ queryKey: ['meta'] }); },
    onError: (e) => { if (e instanceof ApiError) setErrors(e.errors); toast.error((e as Error).message); },
  });
  if (isError) return <ErrorState onRetry={() => refetch()} />;
  if (isLoading || !data) return <Skeleton className="h-96 rounded-2xl" />;

  const field = (key: string) => {
    const val = v[key];
    const label = titleCase(key.split('.')[1]!);
    const err = errors[`values.${key}`]?.[0];
    if (typeof data.values[key] === 'boolean') return <Switch key={key} checked={!!val} onChange={(x) => setV((s) => ({ ...s, [key]: x }))} label={label} />;
    if (key === 'payments.enabled_gateways') {
      const list = (val as string[]) ?? [];
      return (
        <fieldset key={key}><legend className="label">{label}</legend>
          <div className="flex flex-wrap gap-3">{['sandbox', 'razorpay', 'stripe'].map((g) => (
            <label key={g} className="flex items-center gap-2 text-sm"><input type="checkbox" className="h-4 w-4 accent-brand-600" checked={list.includes(g)} onChange={(e) => setV((s) => ({ ...s, [key]: e.target.checked ? [...list, g] : list.filter((x) => x !== g) }))} />
              {titleCase(g)} {data.gateway_status[g] ? <Badge tone="success">keys set</Badge> : <Badge tone="neutral">no keys</Badge>}</label>))}</div>
          {err && <p className="mt-1 text-xs text-danger-600">{err}</p>}
        </fieldset>
      );
    }
    if (key === 'payments.default_gateway') return (
      <label key={key} className="block"><span className="label">{label}</span><select className="input" value={String(val)} onChange={(e) => setV((s) => ({ ...s, [key]: e.target.value }))}>{['sandbox', 'razorpay', 'stripe'].map((g) => <option key={g} value={g}>{titleCase(g)}</option>)}</select></label>
    );
    const numeric = typeof data.values[key] === 'number';
    return (
      <label key={key} className="block"><span className="label">{label}</span>
        <input className={`input ${err ? 'input-error' : ''}`} type={numeric ? 'number' : 'text'} step="any" value={String(val ?? '')} onChange={(e) => setV((s) => ({ ...s, [key]: numeric ? Number(e.target.value) : e.target.value }))} />
        {err && <span className="mt-1 block text-xs text-danger-600">{err}</span>}
      </label>
    );
  };

  return (
    <div className="space-y-6">
      <AdminHeader title="Platform settings" description="Business rules used by the pricing engine, booking flow and payments. Every change is audited." actions={<Button onClick={() => save.mutate()} loading={save.isPending}>Save settings</Button>} />
      {Object.entries(data.groups).map(([group, keys]) => (
        <section key={group} className="card p-6">
          <h2 className="font-sans text-lg font-bold">{LABELS[group] ?? titleCase(group)}</h2>
          {group === 'mail' && <p className="mt-1 text-sm text-ink-500">Mail driver: <strong>{data.mail_driver}</strong> (configure SMTP such as Mailtrap free tier in .env)</p>}
          <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">{keys.map(field)}</div>
        </section>
      ))}
      <div className="flex justify-end"><Button size="lg" onClick={() => save.mutate()} loading={save.isPending}>Save settings</Button></div>
    </div>
  );
}
