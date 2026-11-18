import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Plus, Trash2 } from 'lucide-react';
import { ApiError, del, get, getPage, upload } from '@/lib/api';
import { money, titleCase } from '@/lib/format';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog, Img, StatusBadge, Tabs } from '@/components/ui/misc';
import { AdminHeader, DataTable, FormModal, Toolbar, type FieldDef } from '@/components/admin/kit';
import type { CatalogueItem, Destination } from '@/types';

type Kind = 'activities' | 'guides' | 'transportation' | 'meals';
const DAYS = [0, 1, 2, 3, 4, 5, 6].map((d) => ({ value: d, label: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][d]! }));

function fieldsFor(kind: Kind, destinations: Destination[]): FieldDef[] {
  const dest: FieldDef = { name: 'destination_id', label: 'Destination', type: 'select', options: destinations.map((d) => ({ value: d.id, label: d.name })), placeholder: kind === 'transportation' ? 'Any / intercity' : 'Select…' };
  const common: FieldDef[] = [{ name: 'name', label: 'Name', type: 'text', required: true, span: 2 }, dest];
  const status: FieldDef = { name: 'status', label: 'Status', type: 'select', options: [{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }] };
  const image: FieldDef = { name: 'image', label: kind === 'guides' ? 'Profile photo' : 'Image', type: 'file', span: 2 };
  switch (kind) {
    case 'activities': return [...common,
      { name: 'category', label: 'Category', type: 'select', options: ['sightseeing', 'adventure', 'museum', 'theme_park', 'safari', 'cruise', 'trekking', 'water_sports', 'cultural', 'attraction'].map((c) => ({ value: c, label: titleCase(c) })) },
      { name: 'location', label: 'Location', type: 'text' }, { name: 'short_description', label: 'Short description', type: 'text', span: 2 }, { name: 'description', label: 'Description', type: 'textarea' },
      { name: 'duration_minutes', label: 'Duration (minutes)', type: 'number', min: 15 }, { name: 'capacity_per_slot', label: 'Capacity per time slot', type: 'number', min: 1 },
      { name: 'price', label: 'Price per adult (₹)', type: 'number', min: 0 }, { name: 'child_price', label: 'Child price (₹)', type: 'number', min: 0 },
      { name: 'start_times', label: 'Start times (HH:MM, one per line)', type: 'list' }, { name: 'available_days', label: 'Operating days (none = every day)', type: 'multiselect', options: DAYS },
      { name: 'inclusions', label: 'Inclusions', type: 'list' }, status, image];
    case 'guides': return [...common,
      { name: 'languages', label: 'Languages', type: 'list' }, { name: 'specialty', label: 'Specialty', type: 'text' }, { name: 'experience_years', label: 'Years of experience', type: 'number', min: 0 },
      { name: 'bio', label: 'Bio', type: 'textarea' }, { name: 'hourly_rate', label: 'Hourly rate (₹)', type: 'number', min: 0 }, { name: 'full_day_rate', label: 'Full-day rate (8h+)', type: 'number', min: 0 },
      { name: 'min_hours', label: 'Minimum hours', type: 'number', min: 1 }, { name: 'max_hours', label: 'Maximum hours', type: 'number', min: 1 },
      { name: 'available_from', label: 'Available from', type: 'time' }, { name: 'available_to', label: 'Available to', type: 'time' },
      { name: 'available_days', label: 'Working days', type: 'multiselect', options: DAYS }, status, image];
    case 'transportation': return [...common,
      { name: 'type', label: 'Type', type: 'select', options: ['flight', 'train', 'bus', 'taxi', 'private_car', 'rental_car', 'airport_transfer', 'hotel_transfer'].map((t) => ({ value: t, label: titleCase(t) })) },
      { name: 'provider', label: 'Provider', type: 'text' }, { name: 'from_location', label: 'From', type: 'text' }, { name: 'to_location', label: 'To', type: 'text' },
      { name: 'pricing_unit', label: 'Pricing unit', type: 'select', options: [{ value: 'per_person', label: 'Per person' }, { value: 'per_vehicle', label: 'Per vehicle' }, { value: 'per_day', label: 'Per day' }] },
      { name: 'price', label: 'Price (₹)', type: 'number', min: 0 }, { name: 'capacity', label: 'Seats (per vehicle)', type: 'number', min: 1 }, { name: 'duration_minutes', label: 'Duration (minutes)', type: 'number', min: 5 },
      { name: 'departure_times', label: 'Departure times (one per line)', type: 'list' }, { name: 'description', label: 'Description', type: 'textarea' }, status, image];
    case 'meals': return [...common,
      { name: 'meal_type', label: 'Meal type', type: 'select', options: ['breakfast', 'lunch', 'dinner', 'experience'].map((m) => ({ value: m, label: titleCase(m) })) },
      { name: 'venue', label: 'Venue', type: 'text' }, { name: 'cuisine', label: 'Cuisine', type: 'text' }, { name: 'price', label: 'Price per person (₹)', type: 'number', min: 0 },
      { name: 'description', label: 'Description', type: 'textarea' }, status, image];
  }
}

