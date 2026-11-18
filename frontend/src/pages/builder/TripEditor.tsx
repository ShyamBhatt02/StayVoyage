import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import {
  DndContext, DragOverlay, KeyboardSensor, PointerSensor, closestCorners, useDroppable, useSensor, useSensors,
  type DragEndEvent, type DragOverEvent, type DragStartEvent,
} from '@dnd-kit/core';
import { SortableContext, arrayMove, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import {
  AlertTriangle, ArrowDown, ArrowLeft, ArrowUp, CalendarDays, ChevronDown, Copy, GripVertical, Lock, MoreVertical, Pencil, Plus, Share2, ShoppingBag, Trash2, Users,
} from 'lucide-react';
import { del, get, patch, post, put } from '@/lib/api';
import { addDaysIso, cn, fmtDate, minutesLabel, money, titleCase } from '@/lib/format';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { Button, ButtonLink } from '@/components/ui/Button';
import { ConfirmDialog, ErrorState, Img, Modal, Skeleton, StatusBadge } from '@/components/ui/misc';
import { Input, Select, Textarea } from '@/components/ui/Field';
import { ITEM_META, PriceSummary } from './builderShared';
import { CataloguePicker } from './CataloguePicker';
import type { Trip, TripDay, TripItem } from '@/types';

type Columns = Record<number, number[]>;

export default function TripEditor() {
  const { id = '' } = useParams();
  const [search] = useSearchParams();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const key = ['trip', id];
  const tripQ = useQuery({ queryKey: key, queryFn: () => get<Trip>(`/trips/${id}`) });
  const trip = tripQ.data;
  usePageMeta(trip ? `${trip.title} — Trip Builder` : 'Trip Builder');

  const [pickerDay, setPickerDay] = useState<TripDay | null>(null);
  const [editing, setEditing] = useState<TripItem | null>(null);
  const [shareOpen, setShareOpen] = useState(search.get('share') === '1');
  const [deletingDay, setDeletingDay] = useState<TripDay | null>(null);
  const [mobileSummary, setMobileSummary] = useState(false);
  const locked = trip?.status !== 'draft';

  const apply = (r: { data: Trip; message: string }, quiet = false) => {
    qc.setQueryData(key, r.data);
    qc.invalidateQueries({ queryKey: ['trips'] });
    if (!quiet) toast.success(r.message);
  };
  const onError = (e: Error) => { toast.error(e.message); tripQ.refetch(); };

  const updateTrip = useMutation({ mutationFn: (body: Record<string, unknown>) => patch<Trip>(`/trips/${id}`, body), onSuccess: (r) => apply(r), onError });
  const addDay = useMutation({ mutationFn: (after?: number) => post<Trip>(`/trips/${id}/days`, { after_day_number: after }), onSuccess: (r) => apply(r), onError });
  const updateDay = useMutation({ mutationFn: ({ dayId, body }: { dayId: number; body: Record<string, unknown> }) => patch<Trip>(`/trips/${id}/days/${dayId}`, body), onSuccess: (r) => apply(r, true), onError });
  const deleteDay = useMutation({ mutationFn: (dayId: number) => del<Trip>(`/trips/${id}/days/${dayId}`), onSuccess: (r) => { apply(r); setDeletingDay(null); }, onError });
  const reorderDays = useMutation({ mutationFn: (dayIds: number[]) => put<Trip>(`/trips/${id}/days/order`, { day_ids: dayIds }), onSuccess: (r) => apply(r, true), onError });
  const addItem = useMutation({ mutationFn: (body: Record<string, unknown>) => post<Trip>(`/trips/${id}/items`, body), onSuccess: (r) => apply(r), onError });
  const updateItem = useMutation({ mutationFn: ({ itemId, body }: { itemId: number; body: Record<string, unknown> }) => patch<Trip>(`/trips/${id}/items/${itemId}`, body), onSuccess: (r) => { apply(r); setEditing(null); }, onError });
  const dupItem = useMutation({ mutationFn: (itemId: number) => post<Trip>(`/trips/${id}/items/${itemId}/duplicate`), onSuccess: (r) => apply(r), onError });
  const delItem = useMutation({ mutationFn: (itemId: number) => del<Trip>(`/trips/${id}/items/${itemId}`), onSuccess: (r) => apply(r), onError });
  const reorder = useMutation({ mutationFn: (layout: { day_id: number; item_ids: number[] }[]) => put<Trip>(`/trips/${id}/items/order`, { layout }), onSuccess: (r) => apply(r, true), onError });
  const duplicate = useMutation({ mutationFn: () => post<Trip>(`/trips/${id}/duplicate`), onSuccess: (r) => { toast.success(r.message); navigate(`/tour-builder/${r.data.id}`); }, onError });

  // ---------------------------------------------------------------- drag & drop (desktop)
  const serverColumns = useMemo<Columns>(() => Object.fromEntries((trip?.days ?? []).map((d) => [d.id, d.items.map((i) => i.id!)])), [trip]);
  const [columns, setColumns] = useState<Columns>({});
  useEffect(() => setColumns(serverColumns), [serverColumns]);
  const [activeId, setActiveId] = useState<number | null>(null);
  const itemsById = useMemo(() => new Map((trip?.days ?? []).flatMap((d) => d.items.map((i) => [i.id!, i] as const))), [trip]);
  const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 6 } }), useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }));

  const findColumn = (itemOrDay: string | number): number | undefined => {
    const s = String(itemOrDay);
    if (s.startsWith('day-')) return Number(s.slice(4));
    const idn = Number(s);
    return Number(Object.keys(columns).find((d) => columns[Number(d)]!.includes(idn)));
  };
  const onDragStart = (e: DragStartEvent) => setActiveId(Number(e.active.id));
  const onDragOver = (e: DragOverEvent) => {
    if (!e.over) return;
    const from = findColumn(e.active.id);
    const to = findColumn(e.over.id);
    if (from === undefined || to === undefined || from === to) return;
    setColumns((c) => {
      const fromItems = c[from]!.filter((x) => x !== Number(e.active.id));
      const toItems = [...c[to]!];
      const overIndex = String(e.over!.id).startsWith('day-') ? toItems.length : toItems.indexOf(Number(e.over!.id));
      toItems.splice(overIndex < 0 ? toItems.length : overIndex, 0, Number(e.active.id));
      return { ...c, [from]: fromItems, [to]: toItems };
    });
  };
  const persist = (cols: Columns) => reorder.mutate(Object.entries(cols).map(([d, ids]) => ({ day_id: Number(d), item_ids: ids })));
  const onDragEnd = (e: DragEndEvent) => {
    setActiveId(null);
    if (!e.over) { setColumns(serverColumns); return; }
    const col = findColumn(e.active.id);
    const overCol = findColumn(e.over.id);
    let next = columns;
    if (col !== undefined && col === overCol && !String(e.over.id).startsWith('day-')) {
      const oldIndex = columns[col]!.indexOf(Number(e.active.id));
      const newIndex = columns[col]!.indexOf(Number(e.over.id));
      if (oldIndex !== newIndex) next = { ...columns, [col]: arrayMove(columns[col]!, oldIndex, newIndex) };
    }
    setColumns(next);
    if (JSON.stringify(next) !== JSON.stringify(serverColumns)) persist(next);
  };

  // ---------------------------------------------------------------- mobile move controls
  const moveItem = (item: TripItem, dayId: number, direction: -1 | 1) => {
    const list = [...(columns[dayId] ?? [])];
    const i = list.indexOf(item.id!);
    const j = i + direction;
    if (j < 0 || j >= list.length) return;
    [list[i], list[j]] = [list[j]!, list[i]!];
    const next = { ...columns, [dayId]: list };
    setColumns(next);
    persist(next);
  };
  const moveToDay = (item: TripItem, fromDay: number, toDay: number) => {
    const next = { ...columns, [fromDay]: columns[fromDay]!.filter((x) => x !== item.id), [toDay]: [...(columns[toDay] ?? []), item.id!] };
    setColumns(next);
    persist(next);
  };

  if (tripQ.isLoading) return <div className="container-page py-8"><Skeleton className="h-24 rounded-2xl" /><div className="mt-6 grid gap-6 lg:grid-cols-[1fr_340px]"><Skeleton className="h-96 rounded-2xl" /><Skeleton className="h-72 rounded-2xl" /></div></div>;
  if (tripQ.isError || !trip) return <div className="container-page py-16"><ErrorState message={(tripQ.error as Error)?.message} onRetry={() => tripQ.refetch()} /></div>;

  const unavailable = trip.price_breakdown?.unavailable_items ?? 0;
  const busy = reorder.isPending || addItem.isPending || delItem.isPending || dupItem.isPending;

  return (
    <div className="pb-32 lg:pb-10">
      <div className="border-b border-ink-200 bg-white">
        <div className="container-page py-5">
          <Link to="/account/trips" className="inline-flex items-center gap-1.5 text-sm font-semibold text-ink-500 hover:text-brand-700"><ArrowLeft className="h-4 w-4" /> My trips</Link>
          <div className="mt-3 flex flex-wrap items-start justify-between gap-4">
            <div className="min-w-0 flex-1">
              <div className="flex flex-wrap items-center gap-2">
                <StatusBadge status={trip.status === 'booked' ? 'confirmed' : 'pending'} label={trip.status === 'booked' ? 'Booked' : 'Draft — changes save automatically'} />
                {trip.package && <span className="text-xs text-ink-500">Based on <Link to={`/tours/${trip.package.slug}`} className="link">{trip.package.name}</Link></span>}
              </div>
              <InlineTitle value={trip.title} disabled={locked} onSave={(title) => updateTrip.mutate({ title })} />
              <div className="mt-3 flex flex-wrap items-center gap-3 text-sm">
                <label className="flex items-center gap-2"><CalendarDays className="h-4 w-4 text-brand-600" /><span className="sr-only">Start date</span>
                  <input type="date" disabled={locked} className="input h-9 w-auto py-1" min={addDaysIso(1)} value={trip.start_date ?? ''} onChange={(e) => updateTrip.mutate({ start_date: e.target.value || null })} /></label>
                <label className="flex items-center gap-2"><Users className="h-4 w-4 text-brand-600" /><span className="sr-only">Adults</span>
                  <select disabled={locked} className="input h-9 w-auto py-1" value={trip.adults} onChange={(e) => updateTrip.mutate({ adults: Number(e.target.value) })}>
                    {Array.from({ length: 12 }, (_, i) => <option key={i} value={i + 1}>{i + 1} adult{i ? 's' : ''}</option>)}
                  </select></label>
                <select disabled={locked} aria-label="Children" className="input h-9 w-auto py-1" value={trip.children} onChange={(e) => updateTrip.mutate({ children: Number(e.target.value) })}>
                  {Array.from({ length: 7 }, (_, i) => <option key={i} value={i}>{i} child{i === 1 ? '' : 'ren'}</option>)}
                </select>
                <span className="text-ink-500">{trip.duration_days} days{trip.start_date ? ` · ${fmtDate(trip.start_date, 'd MMM')} – ${fmtDate(trip.end_date, 'd MMM yyyy')}` : ''}</span>
              </div>
            </div>
            <div className="flex flex-wrap gap-2">
              <Button variant="outline" size="sm" onClick={() => setShareOpen(true)} icon={<Share2 className="h-4 w-4" />}>Share</Button>
              <Button variant="outline" size="sm" onClick={() => duplicate.mutate()} loading={duplicate.isPending} icon={<Copy className="h-4 w-4" />}>Duplicate</Button>
              {trip.booking && <ButtonLink to={`/account/bookings/${trip.booking.booking_number}`} size="sm" variant="secondary">View booking</ButtonLink>}
            </div>
          </div>
          {!trip.start_date && !locked && <p className="mt-3 flex items-center gap-2 rounded-xl bg-info-50 px-3 py-2 text-sm text-info-700"><AlertTriangle className="h-4 w-4" /> Add a start date to check live availability for hotels, activities and guides.</p>}
          {locked && <p className="mt-3 flex items-center gap-2 rounded-xl bg-ink-100 px-3 py-2 text-sm text-ink-700"><Lock className="h-4 w-4" /> This trip is booked and locked. Duplicate it to plan a new version.</p>}
        </div>
      </div>

      <div className="container-page mt-6 grid gap-6 lg:grid-cols-[1fr_340px]">
        <DndContext sensors={sensors} collisionDetection={closestCorners} onDragStart={onDragStart} onDragOver={onDragOver} onDragEnd={onDragEnd} onDragCancel={() => { setActiveId(null); setColumns(serverColumns); }}>
          <div className={cn('space-y-5', busy && 'opacity-90')}>
            {trip.days.map((day, di) => (
              <DayCard key={day.id} day={day} trip={trip} index={di} total={trip.days.length} locked={locked}
                itemIds={columns[day.id] ?? []} itemsById={itemsById}
                onAdd={() => setPickerDay(day)}
                onRename={(title) => updateDay.mutate({ dayId: day.id, body: { title } })}
                onDelete={() => setDeletingDay(day)}
                onMoveDay={(dir) => { const ids = trip.days.map((d) => d.id); const j = di + dir; [ids[di], ids[j]] = [ids[j]!, ids[di]!]; reorderDays.mutate(ids); }}
                onEdit={setEditing}
                onDuplicate={(i) => dupItem.mutate(i.id!)}
                onRemove={(i) => delItem.mutate(i.id!)}
                onMove={(i, dir) => moveItem(i, day.id, dir)}
                onMoveToDay={(i, to) => moveToDay(i, day.id, to)} />
            ))}
            {!locked && (
              <button onClick={() => addDay.mutate(undefined)} disabled={addDay.isPending}
                className="flex w-full items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-ink-300 py-5 font-semibold text-ink-600 transition hover:border-brand-500 hover:text-brand-700">
                <Plus className="h-5 w-5" /> Add day {trip.days.length + 1}
              </button>
            )}
          </div>
          <DragOverlay>{activeId && itemsById.get(activeId) ? <ItemCardView item={itemsById.get(activeId)!} dragging /> : null}</DragOverlay>
        </DndContext>

        <aside className="hidden lg:block">
          <div className="card sticky top-24 p-5">
            <h2 className="font-sans text-lg font-bold text-ink-900">Price summary</h2>
            <p className="mb-4 text-xs text-ink-500">Calculated by StayVoyage in real time{trip.priced_at ? ` · updated ${fmtDate(trip.priced_at, 'h:mm a')}` : ''}</p>
            <PriceSummary trip={trip} />
            {unavailable > 0 && <p className="mt-4 flex items-start gap-2 rounded-xl bg-warning-50 p-3 text-sm text-warning-700"><AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" /> {unavailable} item{unavailable > 1 ? 's need' : ' needs'} attention before booking.</p>}
            <BookButton trip={trip} unavailable={unavailable} />
          </div>
        </aside>
      </div>

      {/* Mobile sticky price bar */}
      <div className="fixed inset-x-0 bottom-0 z-30 border-t border-ink-200 bg-white/95 p-3 backdrop-blur lg:hidden">
        <button onClick={() => setMobileSummary((s) => !s)} className="flex w-full items-center justify-between" aria-expanded={mobileSummary}>
          <span className="text-left"><span className="block text-xs text-ink-500">Trip total{unavailable ? ` · ${unavailable} issue(s)` : ''}</span><span className="text-lg font-bold">{money(trip.total_amount)}</span></span>
          <ChevronDown className={cn('h-5 w-5 transition', mobileSummary && 'rotate-180')} />
        </button>
        {mobileSummary && <div className="mt-3 max-h-[50vh] overflow-y-auto"><PriceSummary trip={trip} /></div>}
        <BookButton trip={trip} unavailable={unavailable} />
      </div>

      {pickerDay && (
        <CataloguePicker day={pickerDay} trip={trip} onClose={() => setPickerDay(null)} adding={addItem.isPending}
          onPick={(body) => addItem.mutate({ ...body, trip_day_id: pickerDay.id }, { onSuccess: () => setPickerDay(null) })} />
      )}
      {editing && <EditItemModal item={editing} onClose={() => setEditing(null)} saving={updateItem.isPending} onSave={(body) => updateItem.mutate({ itemId: editing.id!, body })} />}
      <ShareModal open={shareOpen} onClose={() => setShareOpen(false)} trip={trip} onChange={(r) => apply(r)} />
      <ConfirmDialog open={!!deletingDay} onClose={() => setDeletingDay(null)} onConfirm={() => deletingDay && deleteDay.mutate(deletingDay.id)} loading={deleteDay.isPending}
        tone="danger" confirmLabel="Delete day" title={`Delete Day ${deletingDay?.day_number}?`} description="All items on this day will be removed and later days move up." />
    </div>
  );
}

