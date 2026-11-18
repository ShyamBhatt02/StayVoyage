import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Download } from 'lucide-react';
import { download, get } from '@/lib/api';
import { cn, money, titleCase } from '@/lib/format';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { Button } from '@/components/ui/Button';
import { ErrorState, Skeleton, Tabs } from '@/components/ui/misc';
import { AdminHeader } from '@/components/admin/kit';

type Type = 'revenue' | 'bookings' | 'memberships' | 'hotels' | 'tours' | 'customers' | 'refunds';
interface Report { columns: string[]; rows: (string | number)[][]; summary: Record<string, unknown>; range: { from: string; to: string; label: string }; truncated: boolean }
const RANGES = [['today', 'Today'], ['yesterday', 'Yesterday'], ['last_7_days', 'Last 7 days'], ['last_30_days', 'Last 30 days'], ['this_month', 'This month'], ['last_month', 'Last month'], ['custom', 'Custom']] as const;
const MONEY_COLS = /revenue|gross|net|refund|total|paid|spend|value|amount/i;

export default function AdminReports() {
  usePageMeta('Reports — Admin');
  const [type, setType] = useState<Type>('revenue');
  const [range, setRange] = useState<string>('last_30_days');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const params = { range, ...(range === 'custom' ? { from, to } : {}) };
  const ready = range !== 'custom' || (from && to);
  const { data, isLoading, isError, error, refetch } = useQuery({ queryKey: ['admin', 'report', type, params], queryFn: () => get<Report>(`/admin/reports/${type}`, params), enabled: !!ready });
  const exp = useMutation({
    mutationFn: (format: 'csv' | 'xlsx' | 'pdf') => download(`/admin/reports/${type}/export?${new URLSearchParams({ ...params, format } as Record<string, string>).toString()}`, `stayvoyage-${type}-report.${format}`),
    onError: (e) => toast.error((e as Error).message),
  });
  return (
    <div className="space-y-6">
      <AdminHeader title="Reports" description="Filter by date and export to CSV, Excel or PDF."
        actions={<>{(['csv', 'xlsx', 'pdf'] as const).map((f) => <Button key={f} variant="outline" size="sm" disabled={!ready} loading={exp.isPending && exp.variables === f} onClick={() => exp.mutate(f)} icon={<Download className="h-4 w-4" />}>{f.toUpperCase()}</Button>)}</>} />
      <Tabs className="w-fit" value={type} onChange={setType} tabs={(['revenue', 'bookings', 'memberships', 'hotels', 'tours', 'customers', 'refunds'] as Type[]).map((t) => ({ value: t, label: titleCase(t) }))} />
      <div className="flex flex-wrap items-center gap-2">
        {RANGES.map(([v, l]) => <button key={v} className={cn('chip', range === v && 'chip-active')} onClick={() => setRange(v)}>{l}</button>)}
        {range === 'custom' && <><input type="date" className="input h-9 w-auto py-1" value={from} onChange={(e) => setFrom(e.target.value)} aria-label="From" /><input type="date" className="input h-9 w-auto py-1" value={to} min={from} onChange={(e) => setTo(e.target.value)} aria-label="To" /></>}
      </div>
      {isError ? <ErrorState message={(error as Error).message} onRetry={() => refetch()} /> : isLoading || !data ? <Skeleton className="h-80 rounded-2xl" /> : (
        <>
          <p className="text-sm text-ink-500">{data.range.label}: {data.range.from} → {data.range.to}</p>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {Object.entries(data.summary).filter(([, v]) => typeof v !== 'object').map(([k, v]) => (
              <div key={k} className="card p-4"><p className="text-xs text-ink-500">{titleCase(k)}</p><p className="mt-1 text-2xl font-bold">{typeof v === 'number' && MONEY_COLS.test(k) ? money(v) : String(v)}{k.includes('percent') ? '%' : ''}</p></div>
            ))}
          </div>
          <div className="card overflow-x-auto">
            <table className="w-full min-w-[800px] text-sm">
              <thead><tr className="border-b border-ink-200 bg-ink-50 text-left text-xs uppercase tracking-wider text-ink-500">{data.columns.map((c) => <th key={c} className="px-3 py-2.5 font-semibold">{c}</th>)}</tr></thead>
              <tbody>
                {data.rows.map((r, i) => <tr key={i} className="border-b border-ink-100">{r.map((c, j) => <td key={j} className="px-3 py-2">{typeof c === 'number' && MONEY_COLS.test(data.columns[j] ?? '') ? money(c, { exact: true }) : String(c ?? '—')}</td>)}</tr>)}
                {!data.rows.length && <tr><td colSpan={data.columns.length} className="px-3 py-8 text-center text-ink-500">No data for this period.</td></tr>}
              </tbody>
            </table>
          </div>
          {data.truncated && <p className="text-xs text-ink-500">Showing the first 500 rows — export for the full report.</p>}
        </>
      )}
    </div>
  );
}
