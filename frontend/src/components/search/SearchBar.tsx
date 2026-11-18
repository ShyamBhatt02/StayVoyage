import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { CalendarDays, MapPin, Minus, Plus, Search, Users } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { addDaysIso, cn, isoDate } from '@/lib/format';

const today = () => isoDate(new Date());

export const searchSchema = z
  .object({
    destination: z.string().trim().max(100).optional().default(''),
    check_in: z.string().min(1, 'Choose check-in'),
    check_out: z.string().min(1, 'Choose check-out'),
    guests: z.coerce.number().int().min(1).max(30),
    rooms: z.coerce.number().int().min(1).max(10),
  })
  .refine((v) => v.check_in >= today(), { message: 'Check-in cannot be in the past', path: ['check_in'] })
  .refine((v) => v.check_out > v.check_in, { message: 'Check-out must be after check-in', path: ['check_out'] })
  .refine((v) => v.guests >= v.rooms, { message: 'At least one guest per room', path: ['guests'] });

export type SearchValues = z.infer<typeof searchSchema>;

export function defaultSearch(): SearchValues {
  return { destination: '', check_in: addDaysIso(7), check_out: addDaysIso(9), guests: 2, rooms: 1 };
}

export function SearchBar({ initial, variant = 'hero', onSearch }: { initial?: Partial<SearchValues>; variant?: 'hero' | 'compact'; onSearch?: (v: SearchValues) => void }) {
  const navigate = useNavigate();
  const { register, handleSubmit, watch, setValue, formState: { errors } } = useForm<SearchValues>({
    resolver: zodResolver(searchSchema) as never,
    // Ignore undefined/empty initial values so they don't wipe out the sensible defaults.
    defaultValues: { ...defaultSearch(), ...Object.fromEntries(Object.entries(initial ?? {}).filter(([, v]) => v !== undefined && v !== '' && !Number.isNaN(v))) },
  });
  const [guestsOpen, setGuestsOpen] = useState(false);
  const guestsRef = useRef<HTMLDivElement>(null);
  const checkIn = watch('check_in');
  const guests = watch('guests');
  const rooms = watch('rooms');

  useEffect(() => {
    const onClick = (e: MouseEvent) => { if (guestsRef.current && !guestsRef.current.contains(e.target as Node)) setGuestsOpen(false); };
    document.addEventListener('mousedown', onClick);
    return () => document.removeEventListener('mousedown', onClick);
  }, []);

  useEffect(() => {
    const out = watch('check_out');
    if (checkIn && out && out <= checkIn) {
      const d = new Date(checkIn + 'T00:00:00');
      d.setDate(d.getDate() + 1);
      setValue('check_out', isoDate(d));
    }
  }, [checkIn, setValue, watch]);

  const submit = (v: SearchValues) => {
    if (onSearch) return onSearch(v);
    const params = new URLSearchParams(Object.entries(v).filter(([, val]) => val !== '' && val !== undefined).map(([k, val]) => [k, String(val)]));
    navigate(`/hotels?${params.toString()}`);
  };

  const step = (field: 'guests' | 'rooms', delta: number) => {
    const current = Number(watch(field));
    const next = Math.min(field === 'rooms' ? 10 : 30, Math.max(1, current + delta));
    setValue(field, next, { shouldValidate: true });
  };

  const hero = variant === 'hero';
  const fieldCls = cn('flex flex-col justify-center rounded-2xl px-4 py-2.5 transition', hero ? 'bg-ink-50 hover:bg-ink-100 focus-within:bg-white focus-within:ring-2 focus-within:ring-brand-500' : 'bg-white border border-ink-200 focus-within:border-brand-500');
  const firstError = errors.check_in?.message || errors.check_out?.message || errors.guests?.message;

  return (
    <form onSubmit={handleSubmit(submit)} noValidate aria-label="Search hotels"
      className={cn('w-full', hero ? 'rounded-3xl bg-white p-3 shadow-lift' : 'rounded-2xl')}>
      <div className={cn('grid gap-2', hero ? 'md:grid-cols-[1.4fr_1fr_1fr_1fr_auto]' : 'sm:grid-cols-2 lg:grid-cols-[1.4fr_1fr_1fr_1fr_auto]')}>
        <label className={fieldCls}>
          <span className="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-ink-500"><MapPin className="h-3.5 w-3.5" /> Destination</span>
          <input {...register('destination')} placeholder="City, hotel or region" className="w-full bg-transparent text-sm font-semibold text-ink-900 placeholder:font-normal placeholder:text-ink-400 focus:outline-none" />
        </label>
        <label className={cn(fieldCls, errors.check_in && 'ring-2 ring-danger-600/40')}>
          <span className="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-ink-500"><CalendarDays className="h-3.5 w-3.5" /> Check-in</span>
          <input type="date" min={today()} {...register('check_in')} className="w-full bg-transparent text-sm font-semibold text-ink-900 focus:outline-none" />
        </label>
        <label className={cn(fieldCls, errors.check_out && 'ring-2 ring-danger-600/40')}>
          <span className="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-ink-500"><CalendarDays className="h-3.5 w-3.5" /> Check-out</span>
          <input type="date" min={checkIn || today()} {...register('check_out')} className="w-full bg-transparent text-sm font-semibold text-ink-900 focus:outline-none" />
        </label>
        <div className="relative" ref={guestsRef}>
          <button type="button" onClick={() => setGuestsOpen((o) => !o)} aria-expanded={guestsOpen} className={cn(fieldCls, 'h-full w-full text-left')}>
            <span className="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-ink-500"><Users className="h-3.5 w-3.5" /> Guests & rooms</span>
            <span className="text-sm font-semibold text-ink-900">{guests} guest{guests > 1 ? 's' : ''} · {rooms} room{rooms > 1 ? 's' : ''}</span>
          </button>
          {guestsOpen && (
            <div className="absolute right-0 z-30 mt-2 w-72 rounded-2xl border border-ink-200 bg-white p-4 shadow-lift animate-scale-in">
              {(['guests', 'rooms'] as const).map((f) => (
                <div key={f} className="flex items-center justify-between py-2">
                  <div>
                    <p className="text-sm font-semibold capitalize text-ink-900">{f}</p>
                    <p className="text-xs text-ink-500">{f === 'guests' ? 'Adults & children' : 'Rooms needed'}</p>
                  </div>
                  <div className="flex items-center gap-3">
                    <button type="button" onClick={() => step(f, -1)} className="flex h-8 w-8 items-center justify-center rounded-full border border-ink-200 hover:border-brand-500" aria-label={`Fewer ${f}`}><Minus className="h-4 w-4" /></button>
                    <span className="w-6 text-center font-semibold" aria-live="polite">{watch(f)}</span>
                    <button type="button" onClick={() => step(f, 1)} className="flex h-8 w-8 items-center justify-center rounded-full border border-ink-200 hover:border-brand-500" aria-label={`More ${f}`}><Plus className="h-4 w-4" /></button>
                  </div>
                </div>
              ))}
              <Button size="sm" className="mt-2 w-full" onClick={() => setGuestsOpen(false)}>Done</Button>
            </div>
          )}
        </div>
        <Button type="submit" variant="accent" size="lg" className="h-auto min-h-[56px] rounded-2xl px-7" icon={<Search className="h-5 w-5" />}>
          Search Hotels
        </Button>
      </div>
      {firstError && <p role="alert" className="mt-2 px-2 text-sm font-medium text-danger-600">{firstError}</p>}
    </form>
  );
}
