import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { addMonths, format, getDay, parseISO, startOfMonth } from 'date-fns';
import { ArrowLeft, ChevronLeft, ChevronRight, Crown, ImagePlus, Pencil, Plus, Star, Trash2 } from 'lucide-react';
import { ApiError, del, get, post, put, upload } from '@/lib/api';
import { cn, fmtDate, money } from '@/lib/format';
import { useAuth } from '@/providers/AuthProvider';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { Button } from '@/components/ui/Button';
import { Checkbox, Input, Select, Textarea } from '@/components/ui/Field';
import { Badge, ConfirmDialog, ErrorState, Img, Modal, Skeleton, Spinner, StatusBadge, Tabs } from '@/components/ui/misc';
import { AdminHeader, FormModal, type FieldDef } from '@/components/admin/kit';
import type { Amenity, Destination, Hotel, Room } from '@/types';

type Tab = 'details' | 'photos' | 'rooms' | 'availability';

export default function HotelEditor({ partner = false }: { partner?: boolean }) {
  const { id } = useParams();
  const isNew = !id;
  const base = partner ? '/partner' : '/admin';
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { can } = useAuth();
  const staff = !partner && can('hotels.manage');
  const [tab, setTab] = useState<Tab>('details');
  const key = ['admin', 'hotel', base, id];
  const hotel = useQuery({ queryKey: key, queryFn: () => get<Hotel>(`${base}/hotels/${id}`), enabled: !isNew });
  usePageMeta(hotel.data ? `${hotel.data.name} — edit` : 'New hotel');

  if (!isNew && hotel.isLoading) return <Skeleton className="h-96 rounded-2xl" />;
  if (!isNew && hotel.isError) return <ErrorState message={(hotel.error as Error).message} onRetry={() => hotel.refetch()} />;
  const h = hotel.data;

  return (
    <div>
      <Link to={partner ? '/partner' : '/admin/hotels'} className="mb-3 inline-flex items-center gap-1.5 text-sm font-semibold text-ink-500 hover:text-brand-700"><ArrowLeft className="h-4 w-4" /> Back</Link>
      <AdminHeader title={h?.name ?? 'Add a hotel'} description={h ? `${h.city}, ${h.country}` : 'Create the property, then add photos and rooms.'}
        actions={h && <div className="flex items-center gap-2">{h.is_membership_eligible && <Badge tone="sand" icon={<Crown className="h-3 w-3" />}>Membership eligible</Badge>}<StatusBadge status={h.status ?? 'active'} />
          {h.status === 'active' && <a href={`/hotels/${h.slug}`} target="_blank" rel="noreferrer" className="link text-sm">View live</a>}</div>} />
      {!isNew && <Tabs className="mb-6 w-fit" value={tab} onChange={setTab} tabs={[{ value: 'details', label: 'Details' }, { value: 'photos', label: `Photos (${h?.images?.length ?? 0})` }, { value: 'rooms', label: `Rooms (${h?.rooms?.length ?? 0})` }, { value: 'availability', label: 'Availability & pricing' }]} />}
      {tab === 'details' && <DetailsForm hotel={h} base={base} staff={staff} partner={partner} onSaved={(saved) => { qc.setQueryData(key, saved); qc.invalidateQueries({ queryKey: ['admin', 'hotels'] }); if (isNew) navigate(`/admin/hotels/${saved.id}`); }} />}
      {tab === 'photos' && h && <Photos hotel={h} base={base} onChange={() => hotel.refetch()} />}
      {tab === 'rooms' && h && <Rooms hotel={h} base={base} staff={staff} onChange={() => hotel.refetch()} />}
      {tab === 'availability' && h && <Availability hotel={h} base={base} />}
    </div>
  );
}

