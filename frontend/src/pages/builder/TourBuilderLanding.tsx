import { Link, useNavigate } from 'react-router-dom';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { toast } from 'sonner';
import { useState } from 'react';
import { CalendarDays, GripVertical, MousePointerClick, Plus, Route, Share2, ShoppingBag, Sparkles } from 'lucide-react';
import { get, getPage, post } from '@/lib/api';
import { addDaysIso, fmtDate, money } from '@/lib/format';
import { useAuth } from '@/providers/AuthProvider';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Input, Select } from '@/components/ui/Field';
import { Img, Modal, Skeleton, StatusBadge } from '@/components/ui/misc';
import { PackageCard } from '@/components/cards';
import type { Destination, TourPackage, Trip } from '@/types';

const schema = z.object({
  title: z.string().trim().min(2, 'Give your trip a name').max(120),
  destination_id: z.coerce.number().optional(),
  start_date: z.string().optional(),
  days: z.coerce.number().int().min(1).max(30),
  adults: z.coerce.number().int().min(1).max(20),
  children: z.coerce.number().int().min(0).max(10),
});

export function NewTripModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const navigate = useNavigate();
  const destinations = useQuery({ queryKey: ['public', 'destinations'], queryFn: () => get<Destination[]>('/destinations'), enabled: open });
  const { register, handleSubmit, watch, formState: { errors } } = useForm<z.infer<typeof schema>>({
    resolver: zodResolver(schema) as never,
    defaultValues: { title: '', days: 4, adults: 2, children: 0, start_date: addDaysIso(30) },
  });
  const create = useMutation({
    mutationFn: (v: z.infer<typeof schema>) => {
      const d = destinations.data?.find((x) => x.id === Number(v.destination_id));
      return post<Trip>('/trips', { ...v, destination_id: v.destination_id || undefined, destination_summary: d ? d.name : undefined, start_date: v.start_date || undefined });
    },
    onSuccess: (r) => { toast.success(r.message); onClose(); navigate(`/tour-builder/${r.data.id}`); },
    onError: (e) => toast.error((e as Error).message),
  });
  const startDate = watch('start_date');
  return (
    <Modal open={open} onClose={onClose} title="Plan a new trip" description="You can change everything later — dates, travellers and days."
      footer={<><Button variant="outline" onClick={onClose}>Cancel</Button><Button form="new-trip" type="submit" loading={create.isPending}>Create trip</Button></>}>
      <form id="new-trip" onSubmit={handleSubmit((v) => create.mutate(v))} className="grid gap-4 sm:grid-cols-2" noValidate>
        <Input wrapClassName="sm:col-span-2" label="Trip name" placeholder="e.g. Honeymoon in Bali" {...register('title')} error={errors.title?.message} />
        <Select label="Main destination" {...register('destination_id')}>
          <option value="">Multiple / decide later</option>
          {destinations.data?.map((d) => <option key={d.id} value={d.id}>{d.name}, {d.country}</option>)}
        </Select>
        <Input label="Start date" type="date" min={addDaysIso(1)} {...register('start_date')} hint={startDate ? `Starts ${fmtDate(startDate, 'EEEE')}` : 'Optional — needed to check availability'} />
        <Input label="Number of days" type="number" min={1} max={30} {...register('days')} error={errors.days?.message} />
        <div className="grid grid-cols-2 gap-3">
          <Input label="Adults" type="number" min={1} max={20} {...register('adults')} error={errors.adults?.message} />
          <Input label="Children" type="number" min={0} max={10} {...register('children')} error={errors.children?.message} />
        </div>
      </form>
    </Modal>
  );
}

