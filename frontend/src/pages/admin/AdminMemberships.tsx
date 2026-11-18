import { useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Scale } from 'lucide-react';
import { get, getPage, post } from '@/lib/api';
import { fmtDate, money } from '@/lib/format';
import { useAuth } from '@/providers/AuthProvider';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Field';
import { Badge, Modal, Spinner, StatusBadge } from '@/components/ui/misc';
import { AdminHeader, DataTable, Toolbar } from '@/components/admin/kit';
import { LedgerTable, MembershipCard } from '@/components/account';
import type { Membership, MembershipTransaction } from '@/types';

interface Detail { membership: Membership; ledger: MembershipTransaction[]; ledger_balance: number; bookings: { id: number; booking_number: string; check_in: string; check_out: string; status: string; membership_nights_used: number }[] }

export default function AdminMemberships() {
  usePageMeta('Members & wallets — Admin');
  const [q, setQ] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [open, setOpen] = useState<Membership | null>(null);
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['admin', 'memberships', q, status, page],
    queryFn: () => getPage<Membership>('/admin/memberships', { q: q || undefined, status: status || undefined, page }),
    placeholderData: keepPreviousData,
  });
  return (
    <div>
      <AdminHeader title="Members & wallets" description="Every membership with its night ledger. Adjustments are audited." />
      <Toolbar>
        <input className="input w-72" placeholder="Search name, email or membership no." value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} aria-label="Search memberships" />
        <select className="input w-auto" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} aria-label="Status"><option value="">All statuses</option><option value="active">Active</option><option value="expired">Expired</option><option value="pending">Pending payment</option><option value="cancelled">Cancelled</option></select>
      </Toolbar>
      <DataTable rows={data?.items} loading={isLoading} error={error} onRetry={refetch} pagination={data?.pagination} onPage={setPage} onRowClick={setOpen}
        columns={[
          { key: 'no', header: 'Membership', cell: (m) => <div><p className="font-mono text-xs font-semibold">{m.membership_number}</p><p className="text-xs text-ink-500">{m.plan_name}</p></div> },
          { key: 'cust', header: 'Customer', cell: (m) => <div><p className="font-semibold">{m.customer?.name}</p><p className="text-xs text-ink-500">{m.customer?.email}</p></div> },
          { key: 'period', header: 'Period', cell: (m) => `${fmtDate(m.starts_at)} – ${fmtDate(m.expires_at)}` },
          { key: 'nights', header: 'Nights (used / total)', cell: (m) => <span className="font-semibold">{m.used_nights} / {m.total_nights} <span className="text-ink-500">({m.remaining_nights} left)</span></span> },
          { key: 'paid', header: 'Paid', cell: (m) => money(m.price_paid) },
          { key: 'status', header: 'Status', cell: (m) => <StatusBadge status={m.status} label={m.status_label} /> },
        ]} />
      {open && <MembershipDrawer id={open.id} onClose={() => setOpen(null)} />}
    </div>
  );
}

function MembershipDrawer({ id, onClose }: { id: number; onClose: () => void }) {
  const { can } = useAuth();
  const qc = useQueryClient();
  const [nights, setNights] = useState('');
  const [reason, setReason] = useState('');
  const { data, isLoading } = useQuery({ queryKey: ['admin', 'membership', id], queryFn: () => get<Detail>(`/admin/memberships/${id}`) });
  const adjust = useMutation({
    mutationFn: () => post(`/admin/memberships/${id}/adjust`, { nights: Number(nights), reason }),
    onSuccess: (r) => { toast.success(r.message); setNights(''); setReason(''); qc.invalidateQueries({ queryKey: ['admin', 'membership'] }); qc.invalidateQueries({ queryKey: ['admin', 'memberships'] }); },
    onError: (e) => toast.error((e as Error).message),
  });
  return (
    <Modal open onClose={onClose} size="xl" title={data ? `${data.membership.customer?.name ?? ''} — ${data.membership.membership_number}` : 'Membership'}>
      {isLoading || !data ? <Spinner /> : (
        <div className="space-y-6">
          <MembershipCard m={data.membership} />
          <p className="flex items-center gap-2 text-sm">
            <Scale className="h-4 w-4 text-brand-600" /> Ledger sum: <strong>{data.ledger_balance}</strong> · Cached balance: <strong>{data.membership.remaining_nights}</strong>
            {data.ledger_balance === data.membership.remaining_nights ? <Badge tone="success">Consistent</Badge> : <Badge tone="danger">Mismatch</Badge>}
          </p>
          {can('memberships.adjust') && data.membership.status === 'active' && (
            <div className="rounded-2xl bg-ink-50 p-4">
              <p className="font-semibold">Adjust night balance</p>
              <p className="text-xs text-ink-500">Positive adds nights (goodwill), negative removes. A reason is required and the change is written to both the ledger and the audit log.</p>
              <div className="mt-3 grid gap-3 sm:grid-cols-[120px_1fr_auto]">
                <Input label="Nights (±)" type="number" value={nights} onChange={(e) => setNights(e.target.value)} />
                <Input label="Reason" value={reason} onChange={(e) => setReason(e.target.value)} placeholder="e.g. Service recovery for delayed check-in" />
                <div className="flex items-end"><Button onClick={() => adjust.mutate()} loading={adjust.isPending} disabled={!nights || Number(nights) === 0 || reason.trim().length < 5}>Apply</Button></div>
              </div>
            </div>
          )}
          <div><h3 className="mb-2 font-sans font-bold">Ledger</h3><LedgerTable rows={data.ledger} /></div>
          {data.bookings.length > 0 && (
            <div><h3 className="mb-2 font-sans font-bold">Bookings using this membership</h3>
              <ul className="divide-y divide-ink-100 text-sm">{data.bookings.map((b) => <li key={b.id} className="flex justify-between py-2"><span className="font-mono">{b.booking_number}</span><span>{fmtDate(b.check_in)} – {fmtDate(b.check_out)}</span><span>{b.membership_nights_used} nights</span><StatusBadge status={b.status} /></li>)}</ul></div>
          )}
        </div>
      )}
    </Modal>
  );
}