// ------------------------------------------------------------------ details
function DetailsForm({ hotel, base, staff, partner, onSaved }: { hotel?: Hotel; base: string; staff: boolean; partner: boolean; onSaved: (h: Hotel) => void }) {
  const amenities = useQuery({ queryKey: ['admin', 'amenities', base], queryFn: () => get<Amenity[]>(`${base}/amenities`) });
  const destinations = useQuery({ queryKey: ['admin', 'destinations-opts'], queryFn: () => get<Destination[]>('/destinations'), enabled: !partner });
  const partners = useQuery({ queryKey: ['admin', 'partner-candidates'], queryFn: () => get<{ id: number; name: string; email: string }[]>('/admin/partner-candidates'), enabled: staff });
  const [v, setV] = useState<Record<string, unknown>>({});
  const [policy, setPolicy] = useState<Record<string, unknown>>({});
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  useEffect(() => {
    setV({
      name: hotel?.name ?? '', destination_id: hotel?.destination?.id ?? '', property_type: hotel?.property_type ?? 'hotel', star_rating: hotel?.star_rating ?? 4,
      short_description: hotel?.short_description ?? '', description: hotel?.description ?? '', address: hotel?.address ?? '', city: hotel?.city ?? '', state: hotel?.state ?? '',
      country: hotel?.country ?? 'India', postal_code: hotel?.postal_code ?? '', latitude: hotel?.latitude ?? '', longitude: hotel?.longitude ?? '',
      distance_from_center_km: hotel?.distance_from_center_km ?? '', check_in_time: hotel?.check_in_time ?? '14:00', check_out_time: hotel?.check_out_time ?? '11:00',
      phone: hotel?.phone ?? '', email: hotel?.email ?? '', is_membership_eligible: hotel?.is_membership_eligible ?? false, is_featured: hotel?.is_featured ?? false,
      status: hotel?.status ?? 'draft', meta_title: hotel?.meta_title ?? '', meta_description: hotel?.meta_description ?? '',
      amenity_ids: hotel?.amenities?.map((a) => a.id) ?? [], partner_ids: hotel?.partners?.map((p) => p.id) ?? [],
    });
    setPolicy({ ...(hotel?.policy ?? {}) });
  }, [hotel]);
  const set = (k: string, val: unknown) => setV((s) => ({ ...s, [k]: val }));
  const save = useMutation({
    mutationFn: () => {
      const body = Object.fromEntries(Object.entries({ ...v, policy }).map(([k, x]) => [k, (x as unknown) === '' ? null : x]));
      if (!staff) ['destination_id', 'is_membership_eligible', 'is_featured', 'status', 'partner_ids', 'star_rating'].forEach((k) => delete body[k]);
      return hotel ? put<Hotel>(`${base}/hotels/${hotel.id}`, body) : post<Hotel>('/admin/hotels', body);
    },
    onSuccess: (r) => { toast.success(r.message); setErrors({}); onSaved(r.data); },
    onError: (e) => { if (e instanceof ApiError) setErrors(e.errors); toast.error((e as Error).message); },
  });
  const err = (k: string) => errors[k]?.[0];
  const toggleIn = (k: string, idv: number) => { const arr = (v[k] as number[]) ?? []; set(k, arr.includes(idv) ? arr.filter((x) => x !== idv) : [...arr, idv]); };

  return (
    <form onSubmit={(e) => { e.preventDefault(); save.mutate(); }} className="space-y-6" noValidate>
      <section className="card grid gap-4 p-6 sm:grid-cols-2 lg:grid-cols-3">
        <Input wrapClassName="lg:col-span-2" label="Hotel name" required value={String(v.name ?? '')} onChange={(e) => set('name', e.target.value)} error={err('name')} />
        {staff && <Select label="Destination" required value={String(v.destination_id ?? '')} onChange={(e) => set('destination_id', Number(e.target.value))} error={err('destination_id')}>
          <option value="">Select…</option>{destinations.data?.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}</Select>}
        <Select label="Property type" value={String(v.property_type)} onChange={(e) => set('property_type', e.target.value)}>{['hotel', 'resort', 'villa', 'boutique', 'apartment', 'homestay'].map((t) => <option key={t} value={t}>{t[0]!.toUpperCase() + t.slice(1)}</option>)}</Select>
        {staff && <Select label="Star rating" value={String(v.star_rating)} onChange={(e) => set('star_rating', Number(e.target.value))}>{[1, 2, 3, 4, 5].map((s) => <option key={s} value={s}>{s} star</option>)}</Select>}
        {staff && <Select label="Status" value={String(v.status)} onChange={(e) => set('status', e.target.value)}><option value="draft">Draft</option><option value="active">Active (bookable)</option><option value="inactive">Inactive</option></Select>}
        <Input wrapClassName="sm:col-span-2 lg:col-span-3" label="Short description" hint="Shown on hotel cards (max 300 characters)" maxLength={300} value={String(v.short_description ?? '')} onChange={(e) => set('short_description', e.target.value)} error={err('short_description')} />
        <Textarea wrapClassName="sm:col-span-2 lg:col-span-3" label="Full description" rows={6} value={String(v.description ?? '')} onChange={(e) => set('description', e.target.value)} />
      </section>
      <section className="card grid gap-4 p-6 sm:grid-cols-2 lg:grid-cols-3">
        <Input wrapClassName="sm:col-span-2" label="Address" required value={String(v.address ?? '')} onChange={(e) => set('address', e.target.value)} error={err('address')} />
        <Input label="City" required value={String(v.city ?? '')} onChange={(e) => set('city', e.target.value)} error={err('city')} />
        <Input label="State" value={String(v.state ?? '')} onChange={(e) => set('state', e.target.value)} />
        <Input label="Country" required value={String(v.country ?? '')} onChange={(e) => set('country', e.target.value)} error={err('country')} />
        <Input label="Postal code" value={String(v.postal_code ?? '')} onChange={(e) => set('postal_code', e.target.value)} />
        <Input label="Latitude" type="number" step="0.0000001" value={String(v.latitude ?? '')} onChange={(e) => set('latitude', e.target.value)} error={err('latitude')} />
        <Input label="Longitude" type="number" step="0.0000001" value={String(v.longitude ?? '')} onChange={(e) => set('longitude', e.target.value)} error={err('longitude')} />
        <Input label="Distance from centre (km)" type="number" step="0.1" value={String(v.distance_from_center_km ?? '')} onChange={(e) => set('distance_from_center_km', e.target.value)} />
        <Input label="Check-in from" type="time" value={String(v.check_in_time)} onChange={(e) => set('check_in_time', e.target.value)} error={err('check_in_time')} />
        <Input label="Check-out until" type="time" value={String(v.check_out_time)} onChange={(e) => set('check_out_time', e.target.value)} error={err('check_out_time')} />
        <Input label="Reservations phone" value={String(v.phone ?? '')} onChange={(e) => set('phone', e.target.value)} />
        <Input label="Reservations email" type="email" value={String(v.email ?? '')} onChange={(e) => set('email', e.target.value)} error={err('email')} />
      </section>
      <section className="card p-6">
        <h2 className="font-sans font-bold">Amenities</h2>
        <div className="mt-3 flex flex-wrap gap-1.5">
          {amenities.data?.filter((a) => a.scope !== 'room').map((a) => <button type="button" key={a.id} onClick={() => toggleIn('amenity_ids', a.id)} className={cn('chip text-xs', (v.amenity_ids as number[] | undefined)?.includes(a.id) && 'chip-active')}>{a.name}</button>)}
        </div>
      </section>
      <section className="card grid gap-4 p-6 sm:grid-cols-2">
        <h2 className="font-sans font-bold sm:col-span-2">Policies</h2>
        {(['cancellation_policy', 'children_policy', 'pet_policy', 'smoking_policy', 'extra_policies'] as const).map((k) => (
          <Textarea key={k} label={k.replace('_', ' ').replace(/\b\w/g, (c) => c.toUpperCase())} rows={3} value={String(policy[k] ?? '')} onChange={(e) => setPolicy((p) => ({ ...p, [k]: e.target.value }))} />
        ))}
        <div className="flex flex-col justify-end gap-2"><Checkbox label="Pets allowed" checked={!!policy.pets_allowed} onChange={(e) => setPolicy((p) => ({ ...p, pets_allowed: e.target.checked }))} /><Checkbox label="Smoking allowed" checked={!!policy.smoking_allowed} onChange={(e) => setPolicy((p) => ({ ...p, smoking_allowed: e.target.checked }))} /></div>
      </section>
      {staff && (
        <section className="card grid gap-4 p-6 sm:grid-cols-2">
          <h2 className="font-sans font-bold sm:col-span-2">Platform settings</h2>
          <Checkbox label="Membership eligible (members can use night credits on eligible rooms)" checked={!!v.is_membership_eligible} onChange={(e) => set('is_membership_eligible', e.target.checked)} />
          <Checkbox label="Featured on the home page" checked={!!v.is_featured} onChange={(e) => set('is_featured', e.target.checked)} />
          <Input label="SEO title" value={String(v.meta_title ?? '')} onChange={(e) => set('meta_title', e.target.value)} />
          <Input label="SEO description" maxLength={300} value={String(v.meta_description ?? '')} onChange={(e) => set('meta_description', e.target.value)} />
          <fieldset className="sm:col-span-2"><legend className="label">Hotel partner accounts (can manage this hotel)</legend>
            <div className="flex flex-wrap gap-1.5">{partners.data?.map((p) => <button type="button" key={p.id} onClick={() => toggleIn('partner_ids', p.id)} className={cn('chip text-xs', (v.partner_ids as number[] | undefined)?.includes(p.id) && 'chip-active')}>{p.name} · {p.email}</button>)}
              {!partners.data?.length && <p className="text-sm text-ink-500">No partner accounts yet — create one under Roles & staff.</p>}</div>
          </fieldset>
        </section>
      )}
      <div className="flex justify-end"><Button type="submit" size="lg" loading={save.isPending}>{hotel ? 'Save changes' : 'Create hotel'}</Button></div>
    </form>
  );
}

