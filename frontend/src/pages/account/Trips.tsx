import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Copy, Map, MoreHorizontal, Plus, Share2, Trash2 } from 'lucide-react';
import { del, getPage, post } from '@/lib/api';
import { fmtDate, money } from '@/lib/format';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { Button, ButtonLink } from '@/components/ui/Button';
import { ConfirmDialog, EmptyState, ErrorState, Img, Pagination, Skeleton, StatusBadge } from '@/components/ui/misc';
import { PageHeader } from '@/components/account';
import { NewTripModal } from '@/pages/builder/TourBuilderLanding';
import type { Trip } from '@/types';

export default function Trips() {
  usePageMeta('Saved trips');
  const qc = useQueryClient();
  const navigate = useNavigate();
  const [page, setPage] = useState(1);
  const [creating, setCreating] = useState(false);
  const [deleting, setDeleting] = useState<Trip | null>(null);
  const [menu, setMenu] = useState<number | null>(null);
  const { data, isLoading, isError, refetch } = useQuery({ queryKey: ['trips', page], queryFn: () => getPage<Trip>('/trips', { page }) });

  const duplicate = useMutation({ mutationFn: (id: number) => post<Trip>(`/trips/${id}/duplicate`), onSuccess: (r) => { toast.success(r.message); qc.invalidateQueries({ queryKey: ['trips'] }); }, onError: (e) => toast.error((e as Error).message) });
  const remove = useMutation({ mutationFn: (id: number) => del(`/trips/${id}`), onSuccess: (r) => { toast.success(r.message); setDeleting(null); qc.invalidateQueries({ queryKey: ['trips'] }); }, onError: (e) => toast.error((e as Error).message) });

  return (
    <div>
      <PageHeader title="Saved trips" description="Edit, duplicate, share or book your custom itineraries." action={<Button onClick={() => setCreating(true)} icon={<Plus className="h-4 w-4" />}>New trip</Button>} />
      {isError ? <ErrorState onRetry={() => refetch()} /> : isLoading ? <div className="grid gap-4 sm:grid-cols-2">{[1, 2].map((i) => <Skeleton key={i} className="h-60 rounded-2xl" />)}</div>
        : !data?.items.length ? <EmptyState icon={<Map className="h-7 w-7" />} title="No trips yet" description="Start from scratch or customise one of our tours." action={<div className="flex gap-2"><Button onClick={() => setCreating(true)}>Create a trip</Button><ButtonLink to="/tours" variant="outline">Browse tours</ButtonLink></div>} />
          : (
            <div className="grid gap-5 sm:grid-cols-2">
              {data.items.map((t) => (
                <article key={t.id} className="card relative overflow-hidden">
                  <Link to={`/tour-builder/${t.id}`}><Img src={t.cover_url} alt="" className="h-36 w-full" /></Link>
                  <div className="p-5">
                    <div className="flex items-start justify-between gap-2">
                      <div>
                        <StatusBadge status={t.status === 'booked' ? 'confirmed' : 'pending'} label={t.status === 'booked' ? 'Booked' : 'Draft'} />
                        <h3 className="mt-2 font-display text-lg font-semibold"><Link to={`/tour-builder/${t.id}`} className="hover:text-brand-700">{t.title}</Link></h3>
                        <p className="text-sm text-ink-500">{t.destination_summary ?? 'Custom trip'} · {t.duration_days} days · {t.travelers} travellers</p>
                        <p className="text-xs text-ink-400">{t.start_date ? `Starts ${fmtDate(t.start_date)}` : 'No date set'} · {t.items_count} items</p>
                      </div>
                      <div className="relative">
                        <button onClick={() => setMenu(menu === t.id ? null : t.id)} className="rounded-lg p-1.5 hover:bg-ink-100" aria-label="Trip actions" aria-expanded={menu === t.id}><MoreHorizontal className="h-5 w-5" /></button>
                        {menu === t.id && (
                          <div className="absolute right-0 z-10 mt-1 w-44 rounded-xl border border-ink-200 bg-white p-1 shadow-lift" role="menu">
                            <button role="menuitem" className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm hover:bg-ink-50" onClick={() => { setMenu(null); duplicate.mutate(t.id!); }}><Copy className="h-4 w-4" /> Duplicate</button>
                            <button role="menuitem" className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm hover:bg-ink-50" onClick={() => navigate(`/tour-builder/${t.id}?share=1`)}><Share2 className="h-4 w-4" /> Share</button>
                            {t.status !== 'booked' && <button role="menuitem" className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm text-danger-600 hover:bg-danger-50" onClick={() => { setMenu(null); setDeleting(t); }}><Trash2 className="h-4 w-4" /> Delete</button>}
                          </div>
                        )}
                      </div>
                    </div>
                    <div className="mt-4 flex items-center justify-between border-t border-ink-100 pt-4">
                      <p className="text-xl font-bold text-ink-900">{money(t.total_amount)}</p>
                      <div className="flex gap-2">
                        <ButtonLink to={`/tour-builder/${t.id}`} variant="outline" size="sm">{t.status === 'booked' ? 'View' : 'Edit'}</ButtonLink>
                        {t.status === 'draft' && <ButtonLink to={`/checkout/trip/${t.id}`} size="sm">Book</ButtonLink>}
                      </div>
                    </div>
                  </div>
                </article>
              ))}
            </div>
          )}
      <Pagination className="mt-8" meta={data?.pagination} onPage={setPage} />
      <NewTripModal open={creating} onClose={() => setCreating(false)} />
      <ConfirmDialog open={!!deleting} onClose={() => setDeleting(null)} onConfirm={() => deleting && remove.mutate(deleting.id!)} loading={remove.isPending} tone="danger" confirmLabel="Delete trip" title={`Delete “${deleting?.title}”?`} description="This can't be undone." />
    </div>
  );
}
