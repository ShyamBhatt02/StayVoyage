import { useState } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { CalendarX } from 'lucide-react';
import { getPage } from '@/lib/api';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { ButtonLink } from '@/components/ui/Button';
import { EmptyState, ErrorState, Pagination, Skeleton, Tabs } from '@/components/ui/misc';
import { BookingRow, PageHeader } from '@/components/account';
import type { Booking } from '@/types';

type Scope = 'upcoming' | 'past' | 'cancelled' | 'all';

export default function Bookings() {
  usePageMeta('My bookings');
  const [scope, setScope] = useState<Scope>('upcoming');
  const [type, setType] = useState<'' | 'hotel' | 'tour'>('');
  const [page, setPage] = useState(1);
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['bookings', scope, type, page],
    queryFn: () => getPage<Booking>('/bookings', { scope, type: type || undefined, page }),
    placeholderData: keepPreviousData,
  });

  return (
    <div>
      <PageHeader title="My bookings" description="Hotel stays and trips — manage, cancel or download invoices." />
      <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
        <Tabs value={scope} onChange={(v) => { setScope(v); setPage(1); }} tabs={[{ value: 'upcoming', label: 'Upcoming' }, { value: 'past', label: 'Past' }, { value: 'cancelled', label: 'Cancelled' }, { value: 'all', label: 'All' }]} />
        <select className="input w-auto" value={type} onChange={(e) => { setType(e.target.value as typeof type); setPage(1); }} aria-label="Booking type">
          <option value="">Hotels & trips</option><option value="hotel">Hotels only</option><option value="tour">Trips only</option>
        </select>
      </div>
      {isError ? <ErrorState onRetry={() => refetch()} /> : isLoading ? <div className="space-y-3">{[1, 2, 3].map((i) => <Skeleton key={i} className="h-28 rounded-2xl" />)}</div>
        : !data?.items.length ? <EmptyState icon={<CalendarX className="h-7 w-7" />} title="No bookings here" description={scope === 'upcoming' ? 'You have no upcoming stays or trips.' : 'Nothing to show for this filter.'} action={<ButtonLink to="/hotels">Find a stay</ButtonLink>} />
          : <div className="space-y-3">{data.items.map((b) => <BookingRow key={b.id} b={b} />)}</div>}
      <Pagination className="mt-8" meta={data?.pagination} onPage={setPage} />
    </div>
  );
}
