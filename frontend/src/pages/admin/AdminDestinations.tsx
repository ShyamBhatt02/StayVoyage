import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Plus, Trash2 } from 'lucide-react';
import { ApiError, del, get, upload } from '@/lib/api';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog, Img, StatusBadge } from '@/components/ui/misc';
import { AdminHeader, DataTable, FormModal, type FieldDef } from '@/components/admin/kit';
import type { Destination } from '@/types';

const FIELDS: FieldDef[] = [
  { name: 'name', label: 'Name', type: 'text', required: true },
  { name: 'country', label: 'Country', type: 'text', required: true },
  { name: 'state', label: 'State / region', type: 'text' },
  { name: 'tagline', label: 'Tagline', type: 'text' },
  { name: 'description', label: 'Description', type: 'textarea' },
  { name: 'highlights', label: 'Highlights', type: 'list' },
  { name: 'best_time_to_visit', label: 'Best time to visit', type: 'text' },
  { name: 'sort_order', label: 'Sort order', type: 'number', min: 0 },
  { name: 'latitude', label: 'Latitude', type: 'number', step: '0.0000001' },
  { name: 'longitude', label: 'Longitude', type: 'number', step: '0.0000001' },
  { name: 'status', label: 'Status', type: 'select', options: [{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }] },
  { name: 'is_featured', label: 'Featured on home page', type: 'checkbox' },
  { name: 'meta_title', label: 'SEO title', type: 'text' },
  { name: 'meta_description', label: 'SEO description', type: 'text' },
  { name: 'image', label: 'Cover image', type: 'file', span: 2, hint: 'JPG/PNG/WebP, landscape works best' },
];

export default function AdminDestinations() {
  usePageMeta('Destinations — Admin');
  const qc = useQueryClient();
  const [editing, setEditing] = useState<Destination | 'new' | null>(null);
  const [deleting, setDeleting] = useState<Destination | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['admin', 'destinations'], queryFn: () => get<Destination[]>('/admin/destinations') });
  const save = useMutation({
    mutationFn: (v: Record<string, unknown>) => upload(editing === 'new' ? '/admin/destinations' : `/admin/destinations/${(editing as Destination).id}`, { ...v, highlights: v.highlights }),
    onSuccess: (r) => { toast.success(r.message); setEditing(null); setErrors({}); qc.invalidateQueries({ queryKey: ['admin', 'destinations'] }); },
    onError: (e) => { if (e instanceof ApiError) setErrors(e.errors); toast.error((e as Error).message); },
  });
  const remove = useMutation({ mutationFn: (d: Destination) => del(`/admin/destinations/${d.id}`), onSuccess: (r) => { toast.success(r.message); setDeleting(null); refetch(); }, onError: (e) => toast.error((e as Error).message) });

  return (
    <div>
      <AdminHeader title="Destinations" description="Cities and regions shown across search, tours and the home page." actions={<Button onClick={() => setEditing('new')} icon={<Plus className="h-4 w-4" />}>Add destination</Button>} />
      <DataTable rows={data} loading={isLoading} error={error} onRetry={refetch} onRowClick={setEditing}
        columns={[
          { key: 'name', header: 'Destination', cell: (d) => <div className="flex items-center gap-3"><Img src={d.image_url} alt="" className="h-11 w-16 rounded-lg" /><div><p className="font-semibold">{d.name}</p><p className="text-xs text-ink-500">{d.tagline}</p></div></div> },
          { key: 'country', header: 'Country', cell: (d) => d.country },
          { key: 'hotels', header: 'Hotels', cell: (d) => d.hotels_count ?? 0 },
          { key: 'tours', header: 'Tours', cell: (d) => d.packages_count ?? 0 },
          { key: 'featured', header: 'Featured', cell: (d) => (d.is_featured ? 'Yes' : '—') },
          { key: 'status', header: 'Status', cell: (d) => <StatusBadge status={d.status ?? 'active'} /> },
          { key: 'x', header: '', className: 'text-right', cell: (d) => <button onClick={(e) => { e.stopPropagation(); setDeleting(d); }} className="rounded-lg p-1.5 text-ink-400 hover:bg-danger-50 hover:text-danger-600" aria-label={`Delete ${d.name}`}><Trash2 className="h-4 w-4" /></button> },
        ]} />
      <FormModal open={!!editing} onClose={() => { setEditing(null); setErrors({}); }} title={editing === 'new' ? 'Add destination' : 'Edit destination'} fields={FIELDS} errors={errors} submitting={save.isPending}
        initial={editing && editing !== 'new' ? (editing as unknown as Record<string, unknown>) : { status: 'active', sort_order: 0 }} onSubmit={(v) => save.mutate(v)} />
      <ConfirmDialog open={!!deleting} onClose={() => setDeleting(null)} onConfirm={() => deleting && remove.mutate(deleting)} loading={remove.isPending} tone="danger" confirmLabel="Delete" title={`Delete ${deleting?.name}?`} description="Only possible when no hotels or tours use this destination." />
      <p className="mt-4 text-xs text-ink-500">“From” prices on the website are calculated automatically from the lowest active room rate.</p>
    </div>
  );
}
