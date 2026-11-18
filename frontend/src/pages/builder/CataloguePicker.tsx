import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Clock, Languages, MapPin, Plus, Search, Star, Users } from 'lucide-react';
import { get } from '@/lib/api';
import { cn, minutesLabel, money } from '@/lib/format';
import { Button } from '@/components/ui/Button';
import { EmptyState, ErrorState, Img, Modal, Skeleton } from '@/components/ui/misc';
import { Input } from '@/components/ui/Field';
import { ITEM_META } from './builderShared';
import type { CatalogueItem, Destination, Trip, TripDay } from '@/types';

const TABS = [
  { type: 'accommodation', label: 'Hotels' },
  { type: 'flight', label: 'Flights' },
  { type: 'train', label: 'Trains' },
  { type: 'bus', label: 'Buses' },
  { type: 'airport_transfer', label: 'Airport transfers' },
  { type: 'hotel_transfer', label: 'Hotel transfers' },
  { type: 'taxi', label: 'Taxis' },
  { type: 'private_car', label: 'Private cars' },
  { type: 'rental_car', label: 'Rental cars' },
  { type: 'activity', label: 'Activities' },
  { type: 'attraction', label: 'Attractions' },
  { type: 'guide', label: 'Guides' },
  { type: 'meal', label: 'Meals' },
  { type: 'other', label: 'Note / free time' },
] as const;