// ------------------------------------------------------------------ photos
function Photos({ hotel, base, onChange }: { hotel: Hotel; base: string; onChange: () => void }) {
  const [files, setFiles] = useState<File[]>([]);
  const up = useMutation({ mutationFn: () => upload(`${base}/hotels/${hotel.id}/images`, { images: files }), onSuccess: (r) => { toast.success(r.message); setFiles([]); onChange(); }, onError: (e) => toast.error((e as Error).message) });
  const remove = useMutation({ mutationFn: (imgId: number) => del(`${base}/hotels/${hotel.id}/images/${imgId}`), onSuccess: (r) => { toast.success(r.message); onChange(); }, onError: (e) => toast.error((e as Error).message) });
  const cover = useMutation({ mutationFn: (imgId: number) => post(`${base}/hotels/${hotel.id}/images/${imgId}/cover`), onSuccess: (r) => { toast.success(r.message); onChange(); }, onError: (e) => toast.error((e as Error).message) });
  return (
    <div className="space-y-6">
      <section className="card flex flex-wrap items-center gap-4 p-5">
        <ImagePlus className="h-6 w-6 text-brand-600" />
        <div className="flex-1"><p className="font-semibold">Upload photos</p><p className="text-xs text-ink-500">JPG, PNG or WebP · up to 6 MB · min 300×200. Images are re-encoded and resized automatically.</p></div>
        <input type="file" multiple accept="image/jpeg,image/png,image/webp" onChange={(e) => setFiles(Array.from(e.target.files ?? []).slice(0, 10))} className="text-sm" aria-label="Choose photos" />
        <Button onClick={() => up.mutate()} disabled={!files.length} loading={up.isPending}>Upload {files.length || ''}</Button>
      </section>
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {hotel.images?.map((img) => (
          <figure key={img.id} className="card overflow-hidden">
            <Img src={img.url} alt="" className="h-40 w-full" />
            <figcaption className="flex items-center justify-between p-2">
              {img.is_cover ? <Badge tone="brand" icon={<Star className="h-3 w-3" />}>Cover</Badge> : <Button size="sm" variant="ghost" onClick={() => cover.mutate(img.id)}>Set cover</Button>}
              <button onClick={() => remove.mutate(img.id)} className="rounded-lg p-1.5 text-ink-500 hover:bg-danger-50 hover:text-danger-600" aria-label="Delete photo"><Trash2 className="h-4 w-4" /></button>
            </figcaption>
          </figure>
        ))}
      </div>
    </div>
  );
}