function BookButton({ trip, unavailable }: { trip: Trip; unavailable: number }) {
  if (trip.status !== 'draft') return null;
  const reason = !trip.start_date ? 'Add a start date to book' : !trip.items_count ? 'Add items to book' : unavailable ? 'Fix unavailable items to book' : null;
  return (
    <div className="mt-4">
      <ButtonLink to={reason ? '#' : `/checkout/trip/${trip.id}`} variant="accent" size="lg" className={cn('w-full', reason && 'pointer-events-none opacity-50')} icon={<ShoppingBag className="h-5 w-5" />} aria-disabled={!!reason}>
        Book this trip
      </ButtonLink>
      {reason && <p className="mt-1.5 text-center text-xs text-ink-500">{reason}</p>}
    </div>
  );
}

function InlineTitle({ value, onSave, disabled }: { value: string; onSave: (v: string) => void; disabled?: boolean }) {
  const [edit, setEdit] = useState(false);
  const [v, setV] = useState(value);
  useEffect(() => setV(value), [value]);
  if (edit && !disabled) {
    return (
      <form onSubmit={(e) => { e.preventDefault(); setEdit(false); if (v.trim() && v !== value) onSave(v.trim()); }} className="mt-2">
        <input autoFocus className="input font-display text-2xl font-semibold" value={v} maxLength={120} onChange={(e) => setV(e.target.value)} onBlur={() => { setEdit(false); if (v.trim() && v !== value) onSave(v.trim()); }} aria-label="Trip name" />
      </form>
    );
  }
  return (
    <h1 className="mt-2 flex items-center gap-2 font-display text-2xl font-semibold text-ink-900 sm:text-3xl">
      {value}
      {!disabled && <button onClick={() => setEdit(true)} className="rounded-lg p-1 text-ink-400 hover:bg-ink-100 hover:text-ink-700" aria-label="Rename trip"><Pencil className="h-4 w-4" /></button>}
    </h1>
  );
}