export default function AdminCatalogue() {
  usePageMeta('Tour catalogue — Admin');
  const qc = useQueryClient();
  const [kind, setKind] = useState<Kind>('activities');
  const [q, setQ] = useState('');
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState<CatalogueItem | 'new' | null>(null);
  const [deleting, setDeleting] = useState<CatalogueItem | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const destinations = useQuery({ queryKey: ['admin', 'destinations-opts'], queryFn: () => get<Destination[]>('/destinations') });
  const fields = useMemo(() => fieldsFor(kind, destinations.data ?? []), [kind, destinations.data]);
  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['admin', 'catalogue', kind, q, page], queryFn: () => getPage<CatalogueItem>(`/admin/catalogue/${kind}`, { q: q || undefined, page }) });

  const save = useMutation({
    mutationFn: (v: Record<string, unknown>) => upload(editing === 'new' ? `/admin/catalogue/${kind}` : `/admin/catalogue/${kind}/${(editing as CatalogueItem).id}`, v),
    onSuccess: (r) => { toast.success(r.message); setEditing(null); setErrors({}); qc.invalidateQueries({ queryKey: ['admin', 'catalogue'] }); },
    onError: (e) => { if (e instanceof ApiError) setErrors(e.errors); toast.error((e as Error).message); },
  });
  const remove = useMutation({ mutationFn: (it: CatalogueItem) => del(`/admin/catalogue/${kind}/${it.id}`), onSuccess: (r) => { toast.success(r.message); setDeleting(null); refetch(); }, onError: (e) => toast.error((e as Error).message) });

  return (
    <div>
      <AdminHeader title="Tour catalogue" description="Bookable building blocks for the Tour Builder and tour packages." actions={<Button onClick={() => setEditing('new')} icon={<Plus className="h-4 w-4" />}>Add {kind === 'transportation' ? 'transport' : kind.slice(0, -1)}</Button>} />
      <Tabs className="mb-4 w-fit" value={kind} onChange={(k) => { setKind(k); setPage(1); }} tabs={[{ value: 'activities', label: 'Activities & attractions' }, { value: 'guides', label: 'Guides' }, { value: 'transportation', label: 'Transportation' }, { value: 'meals', label: 'Meals' }]} />
      <Toolbar><input className="input w-64" placeholder="Search" value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} aria-label="Search catalogue" /></Toolbar>
      <DataTable rows={data?.items} loading={isLoading} error={error} onRetry={refetch} pagination={data?.pagination} onPage={setPage} onRowClick={setEditing}
        columns={[
          { key: 'name', header: 'Name', cell: (i) => <div className="flex items-center gap-3"><Img src={i.image_url} alt="" className="h-10 w-14 rounded-lg" /><div><p className="font-semibold">{i.name}</p><p className="text-xs text-ink-500">{(i.category ?? i.type ?? i.meal_type ?? i.specialty) as string}</p></div></div> },
          { key: 'dest', header: 'Destination', cell: (i) => i.destination ?? 'Any' },
          { key: 'price', header: 'Price', cell: (i) => `${money(i.price)} ${i.price_label}` },
          { key: 'status', header: 'Status', cell: (i) => <StatusBadge status={i.status ?? 'active'} /> },
          { key: 'x', header: '', className: 'text-right', cell: (i) => <button onClick={(e) => { e.stopPropagation(); setDeleting(i); }} className="rounded-lg p-1.5 text-ink-400 hover:bg-danger-50 hover:text-danger-600" aria-label={`Archive ${i.name}`}><Trash2 className="h-4 w-4" /></button> },
        ]} />
      <FormModal open={!!editing} onClose={() => { setEditing(null); setErrors({}); }} title={editing === 'new' ? 'Add item' : 'Edit item'} fields={fields} errors={errors} submitting={save.isPending} size="xl"
        initial={editing && editing !== 'new' ? (editing as unknown as Record<string, unknown>) : { status: 'active' }} onSubmit={(v) => save.mutate(v)} />
      <ConfirmDialog open={!!deleting} onClose={() => setDeleting(null)} onConfirm={() => deleting && remove.mutate(deleting)} loading={remove.isPending} tone="danger" confirmLabel="Archive" title={`Archive ${deleting?.name}?`} description="Customer itineraries that include it will show it as unavailable." />
    </div>
  );
}
