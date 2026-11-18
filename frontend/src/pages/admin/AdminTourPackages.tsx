import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Plus } from 'lucide-react';
import { getPage } from '@/lib/api';
import { money } from '@/lib/format';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { ButtonLink } from '@/components/ui/Button';
import { Img, StatusBadge } from '@/components/ui/misc';
import { AdminHeader, DataTable, Toolbar } from '@/components/admin/kit';
import type { TourPackage } from '@/types';

export default function AdminTourPackages() {
  usePageMeta('Tour packages — Admin');
  const navigate = useNavigate();
  const [q, setQ] = useState('');
  const [page, setPage] = useState(1);
  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['admin', 'packages', q, page], queryFn: () => getPage<TourPackage>('/admin/tour-packages', { q: q || undefined, page }) });
  return (
    <div>
      <AdminHeader title="Tour packages" description="Templates customers can book or customise in the Tour Builder." actions={<ButtonLink to="/admin/tour-packages/new" icon={<Plus className="h-4 w-4" />}>New package</ButtonLink>} />
      <Toolbar><input className="input w-64" placeholder="Search packages" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Search packages" /></Toolbar>
      <DataTable rows={data?.items} loading={isLoading} error={error} onRetry={refetch} pagination={data?.pagination} onPage={setPage} onRowClick={(p) => navigate(`/admin/tour-packages/${p.id}`)}
        columns={[
          { key: 'name', header: 'Package', cell: (p) => <div className="flex items-center gap-3"><Img src={p.image_url} alt="" className="h-11 w-16 rounded-lg" /><div><p className="font-semibold">{p.name}</p><p className="text-xs text-ink-500">{p.cities}</p></div></div> },
          { key: 'dest', header: 'Destination', cell: (p) => p.destination?.name },
          { key: 'dur', header: 'Duration', cell: (p) => `${p.duration_days}D / ${p.duration_nights}N` },
          { key: 'price', header: 'From (pp)', cell: (p) => money(p.price_from) },
          { key: 'featured', header: 'Featured', cell: (p) => (p.is_featured ? 'Yes' : '—') },
          { key: 'status', header: 'Status', cell: (p) => <StatusBadge status={p.status ?? 'active'} /> },
        ]} />
    </div>
  );
}
