import { useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { RotateCcw } from 'lucide-react';
import { getPage, post } from '@/lib/api';
import { fmtDateTime, money, titleCase } from '@/lib/format';
import { useAuth } from '@/providers/AuthProvider';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { Button } from '@/components/ui/Button';
import { Input, Textarea } from '@/components/ui/Field';
import { Modal, StatusBadge } from '@/components/ui/misc';
import { AdminHeader, DataTable, Toolbar } from '@/components/admin/kit';
import type { Payment } from '@/types';

export default function AdminPayments() {
  usePageMeta('Payments — Admin');
  const { can } = useAuth();
  const qc = useQueryClient();
  const [f, setF] = useState({ q: '', status: '', gateway: '' });
  const [page, setPage] = useState(1);
  const [refunding, setRefunding] = useState<Payment | null>(null);
  const [amount, setAmount] = useState('');
  const [reason, setReason] = useState('');
  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['admin', 'payments', f, page], queryFn: () => getPage<Payment>('/admin/payments', { ...Object.fromEntries(Object.entries(f).filter(([, v]) => v)), page }), placeholderData: keepPreviousData });
  const refund = useMutation({
    mutationFn: () => post(`/admin/payments/${refunding!.id}/refund`, { amount: Number(amount), reason }),
    onSuccess: (r) => { toast.success(r.message); setRefunding(null); qc.invalidateQueries({ queryKey: ['admin', 'payments'] }); },
    onError: (e) => toast.error((e as Error).message),
  });
  const max = refunding ? refunding.amount - refunding.amount_refunded : 0;
  return (
    <div>
      <AdminHeader title="Payments & refunds" description="Gateway transactions. Card details are never stored by StayVoyage." />
      <Toolbar>
        <input className="input w-60" placeholder="Reference or gateway ID" value={f.q} onChange={(e) => { setF({ ...f, q: e.target.value }); setPage(1); }} aria-label="Search payments" />
        <select className="input w-auto" value={f.status} onChange={(e) => { setF({ ...f, status: e.target.value }); setPage(1); }} aria-label="Status"><option value="">All statuses</option>{['pending', 'paid', 'failed', 'refunded', 'partially_refunded'].map((s) => <option key={s} value={s}>{titleCase(s)}</option>)}</select>
        <select className="input w-auto" value={f.gateway} onChange={(e) => { setF({ ...f, gateway: e.target.value }); setPage(1); }} aria-label="Gateway"><option value="">All gateways</option><option value="sandbox">Sandbox</option><option value="razorpay">Razorpay</option><option value="stripe">Stripe</option></select>
      </Toolbar>
      <DataTable rows={data?.items} loading={isLoading} error={error} onRetry={refetch} pagination={data?.pagination} onPage={setPage}
        columns={[
          { key: 'ref', header: 'Reference', cell: (p) => <div><p className="font-mono text-xs font-semibold">{p.reference}</p><p className="text-xs text-ink-500">{p.gateway_payment_id ?? '—'}</p></div> },
          { key: 'for', header: 'For', cell: (p) => <div><p className="font-medium">{p.payable_reference}</p><p className="text-xs capitalize text-ink-500">{p.purpose}</p></div> },
          { key: 'cust', header: 'Customer', cell: (p) => p.customer?.name },
          { key: 'gw', header: 'Gateway', cell: (p) => `${titleCase(p.gateway)}${p.method ? ` · ${p.method.toUpperCase()}` : ''}` },
          { key: 'when', header: 'Date', cell: (p) => fmtDateTime(p.paid_at ?? p.created_at) },
          { key: 'amt', header: 'Amount', cell: (p) => <div><p className="font-semibold">{money(p.amount, { exact: true })}</p>{p.amount_refunded > 0 && <p className="text-xs text-success-700">−{money(p.amount_refunded)}</p>}</div> },
          { key: 'status', header: 'Status', cell: (p) => <div><StatusBadge status={p.status} label={p.status_label} />{p.failure_reason && <p className="mt-1 max-w-[180px] text-xs text-danger-600">{p.failure_reason}</p>}</div> },
          { key: 'x', header: '', cell: (p) => can('refunds.manage') && ['paid', 'partially_refunded'].includes(p.status) && <Button size="sm" variant="outline" onClick={() => { setRefunding(p); setAmount(String(p.amount - p.amount_refunded)); setReason(''); }} icon={<RotateCcw className="h-3.5 w-3.5" />}>Refund</Button> },
        ]} />
      <Modal open={!!refunding} onClose={() => setRefunding(null)} title={`Refund ${refunding?.reference}`} description={`Refundable: ${money(max, { exact: true })}. The refund goes back to the original payment method through ${titleCase(refunding?.gateway)}.`}
        footer={<><Button variant="outline" onClick={() => setRefunding(null)}>Cancel</Button><Button variant="danger" loading={refund.isPending} disabled={!amount || Number(amount) <= 0 || Number(amount) > max || reason.trim().length < 5} onClick={() => refund.mutate()}>Refund {amount ? money(Number(amount)) : ''}</Button></>}>
        <div className="space-y-4">
          <Input label="Amount (₹)" type="number" min={1} max={max} step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} />
          <Textarea label="Reason (audited)" rows={2} value={reason} onChange={(e) => setReason(e.target.value)} />
          <p className="text-xs text-ink-500">Refunding money does not cancel the booking. To cancel and refund per policy, use the booking's Cancel action.</p>
        </div>
      </Modal>
    </div>
  );
}