// ------------------------------------------------------------------ rooms
const ROOM_FIELDS = (staff: boolean, amenities: Amenity[]): FieldDef[] => [
  { name: 'name', label: 'Room name', type: 'text', required: true },
  { name: 'room_type', label: 'Room type', type: 'select', options: ['standard', 'deluxe', 'premium', 'suite', 'family', 'villa'].map((t) => ({ value: t, label: t[0]!.toUpperCase() + t.slice(1) })) },
  { name: 'description', label: 'Description', type: 'textarea' },
  { name: 'max_guests', label: 'Max guests', type: 'number', min: 1 },
  { name: 'max_adults', label: 'Max adults', type: 'number', min: 1 },
  { name: 'max_children', label: 'Max children', type: 'number', min: 0 },
  { name: 'bed_type', label: 'Bed type', type: 'text' },
  { name: 'size_sqft', label: 'Size (sq ft)', type: 'number' },
  { name: 'total_rooms', label: 'Number of rooms (inventory)', type: 'number', min: 0, hint: 'Cannot go below rooms already booked' },
  { name: 'base_price', label: 'Base price / night (₹)', type: 'number', min: 0 },
  { name: 'weekend_price', label: 'Weekend price (Fri & Sat)', type: 'number', min: 0 },
  { name: 'free_cancellation_hours', label: 'Free cancellation (hours before check-in)', type: 'number', min: 0 },
  { name: 'cancellation_policy', label: 'Cancellation policy text', type: 'textarea', rows: 2 },
  { name: 'breakfast_included', label: 'Breakfast included', type: 'checkbox' },
  { name: 'free_cancellation', label: 'Free cancellation available', type: 'checkbox' },
  ...(staff ? [{ name: 'is_membership_eligible', label: 'Membership eligible', type: 'checkbox' } as FieldDef] : []),
  { name: 'status', label: 'Status', type: 'select', options: [{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }] },
  { name: 'amenity_ids', label: 'Room amenities', type: 'multiselect', options: amenities.filter((a) => a.scope !== 'hotel').map((a) => ({ value: a.id, label: a.name })) },
];