function DayCard(props: {
  day: TripDay; trip: Trip; index: number; total: number; locked: boolean; itemIds: number[]; itemsById: Map<number, TripItem>;
  onAdd: () => void; onRename: (t: string) => void; onDelete: () => void; onMoveDay: (d: -1 | 1) => void;
  onEdit: (i: TripItem) => void; onDuplicate: (i: TripItem) => void; onRemove: (i: TripItem) => void; onMove: (i: TripItem, d: -1 | 1) => void; onMoveToDay: (i: TripItem, to: number) => void;
}) {
  const { day, trip, index, total, locked, itemIds, itemsById } = props;
  const { setNodeRef, isOver } = useDroppable({ id: `day-${day.id}`, disabled: locked });
  const [title, setTitle] = useState(day.title ?? '');
  useEffect(() => setTitle(day.title ?? ''), [day.title]);
  const dayTotal = itemIds.reduce((s, i) => s + (itemsById.get(i)?.total_price ?? 0), 0);

  return (
    <section className="card overflow-hidden" aria-labelledby={`day-${day.id}-h`}>
      <header className="flex flex-wrap items-center gap-3 border-b border-ink-100 bg-ink-50/70 px-4 py-3">
        <span className="flex h-10 w-10 shrink-0 flex-col items-center justify-center rounded-xl bg-brand-600 text-white">
          <span className="text-[9px] font-bold uppercase leading-none">Day</span><span className="text-sm font-bold leading-none">{day.day_number}</span>
        </span>
        <div className="min-w-0 flex-1">
          {locked ? <h2 id={`day-${day.id}-h`} className="font-sans font-bold text-ink-900">{day.title}</h2> : (
            <input id={`day-${day.id}-h`} value={title} onChange={(e) => setTitle(e.target.value)} onBlur={() => title !== day.title && title.trim() && props.onRename(title.trim())} maxLength={120}
              className="w-full rounded-lg bg-transparent px-1 py-0.5 font-sans font-bold text-ink-900 hover:bg-white focus:bg-white focus:outline-none focus:ring-2 focus:ring-brand-500" aria-label={`Day ${day.day_number} title`} />
          )}
          <p className="px-1 text-xs text-ink-500">{day.date ? fmtDate(day.date, 'EEEE, d MMM yyyy') : 'Set a start date to see dates'} · {money(dayTotal)}</p>
        </div>
        {!locked && (
          <div className="flex items-center gap-1">
            <button onClick={() => props.onMoveDay(-1)} disabled={index === 0} className="rounded-lg p-1.5 text-ink-500 hover:bg-white disabled:opacity-30" aria-label="Move day earlier"><ArrowUp className="h-4 w-4" /></button>
            <button onClick={() => props.onMoveDay(1)} disabled={index === total - 1} className="rounded-lg p-1.5 text-ink-500 hover:bg-white disabled:opacity-30" aria-label="Move day later"><ArrowDown className="h-4 w-4" /></button>
            <button onClick={props.onDelete} disabled={total <= 1} className="rounded-lg p-1.5 text-ink-500 hover:bg-danger-50 hover:text-danger-600 disabled:opacity-30" aria-label={`Delete day ${day.day_number}`}><Trash2 className="h-4 w-4" /></button>
          </div>
        )}
      </header>
      <div ref={setNodeRef} className={cn('space-y-2 p-3 transition', isOver && 'bg-brand-50/60')}>
        <SortableContext id={`day-${day.id}`} items={itemIds} strategy={verticalListSortingStrategy}>
          {itemIds.map((iid, idx) => {
            const item = itemsById.get(iid);
            if (!item) return null;
            return (
              <SortableItem key={iid} item={item} locked={locked} first={idx === 0} last={idx === itemIds.length - 1} days={trip.days} currentDay={day.id}
                onEdit={() => props.onEdit(item)} onDuplicate={() => props.onDuplicate(item)} onRemove={() => props.onRemove(item)}
                onMove={(d) => props.onMove(item, d)} onMoveToDay={(to) => props.onMoveToDay(item, to)} />
            );
          })}
        </SortableContext>
        {!itemIds.length && <p className="rounded-xl border border-dashed border-ink-200 py-6 text-center text-sm text-ink-400">{locked ? 'Nothing planned.' : 'Free day — add something, or drag an item here.'}</p>}
        {!locked && (
          <button onClick={props.onAdd} className="flex w-full items-center justify-center gap-1.5 rounded-xl py-2.5 text-sm font-semibold text-brand-700 transition hover:bg-brand-50">
            <Plus className="h-4 w-4" /> Add to Day {day.day_number}
          </button>
        )}
      </div>
    </section>
  );
}

