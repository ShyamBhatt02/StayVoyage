import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Check, EyeOff, Trash2, X } from 'lucide-react';
import { del, getPage, post } from '@/lib/api';
import { fmtDate } from '@/lib/format';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { Button } from '@/components/ui/Button';
import { Avatar, ConfirmDialog, EmptyState, ErrorState, Img, Pagination, Skeleton, Stars, StatusBadge, Tabs } from '@/components/ui/misc';
import { AdminHeader } from '@/components/admin/kit';
import type { Review } from '@/types';

type S = 'pending' | 'approved' | 'rejected' | 'hidden' | '';

export default function AdminReviews() {
  usePageMeta('Reviews — Admin');
  const [params] = useSearchParams();
  const qc = useQueryClient();
  const [status, setStatus] = useState<S>((params.get('status') as S) ?? 'pending');
  const [page, setPage] = useState(1);
  const [deleting, setDeleting] = useState<Review | null>(null);
  const { data, isLoading, isError, refetch } = useQuery({ queryKey: ['admin', 'reviews', status, page], queryFn: () => getPage<Review>('/admin/reviews', { status: status || undefined, page }), placeholderData: keepPreviousData });
  const moderate = useMutation({
    mutationFn: ({ r, s }: { r: Review; s: string }) => post(`/admin/reviews/${r.id}/moderate`, { status: s }),
    onSuccess: (r) => { toast.success(r.message); qc.invalidateQueries({ queryKey: ['admin', 'reviews'] }); },
    onError: (e) => toast.error((e as Error).message),
  });
  const remove = useMutation({ mutationFn: (r: Review) => del(`/admin/reviews/${r.id}`), onSuccess: (r) => { toast.success(r.message); setDeleting(null); refetch(); } });
  return (
    <div>
      <AdminHeader title="Reviews" description="Only guests with completed stays can review. Approved reviews update hotel ratings automatically." />
      <Tabs className="mb-5 w-fit" value={status} onChange={(v) => { setStatus(v); setPage(1); }} tabs={[{ value: 'pending', label: 'Pending' }, { value: 'approved', label: 'Approved' }, { value: 'hidden', label: 'Hidden' }, { value: 'rejected', label: 'Rejected' }, { value: '', label: 'All' }]} />
      {isError ? <ErrorState onRetry={() => refetch()} /> : isLoading ? <Skeleton className="h-64 rounded-2xl" /> : !data?.items.length ? <EmptyState title="No reviews here" /> : (
        <div className="space-y-4">
          {data.items.map((r) => (
            <article key={r.id} className="card p-5">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex items-center gap-3"><Avatar name={r.author?.name} src={r.author?.avatar_url} /><div><p className="font-semibold">{r.author?.name} <span className="text-xs font-normal text-ink-500">{r.author?.email}</span></p><p className="text-xs text-ink-500">{r.hotel?.name} · stayed {r.stay_month} · {fmtDate(r.created_at)}</p></div></div>
                <div className="flex items-center gap-2"><Stars value={r.overall} /><StatusBadge status={r.status ?? 'pending'} /></div>
              </div>
              <h3 className="mt-3 font-sans font-semibold">{r.title}</h3>
              <p className="mt-1 text-sm text-ink-600">{r.comment}</p>
              <p className="mt-2 text-xs text-ink-500">Cleanliness {r.cleanliness} · Location {r.location} · Service {r.service} · Value {r.value}</p>
              {r.images.length > 0 && <div className="mt-3 flex gap-2">{r.images.map((src, i) => <Img key={i} src={src} alt="" className="h-16 w-20 rounded-lg" />)}</div>}
              <div className="mt-4 flex flex-wrap gap-2">
                {r.status !== 'approved' && <Button size="sm" onClick={() => moderate.mutate({ r, s: 'approved' })} icon={<Check className="h-4 w-4" />}>Approve</Button>}
                {r.status !== 'rejected' && <Button size="sm" variant="outline" onClick={() => moderate.mutate({ r, s: 'rejected' })} icon={<X className="h-4 w-4" />}>Reject</Button>}
                {r.status === 'approved' && <Button size="sm" variant="outline" onClick={() => moderate.mutate({ r, s: 'hidden' })} icon={<EyeOff className="h-4 w-4" />}>Hide</Button>}
                <Button size="sm" variant="ghost" className="text-danger-600" onClick={() => setDeleting(r)} icon={<Trash2 className="h-4 w-4" />}>Delete</Button>
              </div>
            </article>
          ))}
        </div>
      )}
      <Pagination className="mt-6" meta={data?.pagination} onPage={setPage} />
      <ConfirmDialog open={!!deleting} onClose={() => setDeleting(null)} onConfirm={() => deleting && remove.mutate(deleting)} loading={remove.isPending} tone="danger" confirmLabel="Delete" title="Delete this review?" />
    </div>
  );
}
