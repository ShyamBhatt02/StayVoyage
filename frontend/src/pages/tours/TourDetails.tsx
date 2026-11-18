import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Check, ChevronRight, Clock, MapPin, Star, Users, Wand2, X } from 'lucide-react';
import { get, post } from '@/lib/api';
import { addDaysIso, money, titleCase } from '@/lib/format';
import { useAuth } from '@/providers/AuthProvider';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Field';
import { EmptyState, ErrorState, Img, Modal, Skeleton } from '@/components/ui/misc';
import { PackageCard } from '@/components/cards';
import { ITEM_META } from '@/pages/builder/builderShared';
import type { TourPackage, Trip } from '@/types';

export default function TourDetails() {
  const { slug = '' } = useParams();
  const navigate = useNavigate();
  const { user } = useAuth();
  const { data, isLoading, isError, error } = useQuery({ queryKey: ['public', 'tour', slug], queryFn: () => get<{ package: TourPackage; related: TourPackage[] }>(`/tours/${slug}`) });
  const pkg = data?.package;
  usePageMeta(pkg ? `${pkg.name} — ${pkg.duration_days} days` : 'Tour', pkg?.summary);
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState({ start_date: addDaysIso(30), adults: 2, children: 0 });

  const customize = useMutation({
    mutationFn: () => post<Trip>(`/trips/from-package/${slug}`, form),
    onSuccess: (r) => { toast.success(r.message); navigate(`/tour-builder/${r.data.id}`); },
    onError: (e) => toast.error((e as Error).message),
  });
  const start = () => (user ? setOpen(true) : navigate(`/login?redirect=/tours/${slug}`));

  if (isLoading) return <div className="container-page py-10"><Skeleton className="h-96 rounded-3xl" /></div>;
  if (isError || !pkg) return <div className="container-page py-16">{(error as { status?: number })?.status === 404 ? <EmptyState title="Tour not found" /> : <ErrorState />}</div>;

  return (
    <div className="pb-10">
      <section className="relative isolate overflow-hidden">
        <Img src={pkg.image_url} alt="" eager className="absolute inset-0 -z-10 h-full w-full" />
        <div className="absolute inset-0 -z-10 bg-gradient-to-t from-ink-950/90 via-ink-950/40 to-ink-950/20" />
        <div className="container-page pb-12 pt-32 text-white">
          <nav aria-label="Breadcrumb" className="mb-4 flex items-center gap-1.5 text-sm text-white/80"><Link to="/tours" className="hover:text-white">Tours</Link><ChevronRight className="h-3.5 w-3.5" /><span>{pkg.destination?.name}</span></nav>
          <h1 className="max-w-3xl font-display text-4xl font-semibold sm:text-5xl">{pkg.name}</h1>
          <p className="mt-3 max-w-2xl text-lg text-white/85">{pkg.summary}</p>
          <p className="mt-4 flex flex-wrap gap-4 text-sm font-semibold">
            <span className="flex items-center gap-1.5"><Clock className="h-4 w-4" />{pkg.duration_days} days / {pkg.duration_nights} nights</span>
            <span className="flex items-center gap-1.5"><MapPin className="h-4 w-4" />{pkg.cities}</span>
            <span className="flex items-center gap-1.5"><Star className="h-4 w-4 fill-sand-400 text-sand-400" />{pkg.rating_avg.toFixed(1)} ({pkg.review_count} reviews)</span>
            <span className="flex items-center gap-1.5"><Users className="h-4 w-4" />Up to {pkg.max_travelers} travellers</span>
          </p>
        </div>
      </section>

      <div className="container-page mt-10 grid gap-10 lg:grid-cols-[1fr_360px]">
        <div>
          <section>
            <h2 className="font-display text-2xl font-semibold">Highlights</h2>
            <ul className="mt-4 grid gap-3 sm:grid-cols-2">{pkg.highlights.map((h) => <li key={h} className="flex gap-2.5 text-ink-700"><Check className="mt-0.5 h-5 w-5 shrink-0 text-brand-600" />{h}</li>)}</ul>
          </section>
          {pkg.gallery.length > 0 && (
            <div className="mt-8 grid grid-cols-2 gap-3 sm:grid-cols-4">{pkg.gallery.map((g, i) => <Img key={i} src={g} alt={`${pkg.name} photo ${i + 1}`} className="h-32 w-full rounded-2xl" />)}</div>
          )}
          <section className="mt-10">
            <h2 className="font-display text-2xl font-semibold">Day-by-day itinerary</h2>
            <ol className="mt-6 space-y-6">
              {pkg.days?.map((d) => (
                <li key={d.day_number} className="relative border-l-2 border-brand-200 pl-6">
                  <span className="absolute -left-[13px] top-0 flex h-6 w-6 items-center justify-center rounded-full bg-brand-600 text-xs font-bold text-white">{d.day_number}</span>
                  <h3 className="font-sans text-lg font-semibold text-ink-900">{d.title}</h3>
                  <ul className="mt-3 space-y-2">
                    {d.items.map((i) => {
                      const meta = ITEM_META[i.item_type] ?? ITEM_META.other!;
                      return (
                        <li key={i.id} className="flex items-center gap-3 rounded-xl border border-ink-200 bg-white p-3">
                          <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${meta.tone}`}><meta.icon className="h-4 w-4" /></span>
                          <div className="min-w-0"><p className="truncate font-medium text-ink-900">{i.title}</p><p className="text-xs text-ink-500">{titleCase(i.item_type)}{i.start_time ? ` · ${i.start_time}` : ''}{i.nights ? ` · ${i.nights} nights` : ''}{i.hours ? ` · ${i.hours} hrs` : ''}</p></div>
                        </li>
                      );
                    })}
                  </ul>
                </li>
              ))}
            </ol>
          </section>
          <section className="mt-10 grid gap-6 sm:grid-cols-2">
            <div className="card p-5"><h3 className="font-sans font-bold">Included</h3><ul className="mt-3 space-y-2 text-sm">{pkg.inclusions.map((x) => <li key={x} className="flex gap-2"><Check className="h-4 w-4 shrink-0 text-success-600" />{x}</li>)}</ul></div>
            <div className="card p-5"><h3 className="font-sans font-bold">Not included</h3><ul className="mt-3 space-y-2 text-sm">{pkg.exclusions.map((x) => <li key={x} className="flex gap-2"><X className="h-4 w-4 shrink-0 text-danger-600" />{x}</li>)}</ul></div>
          </section>
        </div>
        <aside className="lg:sticky lg:top-24 lg:self-start">
          <div className="card p-6">
            <p className="text-sm text-ink-500">From, per person</p>
            <p className="text-3xl font-bold text-ink-900">{money(pkg.price_from)}</p>
            <p className="mt-1 text-xs text-ink-500">Based on {pkg.default_adults} adults sharing, incl. taxes & fees. Final price depends on dates and choices.</p>
            <Button variant="accent" size="lg" className="mt-5 w-full" onClick={start} icon={<Wand2 className="h-5 w-5" />}>Customize This Tour</Button>
            <p className="mt-3 text-center text-xs text-ink-500">Opens in the Tour Builder — change hotels, days or activities, then book.</p>
          </div>
        </aside>
      </div>

      {data.related.length > 0 && (
        <section className="container-page mt-16">
          <h2 className="font-display text-2xl font-semibold">You might also like</h2>
          <div className="mt-6 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">{data.related.map((r) => <PackageCard key={r.id} pkg={r} />)}</div>
        </section>
      )}

      <Modal open={open} onClose={() => setOpen(false)} title="Customize this tour" description="We'll copy the full itinerary into your Tour Builder."
        footer={<><Button variant="outline" onClick={() => setOpen(false)}>Cancel</Button><Button onClick={() => customize.mutate()} loading={customize.isPending}>Open in Tour Builder</Button></>}>
        <div className="grid gap-4 sm:grid-cols-3">
          <Input label="Start date" type="date" min={addDaysIso(1)} value={form.start_date} onChange={(e) => setForm({ ...form, start_date: e.target.value })} />
          <Input label="Adults" type="number" min={1} max={20} value={form.adults} onChange={(e) => setForm({ ...form, adults: Number(e.target.value) })} />
          <Input label="Children" type="number" min={0} max={10} value={form.children} onChange={(e) => setForm({ ...form, children: Number(e.target.value) })} />
        </div>
      </Modal>
    </div>
  );
}