function SortableItem(props: {
  item: TripItem; locked: boolean; first: boolean; last: boolean; days: TripDay[]; currentDay: number;
  onEdit: () => void; onDuplicate: () => void; onRemove: () => void; onMove: (d: -1 | 1) => void; onMoveToDay: (to: number) => void;
}) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: props.item.id!, disabled: props.locked });
  return (
    <div ref={setNodeRef} style={{ transform: CSS.Transform.toString(transform), transition }} className={cn(isDragging && 'opacity-40')}>
      <ItemCardView item={props.item} handle={!props.locked ? <button {...attributes} {...listeners} className="hidden cursor-grab touch-none rounded-lg p-1 text-ink-400 hover:bg-ink-100 active:cursor-grabbing md:block" aria-label={`Drag ${props.item.title}`}><GripVertical className="h-5 w-5" /></button> : null}
        actions={!props.locked && <ItemMenu {...props} />} />
    </div>
  );
}

function ItemMenu({ item, first, last, days, currentDay, onEdit, onDuplicate, onRemove, onMove, onMoveToDay }: Parameters<typeof SortableItem>[0]) {
  const [open, setOpen] = useState(false);
  return (
    <div className="flex items-center gap-0.5">
      {/* Explicit move controls (mobile-first; also keyboard accessible) */}
      <button onClick={() => onMove(-1)} disabled={first} className="rounded-lg p-1.5 text-ink-500 hover:bg-ink-100 disabled:opacity-30 md:hidden" aria-label={`Move ${item.title} up`}><ArrowUp className="h-4 w-4" /></button>
      <button onClick={() => onMove(1)} disabled={last} className="rounded-lg p-1.5 text-ink-500 hover:bg-ink-100 disabled:opacity-30 md:hidden" aria-label={`Move ${item.title} down`}><ArrowDown className="h-4 w-4" /></button>
      <div className="relative">
        <button onClick={() => setOpen((o) => !o)} className="rounded-lg p-1.5 text-ink-500 hover:bg-ink-100" aria-label={`Actions for ${item.title}`} aria-expanded={open}><MoreVertical className="h-4 w-4" /></button>
        {open && (
          <>
            <div className="fixed inset-0 z-10" onClick={() => setOpen(false)} aria-hidden />
            <div role="menu" className="absolute right-0 z-20 mt-1 w-52 rounded-xl border border-ink-200 bg-white p-1 shadow-lift">
              <button role="menuitem" onClick={() => { setOpen(false); onEdit(); }} className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm hover:bg-ink-50"><Pencil className="h-4 w-4" /> Edit details</button>
              <button role="menuitem" onClick={() => { setOpen(false); onDuplicate(); }} className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm hover:bg-ink-50"><Copy className="h-4 w-4" /> Duplicate</button>
              <label className="block px-3 py-2 text-xs font-semibold text-ink-500">Move to day
                <select className="input mt-1 h-9 py-1 text-sm" value={currentDay} onChange={(e) => { setOpen(false); onMoveToDay(Number(e.target.value)); }}>
                  {days.map((d) => <option key={d.id} value={d.id}>Day {d.day_number}{d.title ? ` — ${d.title}` : ''}</option>)}
                </select>
              </label>
              <button role="menuitem" onClick={() => { setOpen(false); onRemove(); }} className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm text-danger-600 hover:bg-danger-50"><Trash2 className="h-4 w-4" /> Remove</button>
            </div>
          </>
        )}
      </div>
    </div>
  );
}