export default function TourBuilderLanding() {
  usePageMeta('Build your own trip', 'Create a day-by-day itinerary with hotels, transfers, activities and guides — priced live.');
  const { user } = useAuth();
  const navigate = useNavigate();
  const [open, setOpen] = useState(false);
  const trips = useQuery({ queryKey: ['trips', 1], queryFn: () => getPage<Trip>('/trips'), enabled: !!user });
  const packages = useQuery({ queryKey: ['public', 'tours', 'builder'], queryFn: () => getPage<TourPackage>('/tours', { sort: 'popular' }) });

  const start = () => (user ? setOpen(true) : navigate('/login?redirect=/tour-builder'));

  return (
    <>
      <section className="relative isolate overflow-hidden bg-ink-950 text-white">
        <img src="/storage/seed/misc/road-trip.jpg" alt="" className="absolute inset-0 -z-10 h-full w-full object-cover opacity-40" />
        <div className="absolute inset-0 -z-10 bg-gradient-to-r from-ink-950 via-ink-950/70 to-transparent" />
        <div className="container-page py-20 sm:py-24">
          <p className="eyebrow text-coral-300">Custom Tour Builder</p>
          <h1 className="mt-3 max-w-2xl font-display text-4xl font-semibold sm:text-5xl">Design your trip, one day at a time.</h1>
          <p className="mt-4 max-w-xl text-lg text-white/85">Pick hotels from our inventory, add flights, trains and private transfers, book guided experiences and dinners. We price everything live and hold it all in one booking.</p>
          <div className="mt-8 flex flex-wrap gap-3">
            <Button variant="accent" size="lg" onClick={start} icon={<Plus className="h-5 w-5" />}>Start a new trip</Button>
            <ButtonLink to="/tours" variant="white" size="lg">Customise a tour</ButtonLink>
          </div>
        </div>
      </section>

      {user && (
        <section className="container-page py-12">
          <div className="mb-5 flex items-center justify-between"><h2 className="font-display text-2xl font-semibold text-ink-900">Your trips</h2><Link to="/account/trips" className="link text-sm">Manage all</Link></div>
          {trips.isLoading ? <Skeleton className="h-40 rounded-2xl" /> : trips.data?.items.length ? (
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {trips.data.items.slice(0, 6).map((t) => (
                <Link key={t.id} to={`/tour-builder/${t.id}`} className="card flex gap-4 p-3 transition hover:shadow-lift">
                  <Img src={t.cover_url} alt="" className="h-20 w-24 shrink-0 rounded-xl" />
                  <div className="min-w-0">
                    <StatusBadge status={t.status === 'booked' ? 'confirmed' : 'pending'} label={t.status === 'booked' ? 'Booked' : 'Draft'} />
                    <p className="mt-1 truncate font-semibold text-ink-900">{t.title}</p>
                    <p className="text-xs text-ink-500">{t.duration_days} days · {t.start_date ? fmtDate(t.start_date, 'd MMM yyyy') : 'No date'}</p>
                    <p className="text-sm font-bold text-ink-900">{money(t.total_amount)}</p>
                  </div>
                </Link>
              ))}
            </div>
          ) : <p className="text-ink-500">No trips yet — start one above.</p>}
        </section>
      )}

      <section className="container-page py-12">
        <h2 className="section-title text-center">How the Tour Builder works</h2>
        <div className="mt-10 grid gap-6 md:grid-cols-4">
          {[
            [CalendarDays, 'Set the basics', 'Name your trip, choose dates, days and travellers.'],
            [MousePointerClick, 'Add anything', 'Hotels, flights, trains, buses, transfers, rental cars, activities, guides and meals.'],
            [GripVertical, 'Arrange your days', 'Drag & drop on desktop or use move buttons on mobile. Prices update instantly.'],
            [ShoppingBag, 'Share & book', 'Share a link on WhatsApp, then book the whole itinerary in one checkout.'],
          ].map(([Icon, t, b]) => {
            const I = Icon as typeof Route;
            return <div key={t as string} className="card p-6"><span className="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-600 text-white"><I className="h-5 w-5" /></span><h3 className="mt-4 font-sans font-semibold">{t as string}</h3><p className="mt-1 text-sm text-ink-600">{b as string}</p></div>;
          })}
        </div>
      </section>

      <section className="container-page py-12">
        <div className="mb-6 flex flex-wrap items-end justify-between gap-3">
          <div><p className="eyebrow"><Sparkles className="mr-1 inline h-3.5 w-3.5" />Start from a template</p><h2 className="section-title mt-2">Customise a ready-made tour</h2></div>
          <ButtonLink to="/tours" variant="outline" icon={<Share2 className="h-4 w-4" />}>See all tours</ButtonLink>
        </div>
        <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">{packages.data?.items.slice(0, 3).map((p) => <PackageCard key={p.id} pkg={p} />)}</div>
      </section>

      <NewTripModal open={open} onClose={() => setOpen(false)} />
    </>
  );
}
