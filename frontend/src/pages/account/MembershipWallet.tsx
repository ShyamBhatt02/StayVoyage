import { useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { CalendarClock, Crown, Download, Info } from 'lucide-react';
import { download, get, getPage, put } from '@/lib/api';
import { fmtDate, money } from '@/lib/format';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { Button, ButtonLink } from '@/components/ui/Button';
import { EmptyState, ErrorState, Pagination, Skeleton, StatusBadge, Switch } from '@/components/ui/misc';
import { LedgerTable, MembershipCard, PageHeader } from '@/components/account';
import type { Membership, MembershipTransaction } from '@/types';

export default function MembershipWallet() {
  usePageMeta('Membership wallet');
  const qc = useQueryClient();
  const [page, setPage] = useState(1);
  const current = useQuery({ queryKey: ['membership', 'current'], queryFn: () => get<{ membership: Membership | null; queued_renewal: Membership | null }>('/membership/current') });
  const history = useQuery({ queryKey: ['membership', 'history'], queryFn: () => get<Membership[]>('/membership/history') });
  const ledger = useQuery({
    queryKey: ['membership', 'ledger', page],
    queryFn: () => getPage<MembershipTransaction>('/membership/transactions', { page }),
    placeholderData: keepPreviousData,
  });
  const autoRenew = useMutation({
    mutationFn: (enabled: boolean) => put<Membership>('/membership/auto-renew', { enabled }),
    onSuccess: (r) => { toast.success(r.message); qc.invalidateQueries({ queryKey: ['membership'] }); },
    onError: (e) => toast.error((e as Error).message),
  });
  const invoice = useMutation({ mutationFn: (n: string) => download(`/membership/${n}/invoice`, `StayVoyage-${n}.pdf`), onError: (e) => toast.error((e as Error).message) });

  if (current.isError) return <ErrorState onRetry={() => current.refetch()} />;
  const m = current.data?.membership;

  return (
    <div className="space-y-8">
      <PageHeader title="Membership wallet" description="Every night credited, used, returned or expired — recorded in your ledger."
        action={<ButtonLink to="/membership" variant={m?.is_usable ? 'outline' : 'accent'} icon={<Crown className="h-4 w-4" />}>{m?.is_usable ? 'Renew / extend' : 'Get membership'}</ButtonLink>} />

      {current.isLoading ? <Skeleton className="h-56 rounded-3xl" /> : m ? (
        <>
          <MembershipCard m={m} />
          <div className="grid gap-4 md:grid-cols-2">
            <div className="card p-5">
              <h2 className="font-sans font-bold text-ink-900">Auto-renewal</h2>
              <p className="mt-1 text-sm text-ink-600">We'll remind you 3 days before expiry with a one-tap renewal so your nights continue without a gap.</p>
              <div className="mt-4"><Switch checked={m.auto_renew} disabled={!m.is_usable || autoRenew.isPending} onChange={(v) => autoRenew.mutate(v)} label={m.auto_renew ? 'On' : 'Off'} /></div>
            </div>
            <div className="card p-5">
              <h2 className="flex items-center gap-2 font-sans font-bold text-ink-900"><Info className="h-4 w-4 text-brand-600" /> Your plan rules</h2>
              <ul className="mt-2 space-y-1 text-sm text-ink-600">
                <li>Up to {String(m.rules.max_nights_per_booking)} nights, {String(m.rules.max_rooms_per_booking)} room(s), {String(m.rules.max_guests_per_booking)} guests per booking</li>
                <li>Free cancellation until {String(m.rules.cancellation_deadline_hours)}h before check-in</li>
                <li>{m.rules.unused_nights_expire ? 'Unused nights lapse at expiry' : 'Unused nights roll over'}</li>
              </ul>
            </div>
          </div>
          {current.data?.queued_renewal && (
            <div className="flex items-center gap-3 rounded-2xl bg-brand-50 p-4 text-sm text-brand-800 ring-1 ring-brand-200">
              <CalendarClock className="h-5 w-5" /> Renewal {current.data.queued_renewal.membership_number} ({current.data.queued_renewal.total_nights} nights) starts {fmtDate(current.data.queued_renewal.starts_at)}.
            </div>
          )}
        </>
      ) : (
        <EmptyState icon={<Crown className="h-7 w-7" />} title="No membership yet" description="Join StayVoyage Monthly for up to 30 hotel nights at participating hotels." action={<ButtonLink to="/membership">See plans</ButtonLink>} />
      )}

      <section className="card p-5">
        <h2 className="font-display text-xl font-semibold text-ink-900">Transaction ledger</h2>
        <p className="mt-1 text-sm text-ink-500">Your balance is always the sum of these entries.</p>
        <div className="mt-4">
          {ledger.isLoading ? <Skeleton className="h-40" /> : ledger.data?.items.length ? <LedgerTable rows={ledger.data.items} /> : <p className="text-sm text-ink-500">No transactions yet.</p>}
        </div>
        <Pagination className="mt-6" meta={ledger.data?.pagination} onPage={setPage} />
      </section>

      {!!history.data?.length && (
        <section className="card p-5">
          <h2 className="font-display text-xl font-semibold text-ink-900">Membership history</h2>
          <ul className="mt-4 divide-y divide-ink-100">
            {history.data.map((h) => (
              <li key={h.id} className="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
                <div><p className="font-semibold text-ink-900">{h.plan_name} <span className="font-mono text-xs font-normal text-ink-500">{h.membership_number}</span></p><p className="text-ink-500">{fmtDate(h.starts_at)} – {fmtDate(h.expires_at)} · {h.used_nights}/{h.total_nights} nights used · {money(h.price_paid)}</p></div>
                <div className="flex items-center gap-2">
                  <StatusBadge status={h.status} label={h.status_label} />
                  {h.status !== 'cancelled' && <Button size="sm" variant="ghost" onClick={() => invoice.mutate(h.membership_number)} icon={<Download className="h-4 w-4" />}>Invoice</Button>}
                </div>
              </li>
            ))}
          </ul>
        </section>
      )}
    </div>
  );
}
