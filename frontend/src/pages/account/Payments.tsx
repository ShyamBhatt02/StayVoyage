import { useState } from 'react';
import { Link } from 'react-router-dom';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Receipt } from 'lucide-react';
import { getPage } from '@/lib/api';
import { fmtDateTime, money, titleCase } from '@/lib/format';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { EmptyState, ErrorState, Pagination, Skeleton, StatusBadge } from '@/components/ui/misc';
import { PageHeader } from '@/components/account';
import type { Payment } from '@/types';

export default function Payments() {
  usePageMeta('Payments');
  const [page, setPage] = useState(1);
  const { data, isLoading, isError, refetch } = useQuery({ queryKey: ['payments', page], queryFn: () => getPage<Payment>('/payments', { page }), placeholderData: keepPreviousData });
  return (
    <div>
      <PageHeader title="Payments" description="All payments and refunds on your account." />
      {isError ? <ErrorState onRetry={() => refetch()} /> : isLoading ? <Skeleton className="h-72 rounded-2xl" /> : !data?.items.length ? (
        <EmptyState icon={<Receipt className="h-7 w-7" />} title="No payments yet" />
      ) : (
        <div className="card overflow-x-auto">
          <table className="w-full min-w-[680px] text-sm">
            <thead><tr className="border-b border-ink-200 bg-ink-50 text-left text-xs uppercase tracking-wider text-ink-500"><th className="px-4 py-3 font-semibold">Date</th><th className="font-semibold">For</th><th className="font-semibold">Method</th><th className="font-semibold">Reference</th><th className="text-right font-semibold">Amount</th><th className="px-4 text-right font-semibold">Status</th></tr></thead>
            <tbody>
              {data.items.map((p) => (
                <tr key={p.id} className="border-b border-ink-100 last:border-0">
                  <td className="whitespace-nowrap px-4 py-3 text-ink-600">{fmtDateTime(p.paid_at ?? p.created_at)}</td>
                  <td>{p.payable_type === 'booking' && p.payable_reference ? <Link className="link" to={`/account/bookings/${p.payable_reference}`}>{p.payable_reference}</Link> : <span className="font-medium">Membership {p.payable_reference}</span>}</td>
                  <td className="text-ink-600">{titleCase(p.gateway)}{p.method ? ` · ${p.method.toUpperCase()}` : ''}</td>
                  <td className="font-mono text-xs text-ink-500">{p.reference}</td>
                  <td className="text-right font-semibold">{money(p.amount, { exact: true })}{p.amount_refunded > 0 && <p className="text-xs font-normal text-success-700">−{money(p.amount_refunded)} refunded</p>}</td>
                  <td className="px-4 text-right"><StatusBadge status={p.status} label={p.status_label} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <Pagination className="mt-6" meta={data?.pagination} onPage={setPage} />
    </div>
  );
}