export function CataloguePicker({ day, trip, onClose, onPick, adding }: { day: TripDay; trip: Trip; onClose: () => void; onPick: (b: Record<string, unknown>) => void; adding: boolean }) {
  const [type, setType] = useState<(typeof TABS)[number]['type']>('accommodation');
  const [q, setQ] = useState('');
  const [destinationId, setDestinationId] = useState<number | ''>(day.destination_id ?? '');
  const [note, setNote] = useState({ title: '', start_time: '', notes: '' });
  const meta = ITEM_META[type]!;
  const destinations = useQuery({ queryKey: ['public', 'destinations'], queryFn: () => get<Destination[]>('/destinations') });

  const items = useQuery({
    queryKey: ['catalogue', meta.kind, meta.transportType, destinationId, q],
    queryFn: () => get<CatalogueItem[]>(`/catalogue/${meta.kind}`, {
      destination_id: destinationId || undefined, q: q || undefined, type: meta.transportType,
      category: type === 'attraction' ? 'attraction' : undefined,
    }),
    enabled: meta.kind !== 'none',
    staleTime: 60_000,
  });
  const list = useMemo(() => (items.data ?? []).filter((i) => (type === 'activity' ? i.category !== 'attraction' : true)), [items.data, type]);

  const pick = (item: CatalogueItem) => onPick({ item_type: type, itemable_id: item.id, ...(type === 'accommodation' ? { nights: 1 } : {}) });

  return (
    <Modal open onClose={onClose} size="xl" title={`Add to Day ${day.day_number}`} description={day.date ? new Date(day.date + 'T00:00:00').toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long' }) : trip.title}>
      <div className="-mx-6 -mt-5 mb-4 overflow-x-auto border-b border-ink-100 px-6 scrollbar-none">
        <div className="flex gap-1 py-3" role="tablist">
          {TABS.map((t) => {
            const Icon = ITEM_META[t.type]!.icon;
            return (
              <button key={t.type} role="tab" aria-selected={type === t.type} onClick={() => setType(t.type)}
                className={cn('flex shrink-0 items-center gap-1.5 rounded-full px-3.5 py-2 text-sm font-semibold transition', type === t.type ? 'bg-brand-600 text-white' : 'bg-ink-100 text-ink-700 hover:bg-ink-200')}>
                <Icon className="h-4 w-4" />{t.label}
              </button>
            );
          })}
        </div>
      </div>

      {type === 'other' ? (
        <div className="grid gap-4 sm:grid-cols-2">
          <Input wrapClassName="sm:col-span-2" label="What's planned?" placeholder="e.g. Free time at the beach, shopping in the souk" value={note.title} maxLength={120} onChange={(e) => setNote({ ...note, title: e.target.value })} />
          <Input label="Time (optional)" type="time" value={note.start_time} onChange={(e) => setNote({ ...note, start_time: e.target.value })} />
          <Input label="Notes (optional)" value={note.notes} maxLength={500} onChange={(e) => setNote({ ...note, notes: e.target.value })} />
          <div className="sm:col-span-2"><Button disabled={note.title.trim().length < 2} loading={adding} onClick={() => onPick({ item_type: 'other', title: note.title.trim(), start_time: note.start_time || undefined, notes: note.notes || undefined })} icon={<Plus className="h-4 w-4" />}>Add note</Button>
            <p className="mt-2 text-xs text-ink-500">Notes are free and help you plan downtime between booked items.</p></div>
        </div>
      ) : (
        <>
          <div className="mb-4 grid gap-3 sm:grid-cols-[1fr_220px]">
            <div className="relative"><Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-400" /><input className="input pl-9" placeholder={`Search ${TABS.find((t) => t.type === type)?.label.toLowerCase()}`} value={q} onChange={(e) => setQ(e.target.value)} aria-label="Search" /></div>
            <select className="input" value={destinationId} onChange={(e) => setDestinationId(e.target.value ? Number(e.target.value) : '')} aria-label="Destination">
              <option value="">All destinations</option>
              {destinations.data?.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
            </select>
          </div>
          {items.isError ? <ErrorState onRetry={() => items.refetch()} /> : items.isLoading ? <div className="grid gap-3 sm:grid-cols-2">{[1, 2, 3, 4].map((i) => <Skeleton key={i} className="h-28 rounded-xl" />)}</div>
            : !list.length ? <EmptyState title="Nothing found" description="Try another destination or search term." className="py-8" />
              : (
                <ul className="grid gap-3 sm:grid-cols-2">
                  {list.map((item) => (
                    <li key={item.id} className="flex gap-3 rounded-xl border border-ink-200 p-3 transition hover:border-brand-400">
                      <Img src={item.image_url} alt="" className="h-20 w-20 shrink-0 rounded-lg" />
                      <div className="min-w-0 flex-1">
                        <p className="line-clamp-2 text-sm font-semibold text-ink-900">{type === 'accommodation' ? `${item.hotel_name as string} — ${item.name}` : item.name}</p>
                        <p className="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-ink-500">
                          {item.kind === 'hotel_room' && <><span className="flex items-center gap-0.5"><MapPin className="h-3 w-3" />{item.city as string}</span><span className="flex items-center gap-0.5"><Star className="h-3 w-3 fill-sand-400 text-sand-400" />{String(item.rating_avg)}</span><span className="flex items-center gap-0.5"><Users className="h-3 w-3" />{String(item.max_guests)}</span></>}
                          {item.kind === 'activity' && <><span className="flex items-center gap-0.5"><Clock className="h-3 w-3" />{minutesLabel(item.duration_minutes as number)}</span><span>{item.location as string}</span></>}
                          {item.kind === 'guide' && <span className="flex items-center gap-0.5"><Languages className="h-3 w-3" />{(item.languages as string[]).join(', ')}</span>}
                          {item.kind === 'transportation' && <span>{item.provider as string}{item.from_location ? ` · ${item.from_location as string} → ${item.to_location as string}` : ''}</span>}
                          {item.kind === 'meal' && <span>{item.venue as string}</span>}
                        </p>
                        <div className="mt-2 flex items-center justify-between gap-2">
                          <p className="text-sm font-bold text-ink-900">{money(item.price)} <span className="text-xs font-normal text-ink-500">{item.price_label}</span></p>
                          <Button size="sm" variant="secondary" loading={adding} onClick={() => pick(item)} icon={<Plus className="h-3.5 w-3.5" />}>Add</Button>
                        </div>
                      </div>
                    </li>
                  ))}
                </ul>
              )}
          <p className="mt-4 text-xs text-ink-500">Final prices depend on your dates, travellers and quantities — they're recalculated by StayVoyage as soon as you add an item.</p>
        </>
      )}
    </Modal>
  );
}
