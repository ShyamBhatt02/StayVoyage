import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Bell, CalendarCheck, CheckCheck, Crown, CreditCard, Map, User } from 'lucide-react';
import { http, post } from '@/lib/api';
import { cn, fromNow } from '@/lib/format';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { Button } from '@/components/ui/Button';
import { EmptyState, ErrorState, Pagination, Skeleton, Tabs } from '@/components/ui/misc';
import { PageHeader } from '@/components/account';
import type { AppNotification } from '@/types';
import type { ApiEnvelope, Pagination as P } from '@/lib/api';

const ICONS: Record<string, typeof Bell> = { booking: CalendarCheck, membership: Crown, payment: CreditCard, trip: Map, account: User };

export default function Notifications() {
  usePageMeta('Notifications');
  const qc = useQueryClient();
  const [tab, setTab] = useState<'all' | 'unread'>('all');
  const [page, setPage] = useState(1);
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['notifications', tab, page],
    queryFn: async () => (await http.get<ApiEnvelope<AppNotification[]>>('/notifications', { params: { unread: tab === 'unread' ? 1 : undefined, page } })).data,
  });
  const invalidate = () => { qc.invalidateQueries({ queryKey: ['notifications'] }); qc.invalidateQueries({ queryKey: ['dashboard'] }); };
  const readAll = useMutation({ mutationFn: () => post('/notifications/read-all'), onSuccess: invalidate });
  const readOne = useMutation({ mutationFn: (id: string) => post(`/notifications/${id}/read`), onSuccess: invalidate });
  const unread = (data?.meta?.unread_count as number | undefined) ?? 0;

  return (
    <div>
      <PageHeader title="Notifications" description="Booking updates, payments, membership reminders and more."
        action={unread > 0 && <Button variant="outline" size="sm" onClick={() => readAll.mutate()} loading={readAll.isPending} icon={<CheckCheck className="h-4 w-4" />}>Mark all as read</Button>} />
      <Tabs className="mb-5 w-fit" value={tab} onChange={(v) => { setTab(v); setPage(1); }} tabs={[{ value: 'all', label: 'All' }, { value: 'unread', label: `Unread${unread ? ` (${unread})` : ''}` }]} />
      {isError ? <ErrorState onRetry={() => refetch()} /> : isLoading ? <Skeleton className="h-72 rounded-2xl" /> : !data?.data.length ? (
        <EmptyState icon={<Bell className="h-7 w-7" />} title={tab === 'unread' ? "You're all caught up" : 'No notifications yet'} />
      ) : (
        <ul className="card divide-y divide-ink-100">
          {data.data.map((n) => {
            const Icon = ICONS[n.category] ?? Bell;
            return (
              <li key={n.id} className={cn('flex gap-4 p-4', !n.read_at && 'bg-brand-50/40')}>
                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600"><Icon className="h-5 w-5" /></span>
                <div className="min-w-0 flex-1">
                  <p className={cn('text-sm', n.read_at ? 'text-ink-800' : 'font-semibold text-ink-900')}>{n.title}</p>
                  <p className="mt-0.5 text-sm text-ink-600">{n.message}</p>
                  <div className="mt-2 flex flex-wrap items-center gap-3 text-xs">
                    <span className="text-ink-400">{fromNow(n.created_at)}</span>
                    {n.action_path && <Link to={n.action_path} onClick={() => !n.read_at && readOne.mutate(n.id)} className="font-semibold text-brand-700 hover:underline">{n.action_text ?? 'Open'}</Link>}
                    {!n.read_at && <button onClick={() => readOne.mutate(n.id)} className="font-semibold text-ink-500 hover:text-ink-800">Mark as read</button>}
                  </div>
                </div>
              </li>
            );
          })}
        </ul>
      )}
      <Pagination className="mt-6" meta={data?.meta?.pagination as P | undefined} onPage={setPage} />
    </div>
  );
}