function Rooms({ hotel, base, staff, onChange }: { hotel: Hotel; base: string; staff: boolean; onChange: () => void }) {
  const [editing, setEditing] = useState<Room | 'new' | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [seasonRoom, setSeasonRoom] = useState<Room | null>(null);
  const [archiving, setArchiving] = useState<Room | null>(null);
  const amenities = useQuery({ queryKey: ['admin', 'amenities', base], queryFn: () => get<Amenity[]>(`${base}/amenities`) });
  const fields = useMemo(() => ROOM_FIELDS(staff, amenities.data ?? []), [staff, amenities.data]);
  const save = useMutation({
    mutationFn: (v: Record<string, unknown>) => (editing === 'new' ? post(`${base}/hotels/${hotel.id}/rooms`, v) : put(`${base}/hotels/${hotel.id}/rooms/${(editing as Room).id}`, v)),
    onSuccess: (r) => { toast.success(r.message); setEditing(null); setErrors({}); onChange(); },
    onError: (e) => { if (e instanceof ApiError) setErrors(e.errors); toast.error((e as Error).message); },
  });
  const archive = useMutation({ mutationFn: (r: Room) => del(`/admin/hotels/${hotel.id}/rooms/${r.id}`), onSuccess: (r) => { toast.success(r.message); setArchiving(null); onChange(); }, onError: (e) => toast.error((e as Error).message) });
  const photos = useMutation({ mutationFn: ({ room, files }: { room: Room; files: File[] }) => upload(`${base}/hotels/${hotel.id}/rooms/${room.id}/images`, { images: files }), onSuccess: (r) => { toast.success(r.message); onChange(); }, onError: (e) => toast.error((e as Error).message) });
  const delPhoto = useMutation({ mutationFn: ({ room, img }: { room: Room; img: number }) => del(`${base}/hotels/${hotel.id}/rooms/${room.id}/images/${img}`), onSuccess: () => onChange(), onError: (e) => toast.error((e as Error).message) });

  return (
    <div className="space-y-4">
      <div className="flex justify-end"><Button onClick={() => setEditing('new')} icon={<Plus className="h-4 w-4" />}>Add room type</Button></div>
      {hotel.rooms?.map((r) => (
        <article key={r.id} className="card p-5">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <div className="flex flex-wrap items-center gap-2"><h3 className="font-sans text-lg font-bold">{r.name}</h3><Badge className="capitalize">{r.room_type}</Badge>{r.is_membership_eligible && <Badge tone="sand" icon={<Crown className="h-3 w-3" />}>Member</Badge>}<StatusBadge status={r.status ?? 'active'} /></div>
              <p className="mt-1 text-sm text-ink-600">{r.total_rooms} rooms · sleeps {r.max_guests} · {r.bed_type} · {money(r.base_price)}{r.weekend_price ? ` / ${money(r.weekend_price)} weekend` : ''}</p>
              {!!r.seasonal_prices?.length && <p className="mt-1 text-xs text-ink-500">Seasons: {r.seasonal_prices.map((s) => `${s.name} (${fmtDate(s.start_date, 'd MMM')}–${fmtDate(s.end_date, 'd MMM')}: ${money(s.price)})`).join(' · ')}</p>}
            </div>
            <div className="flex flex-wrap gap-2">
              <Button size="sm" variant="outline" onClick={() => setSeasonRoom(r)}>Seasonal prices</Button>
              <Button size="sm" variant="outline" onClick={() => setEditing(r)} icon={<Pencil className="h-3.5 w-3.5" />}>Edit</Button>
              {staff && <Button size="sm" variant="ghost" className="text-danger-600" onClick={() => setArchiving(r)}>Archive</Button>}
            </div>
          </div>
          <div className="mt-4 flex flex-wrap items-center gap-3">
            {r.images?.map((img) => (
              <div key={img.id} className="relative"><Img src={img.url} alt="" className="h-20 w-28 rounded-lg" />
                <button onClick={() => delPhoto.mutate({ room: r, img: img.id })} className="absolute right-1 top-1 rounded-md bg-white/90 p-1 text-danger-600" aria-label="Delete room photo"><Trash2 className="h-3.5 w-3.5" /></button></div>
            ))}
            <label className="flex h-20 w-28 cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed border-ink-300 text-xs text-ink-500 hover:border-brand-500">
              <ImagePlus className="h-5 w-5" /> Add photos
              <input type="file" multiple accept="image/jpeg,image/png,image/webp" className="sr-only" onChange={(e) => e.target.files?.length && photos.mutate({ room: r, files: Array.from(e.target.files) })} />
            </label>
          </div>
        </article>
      ))}
      <FormModal open={!!editing} onClose={() => { setEditing(null); setErrors({}); }} title={editing === 'new' ? 'Add room type' : `Edit ${(editing as Room | null)?.name ?? ''}`} fields={fields} errors={errors} submitting={save.isPending}
        initial={editing && editing !== 'new' ? { ...editing, amenity_ids: editing.amenities?.map((a) => a.id) } : { room_type: 'deluxe', max_guests: 2, max_adults: 2, max_children: 1, total_rooms: 10, status: 'active', free_cancellation: true, free_cancellation_hours: 48, bed_type: 'King' }}
        onSubmit={(v) => save.mutate(v)} />
      {seasonRoom && <SeasonModal hotel={hotel} room={seasonRoom} base={base} onClose={() => setSeasonRoom(null)} onChange={onChange} />}
      <ConfirmDialog open={!!archiving} onClose={() => setArchiving(null)} onConfirm={() => archiving && archive.mutate(archiving)} loading={archive.isPending} tone="danger" confirmLabel="Archive room" title={`Archive ${archiving?.name}?`} description="Rooms with upcoming bookings can't be archived — set them to inactive instead." />
    </div>
  );
}

