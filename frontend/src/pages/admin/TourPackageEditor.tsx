import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { ArrowLeft, Plus, Trash2 } from 'lucide-react';
import { ApiError, del, get, upload } from '@/lib/api';
import { money } from '@/lib/format';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { Button } from '@/components/ui/Button';
import { Checkbox, Input, Select, Textarea } from '@/components/ui/Field';
import { ConfirmDialog, ErrorState, Img, Skeleton } from '@/components/ui/misc';
import { AdminHeader } from '@/components/admin/kit';
import { ITEM_META } from '@/pages/builder/builderShared';
import type { CatalogueItem, Destination, TourPackage } from '@/types';

interface ItemRow { day_number: number; day_title: string; item_type: string; itemable_id: number | ''; title: string; start_time: string; nights: number | ''; hours: number | '' }

export default function TourPackageEditor() {
  const { id } = useParams();
  const isNew = !id;
  const navigate = useNavigate();
  const qc = useQueryClient();
  const pkg = useQuery({ queryKey: ['admin', 'package', id], queryFn: () => get<TourPackage>(`/admin/tour-packages/${id}`), enabled: !isNew });
  usePageMeta(pkg.data ? `${pkg.data.name} — edit` : 'New tour package');
  const destinations = useQuery({ queryKey: ['admin', 'destinations-opts'], queryFn: () => get<Destination[]>('/destinations') });
  const [v, setV] = useState<Record<string, unknown>>({});
  const [items, setItems] = useState<ItemRow[]>([]);
  const [image, setImage] = useState<File | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [archiving, setArchiving] = useState(false);

  useEffect(() => {
    const p = pkg.data;
    setV({
      name: p?.name ?? '', destination_id: p?.destination?.id ?? '', summary: p?.summary ?? '', description: p?.description ?? '', duration_days: p?.duration_days ?? 4,
      duration_nights: p?.duration_nights ?? 3, cities: p?.cities ?? '', default_adults: p?.default_adults ?? 2, max_travelers: p?.max_travelers ?? 12,
      highlights: (p?.highlights ?? []).join('\n'), inclusions: (p?.inclusions ?? []).join('\n'), exclusions: (p?.exclusions ?? []).join('\n'),
      difficulty: p?.difficulty ?? 'easy', is_featured: p?.is_featured ?? false, status: p?.status ?? 'active', meta_title: p?.meta_title ?? '', meta_description: p?.meta_description ?? '',
    });
    setItems((p?.days ?? []).flatMap((d) => d.items.map((i) => ({
      day_number: d.day_number, day_title: d.title, item_type: i.item_type, itemable_id: i.itemable_id ?? '', title: i.title,
      start_time: i.start_time ?? '', nights: i.nights ?? '', hours: i.hours ?? '',
    }))));
  }, [pkg.data]);

  const set = (k: string, val: unknown) => setV((s) => ({ ...s, [k]: val }));
  const lines = (s: unknown) => String(s ?? '').split('\n').map((x) => x.trim()).filter(Boolean);
  const save = useMutation({
    mutationFn: () => upload<TourPackage>(isNew ? '/admin/tour-packages' : `/admin/tour-packages/${id}`, {
      ...v, highlights: lines(v.highlights), inclusions: lines(v.inclusions), exclusions: lines(v.exclusions), image,
      ...Object.fromEntries(items.flatMap((it, i) => Object.entries(it).filter(([, x]) => x !== '' && x !== null).map(([k, x]) => [`items[${i}][${k}]`, x]))),
    }),
    onSuccess: (r) => { toast.success(r.message); setErrors({}); qc.invalidateQueries({ queryKey: ['admin', 'package'] }); qc.invalidateQueries({ queryKey: ['admin', 'packages'] }); if (isNew) navigate(`/admin/tour-packages/${r.data.id}`); },
    onError: (e) => { if (e instanceof ApiError) setErrors(e.errors); toast.error((e as Error).message); },
  });
  const archive = useMutation({ mutationFn: () => del(`/admin/tour-packages/${id}`), onSuccess: (r) => { toast.success(r.message); navigate('/admin/tour-packages'); } });

  if (!isNew && pkg.isLoading) return <Skeleton className="h-96 rounded-2xl" />;
  if (!isNew && pkg.isError) return <ErrorState onRetry={() => pkg.refetch()} />;
  const err = (k: string) => errors[k]?.[0];

  return (
    <div className="space-y-6">
      <Link to="/admin/tour-packages" className="inline-flex items-center gap-1.5 text-sm font-semibold text-ink-500 hover:text-brand-700"><ArrowLeft className="h-4 w-4" /> All packages</Link>
      <AdminHeader title={pkg.data?.name ?? 'New tour package'} description={pkg.data ? `From ${money(pkg.data.price_from)} per person (auto-calculated from items)` : 'Items reference the live catalogue, so prices stay accurate.'}
        actions={!isNew && <Button variant="ghost" className="text-danger-600" onClick={() => setArchiving(true)}>Archive</Button>} />
      <section className="card grid gap-4 p-6 sm:grid-cols-2 lg:grid-cols-3">
        <Input wrapClassName="lg:col-span-2" label="Package name" value={String(v.name ?? '')} onChange={(e) => set('name', e.target.value)} error={err('name')} />
        <Select label="Destination" value={String(v.destination_id ?? '')} onChange={(e) => set('destination_id', Number(e.target.value))} error={err('destination_id')}><option value="">Select…</option>{destinations.data?.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}</Select>
        <Input wrapClassName="sm:col-span-2 lg:col-span-3" label="Summary" maxLength={400} value={String(v.summary ?? '')} onChange={(e) => set('summary', e.target.value)} error={err('summary')} />
        <Textarea wrapClassName="sm:col-span-2 lg:col-span-3" label="Description" value={String(v.description ?? '')} onChange={(e) => set('description', e.target.value)} />
        <Input label="Days" type="number" value={String(v.duration_days)} onChange={(e) => set('duration_days', Number(e.target.value))} error={err('duration_days')} />
        <Input label="Nights" type="number" value={String(v.duration_nights)} onChange={(e) => set('duration_nights', Number(e.target.value))} />
        <Input label="Cities" value={String(v.cities ?? '')} onChange={(e) => set('cities', e.target.value)} placeholder="Dubai · Abu Dhabi" />
        <Input label="Default adults (for 'from' price)" type="number" value={String(v.default_adults)} onChange={(e) => set('default_adults', Number(e.target.value))} />
        <Input label="Max travellers" type="number" value={String(v.max_travelers)} onChange={(e) => set('max_travelers', Number(e.target.value))} />
        <Select label="Difficulty" value={String(v.difficulty)} onChange={(e) => set('difficulty', e.target.value)}><option value="easy">Easy</option><option value="moderate">Moderate</option><option value="challenging">Challenging</option></Select>
        <Textarea label="Highlights (one per line)" rows={4} value={String(v.highlights ?? '')} onChange={(e) => set('highlights', e.target.value)} />
        <Textarea label="Inclusions (one per line)" rows={4} value={String(v.inclusions ?? '')} onChange={(e) => set('inclusions', e.target.value)} />
        <Textarea label="Exclusions (one per line)" rows={4} value={String(v.exclusions ?? '')} onChange={(e) => set('exclusions', e.target.value)} />
        <Select label="Status" value={String(v.status)} onChange={(e) => set('status', e.target.value)}><option value="active">Active</option><option value="inactive">Inactive</option></Select>
        <div className="flex items-end"><Checkbox label="Featured" checked={!!v.is_featured} onChange={(e) => set('is_featured', e.target.checked)} /></div>
        <label className="block"><span className="label">Cover image</span>{pkg.data?.image_url && <Img src={pkg.data.image_url} alt="" className="mb-2 h-20 w-32 rounded-lg" />}<input type="file" accept="image/jpeg,image/png,image/webp" onChange={(e) => setImage(e.target.files?.[0] ?? null)} className="text-sm" /></label>
      </section>

      <section className="card p-6">
        <div className="flex items-center justify-between"><h2 className="font-sans text-lg font-bold">Itinerary items</h2>
          <Button size="sm" variant="secondary" icon={<Plus className="h-4 w-4" />} onClick={() => setItems((x) => [...x, { day_number: x.at(-1)?.day_number ?? 1, day_title: x.at(-1)?.day_title ?? '', item_type: 'activity', itemable_id: '', title: '', start_time: '', nights: '', hours: '' }])}>Add item</Button></div>
        {errors.items && <p className="mt-2 text-sm text-danger-600">{errors.items[0]}</p>}
        <div className="mt-4 space-y-3">{items.map((it, i) => <ItemEditor key={i} row={it} destinationId={Number(v.destination_id) || undefined} onChange={(r) => setItems((x) => x.map((y, j) => (j === i ? r : y)))} onRemove={() => setItems((x) => x.filter((_, j) => j !== i))} />)}
          {!items.length && <p className="text-sm text-ink-500">No items yet.</p>}</div>
      </section>
      <div className="flex justify-end"><Button size="lg" loading={save.isPending} onClick={() => save.mutate()}>{isNew ? 'Create package' : 'Save package'}</Button></div>
      <ConfirmDialog open={archiving} onClose={() => setArchiving(false)} onConfirm={() => archive.mutate()} loading={archive.isPending} tone="danger" confirmLabel="Archive" title="Archive this package?" description="Existing customer trips copied from it are not affected." />
    </div>
  );
}

