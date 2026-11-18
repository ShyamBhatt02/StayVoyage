import { useState } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { http, type ApiEnvelope, type Pagination as P } from '@/lib/api';
import { fmtDateTime } from '@/lib/format';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { Badge } from '@/components/ui/misc';
import { AdminHeader, DataTable, Toolbar } from '@/components/admin/kit';

interface Log { id: number; action: string; entity_type: string | null; entity_id: number | null; old_values: Record<string, unknown> | null; new_values: Record<string, unknown> | null; description: string | null; ip_address: string | null; user: { id: number; name: string; email: string } | null; created_at: string }

export default function AdminAuditLogs() {
  usePageMeta('Audit logs — Admin');
  const [action, setAction] = useState('');
  const [entity, setEntity] = useState('');
  const [page, setPage] = useState(1);
  const [open, setOpen] = useState<number | null>(null);
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['admin', 'audit', action, entity, page],
    queryFn: async () => (await http.get<ApiEnvelope<Log[]>>('/admin/audit-logs', { params: { action: action || undefined, entity_type: entity || undefined, page } })).data,
    placeholderData: keepPreviousData,
  });
  return (
    <div>
      <AdminHeader title="Audit logs" description="Every administrative change: who, what, before/after, IP and time." />
      <Toolbar>
        <select className="input w-auto" value={action} onChange={(e) => { setAction(e.target.value); setPage(1); }} aria-label="Action">
          <option value="">All actions</option>{['membership', 'price', 'hotel', 'room', 'inventory', 'booking', 'refund', 'user', 'settings', 'coupon', 'review', 'report', 'security'].map((a) => <option key={a} value={a}>{a}.*</option>)}
        </select>
        <select className="input w-auto" value={entity} onChange={(e) => { setEntity(e.target.value); setPage(1); }} aria-label="Entity">
          <option value="">All entities</option>{['Hotel', 'HotelRoom', 'Membership', 'MembershipPlan', 'Booking', 'Payment', 'User', 'Coupon', 'Review', 'TourPackage'].map((x) => <option key={x}>{x}</option>)}
        </select>
      </Toolbar>
      <DataTable rows={data?.data} loading={isLoading} error={error} onRetry={refetch} pagination={data?.meta?.pagination as P | undefined} onPage={setPage} onRowClick={(l) => setOpen(open === l.id ? null : l.id)}
        columns={[
          { key: 'when', header: 'When', cell: (l) => <span className="whitespace-nowrap text-xs">{fmtDateTime(l.created_at)}</span> },
          { key: 'who', header: 'User', cell: (l) => l.user ? <div><p className="font-medium">{l.user.name}</p><p className="text-xs text-ink-500">{l.ip_address}</p></div> : <span className="text-ink-400">System</span> },
          { key: 'action', header: 'Action', cell: (l) => <Badge tone="brand">{l.action}</Badge> },
          { key: 'entity', header: 'Entity', cell: (l) => (l.entity_type ? `${l.entity_type} #${l.entity_id}` : '—') },
          { key: 'change', header: 'Change', cell: (l) => (
            <div className="max-w-md text-xs">
              {l.description && <p className="mb-1 text-ink-600">{l.description}</p>}
              {open === l.id ? (
                <div className="grid gap-2 sm:grid-cols-2">
                  <pre className="overflow-x-auto rounded-lg bg-danger-50 p-2 text-[11px]">{JSON.stringify(l.old_values, null, 2) ?? '—'}</pre>
                  <pre className="overflow-x-auto rounded-lg bg-success-50 p-2 text-[11px]">{JSON.stringify(l.new_values, null, 2) ?? '—'}</pre>
                </div>
              ) : <p className="truncate text-ink-500">{l.new_values ? Object.keys(l.new_values).join(', ') : 'Click to view'}</p>}
            </div>
          ) },
        ]} />
    </div>
  );
}