function SeasonModal({ hotel, room, base, onClose, onChange }: { hotel: Hotel; room: Room; base: string; onClose: () => void; onChange: () => void }) {
  const [v, setV] = useState({ name: '', start_date: '', end_date: '', price: '', weekend_price: '' });
  const add = useMutation({ mutationFn: () => post(`${base}/hotels/${hotel.id}/rooms/${room.id}/seasons`, { ...v, weekend_price: v.weekend_price || null }), onSuccess: (r) => { toast.success(r.message); onChange(); onClose(); }, onError: (e) => toast.error((e as Error).message) });
  const remove = useMutation({ mutationFn: (sid: number) => del(`${base}/hotels/${hotel.id}/rooms/${room.id}/seasons/${sid}`), onSuccess: (r) => { toast.success(r.message); onChange(); onClose(); }, onError: (e) => toast.error((e as Error).message) });
  return (
    <Modal open onClose={onClose} title={`Seasonal prices — ${room.name}`} description="Season prices replace the base/weekend price for nights in the range. Date-specific overrides (Availability tab) win over seasons."
      footer={<Button onClick={() => add.mutate()} loading={add.isPending} disabled={!v.name || !v.start_date || !v.end_date || !v.price}>Add season</Button>}>
      <ul className="mb-5 space-y-2">{room.seasonal_prices?.map((s) => <li key={s.id} className="flex items-center justify-between rounded-xl bg-ink-50 px-3 py-2 text-sm"><span>{s.name}: {fmtDate(s.start_date)} – {fmtDate(s.end_date)} · {money(s.price)}{s.weekend_price ? ` / ${money(s.weekend_price)}` : ''}</span><button onClick={() => remove.mutate(s.id)} className="text-danger-600" aria-label="Remove season"><Trash2 className="h-4 w-4" /></button></li>)}
        {!room.seasonal_prices?.length && <li className="text-sm text-ink-500">No seasons yet.</li>}</ul>
      <div className="grid gap-3 sm:grid-cols-2">
        <Input wrapClassName="sm:col-span-2" label="Season name" value={v.name} onChange={(e) => setV({ ...v, name: e.target.value })} placeholder="e.g. Diwali peak" />
        <Input label="From" type="date" value={v.start_date} onChange={(e) => setV({ ...v, start_date: e.target.value })} />
        <Input label="To" type="date" value={v.end_date} onChange={(e) => setV({ ...v, end_date: e.target.value })} />
        <Input label="Price / night" type="number" value={v.price} onChange={(e) => setV({ ...v, price: e.target.value })} />
        <Input label="Weekend price (optional)" type="number" value={v.weekend_price} onChange={(e) => setV({ ...v, weekend_price: e.target.value })} />
      </div>
    </Modal>
  );
}