export function ItemCardView({ item, handle, actions, dragging }: { item: TripItem; handle?: React.ReactNode; actions?: React.ReactNode; dragging?: boolean }) {
  const meta = ITEM_META[item.item_type] ?? ITEM_META.other!;
  const Icon = meta.icon;
  const details = [
    item.start_time,
    item.nights ? `${item.nights} night${item.nights > 1 ? 's' : ''}` : null,
    item.item_type === 'accommodation' ? `${item.quantity} room${item.quantity > 1 ? 's' : ''}` : null,
    item.hours ? `${item.hours} hrs` : null,
    item.duration_minutes && item.item_type !== 'accommodation' ? minutesLabel(item.duration_minutes) : null,
    item.from_location && item.to_location ? `${item.from_location} → ${item.to_location}` : null,
    item.meal_plan ? titleCase(item.meal_plan) : null,
    ['activity', 'attraction', 'meal'].includes(item.item_type) || item.pricing_unit === 'per_person' ? `× ${item.quantity}` : null,
    item.pricing_unit === 'per_vehicle' ? `${item.quantity} vehicle${item.quantity > 1 ? 's' : ''}` : null,
    item.pricing_unit === 'per_day' ? `${item.quantity} day${item.quantity > 1 ? 's' : ''}` : null,
  ].filter(Boolean);
  return (
    <div className={cn('flex items-start gap-2 rounded-xl border bg-white p-2.5 sm:gap-3 sm:p-3', item.is_available ? 'border-ink-200' : 'border-warning-600/40 bg-warning-50/40', dragging && 'shadow-lift ring-2 ring-brand-500')}>
      {handle}
      {item.image_url ? <Img src={item.image_url} alt="" className="hidden h-14 w-16 shrink-0 rounded-lg sm:block" /> : null}
      <span className={cn('flex h-9 w-9 shrink-0 items-center justify-center rounded-lg sm:hidden', meta.tone)}><Icon className="h-4 w-4" /></span>
      <div className="min-w-0 flex-1">
        <p className="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-ink-500"><Icon className="hidden h-3.5 w-3.5 sm:block" />{meta.label}{item.provider && item.item_type !== 'accommodation' ? ` · ${item.provider}` : ''}</p>
        <p className="truncate font-semibold text-ink-900">{item.hotel_slug ? <Link to={`/hotels/${item.hotel_slug}`} className="hover:text-brand-700">{item.title}</Link> : item.title}</p>
        {details.length > 0 && <p className="truncate text-xs text-ink-500">{details.join(' · ')}</p>}
        {item.notes && <p className="mt-0.5 line-clamp-1 text-xs italic text-ink-500">{item.notes}</p>}
        {!item.is_available && item.availability_note && <p className="mt-1 flex items-center gap-1 text-xs font-semibold text-warning-700"><AlertTriangle className="h-3.5 w-3.5" />{item.availability_note}</p>}
        {item.is_available && item.availability_note && <p className="mt-1 text-xs text-ink-500">{item.availability_note}</p>}
      </div>
      <div className="flex flex-col items-end gap-1">
        <p className="whitespace-nowrap text-sm font-bold text-ink-900">{item.total_price > 0 ? money(item.total_price) : 'Free'}</p>
        {actions}
      </div>
    </div>
  );
}

