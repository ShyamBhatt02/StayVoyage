import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Crown, Plus, Search } from 'lucide-react';
import { getPage } from '@/lib/api';
import { money } from '@/lib/format';
import { useAuth } from '@/providers/AuthProvider';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { ButtonLink } from '@/components/ui/Button';
import { Img, Stars, StatusBadge } from '@/components/ui/misc';
import { AdminHeader, DataTable, Toolbar } from '@/components/admin/kit';
import type { Hotel } from '@/types';

export default function AdminHotels() {
  usePageMeta('Hotels — Admin');
  const navigate = useNavigate();
  const { can } = useAuth();
  const [q, setQ] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['admin', 'hotels', q, status, page],
    queryFn: () => getPage<Hotel>('/admin/hotels', { q: q || undefined, status: status || undefined, page }),
    placeholderData: keepPreviousData,
  });
  return (
    <div>
      <AdminHeader title="Hotels & rooms" description="Properties, photos, rooms, inventory, pricing and membership eligibility."
        actions={can('hotels.manage') && <ButtonLink to="/admin/hotels/new" icon={<Plus className="h-4 w-4" />}>Add hotel</ButtonLink>} />
      <Toolbar>
        <div className="relative"><Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-400" /><input className="input w-64 pl-9" placeholder="Search name or city" value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} aria-label="Search hotels" /></div>
        <select className="input w-auto" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} aria-label="Status"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option><option value="draft">Draft</option></select>
      </Toolbar>
      <DataTable rows={data?.items} loading={isLoading} error={error} onRetry={refetch} pagination={data?.pagination} onPage={setPage} onRowClick={(h) => navigate(`/admin/hotels/${h.id}`)}
        columns={[
          { key: 'name', header: 'Hotel', cell: (h) => <div className="flex items-center gap-3"><Img src={h.cover_url} alt="" className="h-11 w-14 rounded-lg" /><div><p className="font-semibold text-ink-900">{h.name}</p><p className="text-xs text-ink-500">{h.city}, {h.country}</p></div></div> },
          { key: 'stars', header: 'Category', cell: (h) => <div><Stars value={h.star_rating} size="h-3 w-3" /><p className="text-xs capitalize text-ink-500">{h.property_type}</p></div> },
          { key: 'rooms', header: 'Room types', cell: (h) => h.rooms_count ?? '—' },
          { key: 'price', header: 'From', cell: (h) => money(h.from_price) },
          { key: 'rating', header: 'Rating', cell: (h) => `${h.rating_avg.toFixed(1)} (${h.review_count})` },
          { key: 'member', header: 'Membership', cell: (h) => h.is_membership_eligible ? <span className="inline-flex items-center gap-1 text-xs font-semibold text-sand-700"><Crown className="h-3.5 w-3.5" /> Eligible</span> : <span className="text-xs text-ink-400">Regular only</span> },
          { key: 'status', header: 'Status', cell: (h) => <StatusBadge status={h.status ?? 'active'} /> },
        ]} />
    </div>
  );
}