// ------------------------------------------------------------------ availability calendar
interface CalDay { date: string; total: number; booked: number; blocked: number; available: number; is_closed: boolean; price: number; price_override: number | null; note: string | null; is_past: boolean }

function Availability({ hotel, base }: { hotel: Hotel; base: string }) {
  const [roomId, setRoomId] = useState<number | undefined>(hotel.rooms?.[0]?.id);
  const [month, setMonth] = useState(startOfMonth(new Date()));
  const [range, setRange] = useState<{ start?: string; end?: string }>({});
  const [form, setForm] = useState({ blocked_count: '', total_inventory: '', price_override: '', is_closed: '', note: '' });
  const qc = useQueryClient();
  const cal = useQuery({
    queryKey: ['admin', 'calendar', roomId, format(month, 'yyyy-MM')],
    queryFn: () => get<{ room: { name: string; total_rooms: number }; days: CalDay[]; summary: { room_nights_sold: number; occupancy_percent: number } }>(`${base}/hotels/${hotel.id}/rooms/${roomId}/availability`, { month: format(month, 'yyyy-MM') }),
    enabled: !!roomId,
  });
  const update = useMutation({
    mutationFn: () => put(`${base}/hotels/${hotel.id}/rooms/${roomId}/availability`, {
      start_date: range.start, end_date: range.end ?? range.start,
      blocked_count: form.blocked_count === '' ? null : Number(form.blocked_count),
      total_inventory: form.total_inventory === '' ? null : Number(form.total_inventory),
      price_override: form.price_override === '' ? null : Number(form.price_override),
      clear_price_override: form.price_override === 'clear',
      is_closed: form.is_closed === '' ? null : form.is_closed === '1',
      note: form.note || null,
    }),
    onSuccess: (r) => { toast.success(r.message); setRange({}); qc.invalidateQueries({ queryKey: ['admin', 'calendar'] }); },
    onError: (e) => toast.error((e as Error).message),
  });
  const clickDay = (d: CalDay) => {
    if (d.is_past) return;
    if (!range.start || range.end) setRange({ start: d.date });
    else setRange(d.date < range.start ? { start: d.date, end: range.start } : { start: range.start, end: d.date });
  };
  const inRange = (d: string) => range.start && (d === range.start || (range.end && d >= range.start && d <= range.end));
  const lead = cal.data?.days.length ? (getDay(parseISO(cal.data.days[0]!.date)) + 6) % 7 : 0;

  if (!hotel.rooms?.length) return <p className="text-ink-500">Add a room type first.</p>;
  return (
    <div className="grid gap-6 xl:grid-cols-[1fr_320px]">
      <section className="card p-5">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <select className="input w-auto" value={roomId} onChange={(e) => { setRoomId(Number(e.target.value)); setRange({}); }} aria-label="Room type">{hotel.rooms.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}</select>
          <div className="flex items-center gap-2">
            <Button variant="outline" size="icon" onClick={() => setMonth((m) => addMonths(m, -1))} aria-label="Previous month"><ChevronLeft className="h-4 w-4" /></Button>
            <span className="w-36 text-center font-semibold">{format(month, 'MMMM yyyy')}</span>
            <Button variant="outline" size="icon" onClick={() => setMonth((m) => addMonths(m, 1))} aria-label="Next month"><ChevronRight className="h-4 w-4" /></Button>
          </div>
        </div>
        {cal.data && <p className="mt-2 text-sm text-ink-500">{cal.data.summary.room_nights_sold} room nights sold · {cal.data.summary.occupancy_percent}% occupancy</p>}
        {cal.isLoading ? <Spinner /> : (
          <div className="mt-4 grid grid-cols-7 gap-1.5 text-center">
            {['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map((d) => <div key={d} className="text-xs font-semibold text-ink-500">{d}</div>)}
            {Array.from({ length: lead }).map((_, i) => <div key={`l${i}`} />)}
            {cal.data?.days.map((d) => {
              const pct = d.total ? (d.booked + d.blocked) / d.total : 1;
              return (
                <button key={d.date} onClick={() => clickDay(d)} disabled={d.is_past} aria-pressed={!!inRange(d.date)} aria-label={`${fmtDate(d.date)}: ${d.available} available`}
                  className={cn('min-h-[74px] rounded-xl border p-1.5 text-left text-[11px] transition', d.is_past ? 'cursor-not-allowed bg-ink-50 opacity-50' : 'hover:border-brand-400',
                    inRange(d.date) ? 'border-brand-600 bg-brand-50 ring-2 ring-brand-500/20' : 'border-ink-200', d.is_closed && 'bg-danger-50')}>
                  <div className="flex justify-between"><span className="font-bold text-ink-800">{fmtDate(d.date, 'd')}</span>{d.price_override && <span className="text-coral-600">●</span>}</div>
                  <p className={cn('font-semibold', d.is_closed ? 'text-danger-600' : d.available === 0 ? 'text-coral-600' : 'text-success-700')}>{d.is_closed ? 'Closed' : `${d.available} left`}</p>
                  <p className="text-ink-500">{money(d.price)}</p>
                  <div className="mt-1 h-1 rounded-full bg-ink-100"><div className={cn('h-1 rounded-full', pct >= 1 ? 'bg-coral-500' : 'bg-brand-500')} style={{ width: `${Math.min(100, pct * 100)}%` }} /></div>
                </button>
              );
            })}
          </div>
        )}
        <p className="mt-3 text-xs text-ink-500">Click a start and end date to select a range. ● = price override.</p>
      </section>
      <aside className="card h-fit p-5">
        <h2 className="font-sans font-bold">Update selected nights</h2>
        <p className="mt-1 text-sm text-ink-500">{range.start ? `${fmtDate(range.start)}${range.end ? ` → ${fmtDate(range.end)}` : ''}` : 'Select dates on the calendar'}</p>
        <div className="mt-4 space-y-3">
          <Input label="Block rooms (out of sale)" type="number" min={0} value={form.blocked_count} onChange={(e) => setForm({ ...form, blocked_count: e.target.value })} hint="Leave empty to keep current" />
          <Input label="Total inventory for these nights" type="number" min={0} value={form.total_inventory} onChange={(e) => setForm({ ...form, total_inventory: e.target.value })} hint={`Default ${cal.data?.room.total_rooms ?? ''} rooms`} />
          <Input label="Price override (₹ / night)" value={form.price_override} onChange={(e) => setForm({ ...form, price_override: e.target.value })} hint='Type "clear" to remove an override' />
          <Select label="Availability" value={form.is_closed} onChange={(e) => setForm({ ...form, is_closed: e.target.value })}><option value="">No change</option><option value="1">Close (stop sell)</option><option value="0">Open</option></Select>
          <Input label="Internal note" value={form.note} onChange={(e) => setForm({ ...form, note: e.target.value })} />
          <Button className="w-full" disabled={!range.start} loading={update.isPending} onClick={() => update.mutate()}>Apply to {range.end ? 'range' : 'date'}</Button>
          <p className="text-xs text-ink-500">Inventory can never be reduced below rooms already booked — the system will reject it.</p>
        </div>
      </aside>
    </div>
  );
}