function ItemEditor({ row, destinationId, onChange, onRemove }: { row: ItemRow; destinationId?: number; onChange: (r: ItemRow) => void; onRemove: () => void }) {
  const meta = ITEM_META[row.item_type]!;
  const opts = useQuery({
    queryKey: ['catalogue', meta.kind, meta.transportType, destinationId, row.item_type],
    queryFn: () => get<CatalogueItem[]>(`/catalogue/${meta.kind}`, { destination_id: meta.kind === 'transportation' && row.item_type === 'flight' ? undefined : destinationId, type: meta.transportType, category: row.item_type === 'attraction' ? 'attraction' : undefined }),
    enabled: meta.kind !== 'none',
    staleTime: 60_000,
  });
  return (
    <div className="grid gap-2 rounded-xl border border-ink-200 p-3 sm:grid-cols-[70px_1fr_150px_1.6fr_90px_70px_70px_auto]">
      <Input label="Day" type="number" min={1} value={row.day_number} onChange={(e) => onChange({ ...row, day_number: Number(e.target.value) })} />
      <Input label="Day title" value={row.day_title} onChange={(e) => onChange({ ...row, day_title: e.target.value })} />
      <Select label="Type" value={row.item_type} onChange={(e) => onChange({ ...row, item_type: e.target.value, itemable_id: '' })}>{Object.entries(ITEM_META).map(([k, m]) => <option key={k} value={k}>{m.label}</option>)}</Select>
      {meta.kind === 'none' ? <Input label="Title" value={row.title} onChange={(e) => onChange({ ...row, title: e.target.value })} /> : (
        <Select label="Catalogue item" value={String(row.itemable_id)} onChange={(e) => { const it = opts.data?.find((o) => o.id === Number(e.target.value)); onChange({ ...row, itemable_id: Number(e.target.value), title: it ? (it.kind === 'hotel_room' ? `${it.hotel_name as string} — ${it.name}` : it.name) : row.title }); }}>
          <option value="">Select…</option>{opts.data?.map((o) => <option key={o.id} value={o.id}>{o.kind === 'hotel_room' ? `${o.hotel_name as string} — ${o.name}` : o.name} ({money(o.price)})</option>)}
        </Select>
      )}
      <Input label="Time" type="time" value={row.start_time} onChange={(e) => onChange({ ...row, start_time: e.target.value })} />
      <Input label="Nights" type="number" min={1} disabled={row.item_type !== 'accommodation'} value={row.nights} onChange={(e) => onChange({ ...row, nights: e.target.value ? Number(e.target.value) : '' })} />
      <Input label="Hours" type="number" min={1} disabled={row.item_type !== 'guide'} value={row.hours} onChange={(e) => onChange({ ...row, hours: e.target.value ? Number(e.target.value) : '' })} />
      <div className="flex items-end"><button onClick={onRemove} className="rounded-lg p-2 text-ink-400 hover:bg-danger-50 hover:text-danger-600" aria-label="Remove item"><Trash2 className="h-4 w-4" /></button></div>
    </div>
  );
}
