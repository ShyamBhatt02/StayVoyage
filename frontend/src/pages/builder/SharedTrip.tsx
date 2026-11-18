import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery } from '@tanstack/react-query';
import { toast } from 'sonner';
import { CalendarDays, Copy, Users } from 'lucide-react';
import { get, post } from '@/lib/api';
import { fmtDate } from '@/lib/format';
import { useAuth } from '@/providers/AuthProvider';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { Button } from '@/components/ui/Button';
import { EmptyState, Img, Spinner } from '@/components/ui/misc';
import { ItemCardView } from './TripEditor';
import { PriceSummary } from './builderShared';
import type { Trip } from '@/types';

export default function SharedTrip() {
  const { token = '' } = useParams();
  const { user } = useAuth();
  const navigate = useNavigate();
  const { data: trip, isLoading, isError } = useQuery({ queryKey: ['public', 'shared-trip', token], queryFn: () => get<Trip>(`/trips/shared/${token}`), retry: false });
  usePageMeta(trip ? `${trip.title} — shared trip` : 'Shared trip');
  const copy = useMutation({
    mutationFn: () => post<Trip>(`/trips/shared/${token}/copy`),
    onSuccess: (r) => { toast.success(r.message); navigate(`/tour-builder/${r.data.id}`); },
    onError: (e) => toast.error((e as Error).message),
  });

  if (isLoading) return <Spinner className="min-h-[60vh]" />;
  if (isError || !trip) return <div className="container-page py-16"><EmptyState title="This trip isn't available" description="The link may have been made private by its owner." /></div>;

  return (
    <div className="pb-12">
      <section className="relative isolate overflow-hidden bg-brand-950 text-white">
        {trip.cover_url && <Img src={trip.cover_url} alt="" className="absolute inset-0 -z-10 h-full w-full opacity-40" />}
        <div className="container-page py-16">
          <p className="eyebrow text-coral-300">Shared by {trip.owner_first_name}</p>
          <h1 className="mt-2 font-display text-4xl font-semibold">{trip.title}</h1>
          <p className="mt-3 flex flex-wrap gap-4 text-brand-100">
            <span className="flex items-center gap-1.5"><CalendarDays className="h-4 w-4" />{trip.duration_days} days{trip.start_date ? ` · from ${fmtDate(trip.start_date)}` : ''}</span>
            <span className="flex items-center gap-1.5"><Users className="h-4 w-4" />{trip.travelers} travellers</span>
            {trip.destination_summary && <span>{trip.destination_summary}</span>}
          </p>
          <Button variant="accent" className="mt-6" loading={copy.isPending} onClick={() => (user ? copy.mutate() : navigate(`/login?redirect=/trip/${token}`))} icon={<Copy className="h-4 w-4" />}>
            Copy this trip to my account
          </Button>
        </div>
      </section>
      <div className="container-page mt-8 grid gap-6 lg:grid-cols-[1fr_320px]">
        <div className="space-y-5">
          {trip.days.map((d) => (
            <section key={d.day_number} className="card overflow-hidden">
              <header className="border-b border-ink-100 bg-ink-50 px-4 py-3"><p className="font-sans font-bold">Day {d.day_number} · {d.title}</p>{d.date && <p className="text-xs text-ink-500">{fmtDate(d.date, 'EEEE, d MMM yyyy')}</p>}</header>
              <div className="space-y-2 p-3">{d.items.length ? d.items.map((i, idx) => <ItemCardView key={idx} item={i} />) : <p className="py-4 text-center text-sm text-ink-400">Free day</p>}</div>
            </section>
          ))}
        </div>
        <aside><div className="card sticky top-24 p-5"><h2 className="mb-4 font-sans font-bold">Estimated price</h2><PriceSummary trip={trip} /></div></aside>
      </div>
    </div>
  );
}