function EditItemModal({ item, onClose, onSave, saving }: { item: TripItem; onClose: () => void; onSave: (b: Record<string, unknown>) => void; saving: boolean }) {
  const [v, setV] = useState({
    start_time: item.start_time ?? '', quantity: item.quantity, nights: item.nights ?? 1, hours: item.hours ?? 4, meal_plan: item.meal_plan ?? '',
    notes: item.notes ?? '', title: item.title, from_location: item.from_location ?? '', to_location: item.to_location ?? '',
  });
  const t = item.item_type;
  const body: Record<string, unknown> = { start_time: v.start_time || null, notes: v.notes || null };
  if (t === 'accommodation') Object.assign(body, { nights: Number(v.nights), quantity: Number(v.quantity), meal_plan: v.meal_plan || null });
  else if (t === 'guide') Object.assign(body, { hours: Number(v.hours) });
  else if (t !== 'other') Object.assign(body, { quantity: Number(v.quantity) });
  if (t === 'other') Object.assign(body, { title: v.title });
  if (['taxi', 'private_car', 'rental_car'].includes(t)) Object.assign(body, { from_location: v.from_location || null, to_location: v.to_location || null });

  const qtyLabel = t === 'accommodation' ? 'Rooms' : item.pricing_unit === 'per_vehicle' ? 'Vehicles' : item.pricing_unit === 'per_day' ? 'Days' : 'Travellers / tickets';
  return (
    <Modal open onClose={onClose} title={`Edit ${ITEM_META[t]?.label.toLowerCase() ?? 'item'}`} description={item.title}
      footer={<><Button variant="outline" onClick={onClose}>Cancel</Button><Button onClick={() => onSave(body)} loading={saving}>Save & reprice</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        {t === 'other' && <Input wrapClassName="sm:col-span-2" label="Title" value={v.title} maxLength={120} onChange={(e) => setV({ ...v, title: e.target.value })} />}
        <Input label="Start time" type="time" value={v.start_time} onChange={(e) => setV({ ...v, start_time: e.target.value })} />
        {t === 'accommodation' && <Input label="Nights" type="number" min={1} max={30} value={v.nights} onChange={(e) => setV({ ...v, nights: Number(e.target.value) })} />}
        {t === 'guide' && <Input label="Hours" type="number" min={1} max={12} value={v.hours} onChange={(e) => setV({ ...v, hours: Number(e.target.value) })} hint="8+ hours uses the full-day rate" />}
        {t !== 'other' && t !== 'guide' && <Input label={qtyLabel} type="number" min={1} max={50} value={v.quantity} onChange={(e) => setV({ ...v, quantity: Number(e.target.value) })} />}
        {t === 'accommodation' && (
          <Select label="Meal plan" value={v.meal_plan} onChange={(e) => setV({ ...v, meal_plan: e.target.value })}>
            <option value="">As per room rate</option><option value="room_only">Room only</option><option value="breakfast">Breakfast</option><option value="half_board">Half board (breakfast + dinner)</option><option value="full_board">Full board (all meals)</option>
          </Select>
        )}
        {['taxi', 'private_car', 'rental_car'].includes(t) && <>
          <Input label="Pickup" value={v.from_location} onChange={(e) => setV({ ...v, from_location: e.target.value })} />
          <Input label="Drop-off" value={v.to_location} onChange={(e) => setV({ ...v, to_location: e.target.value })} />
        </>}
        <Textarea wrapClassName="sm:col-span-2" label="Notes" rows={3} maxLength={500} value={v.notes} onChange={(e) => setV({ ...v, notes: e.target.value })} placeholder="Anything to remember for this item" />
      </div>
      <p className="mt-4 text-xs text-ink-500">Prices are recalculated by StayVoyage when you save.</p>
    </Modal>
  );
}

function ShareModal({ open, onClose, trip, onChange }: { open: boolean; onClose: () => void; trip: Trip; onChange: (r: { data: Trip; message: string }) => void }) {
  const toggle = useMutation({ mutationFn: (visibility: 'public' | 'private') => post<Trip>(`/trips/${trip.id}/share`, { visibility }), onSuccess: onChange, onError: (e) => toast.error((e as Error).message) });
  const url = trip.share_url ?? '';
  const isPublic = trip.visibility === 'public';
  return (
    <Modal open={open} onClose={onClose} title="Share your trip" description="Anyone with the link can view the itinerary. Your name (first name only) is shown — never your contact details.">
      <div className="space-y-5">
        <div className="flex items-center justify-between rounded-xl bg-ink-50 p-4">
          <div><p className="font-semibold text-ink-900">{isPublic ? 'Public link is on' : 'Trip is private'}</p><p className="text-sm text-ink-500">{isPublic ? 'Viewers can see days, items and prices.' : 'Only you can see this trip.'}</p></div>
          <Button variant={isPublic ? 'outline' : 'primary'} size="sm" loading={toggle.isPending} onClick={() => toggle.mutate(isPublic ? 'private' : 'public')}>{isPublic ? 'Make private' : 'Create public link'}</Button>
        </div>
        {isPublic && url && (
          <>
            <div className="flex gap-2">
              <input readOnly value={url} className="input font-mono text-xs" aria-label="Share link" onFocus={(e) => e.target.select()} />
              <Button variant="secondary" onClick={() => { navigator.clipboard?.writeText(url); toast.success('Link copied'); }} icon={<Copy className="h-4 w-4" />}>Copy</Button>
            </div>
            <a href={`https://wa.me/?text=${encodeURIComponent(`Take a look at my trip “${trip.title}” on StayVoyage: ${url}`)}`} target="_blank" rel="noopener noreferrer"
              className="flex h-11 items-center justify-center gap-2 rounded-xl bg-success-600 font-semibold text-white hover:bg-success-700">Share on WhatsApp</a>
          </>
        )}
      </div>
    </Modal>
  );
}
