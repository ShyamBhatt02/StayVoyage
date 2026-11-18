import { useQuery } from '@tanstack/react-query';
import { toast } from 'sonner';
import { BadgePercent, Copy } from 'lucide-react';
import { get } from '@/lib/api';
import { fmtDate, money } from '@/lib/format';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { Badge, EmptyState, ErrorState, Img, Skeleton } from '@/components/ui/misc';
import type { Coupon } from '@/types';

const SCOPE: Record<string, string> = { all: 'Hotels & trips', hotels: 'Hotel stays', tours: 'Custom trips', memberships: 'Membership' };

export default function Offers() {
  usePageMeta('Offers & coupons', 'Current StayVoyage deals on hotels, tours and memberships.');
  const { data, isLoading, isError, refetch } = useQuery({ queryKey: ['public', 'offers'], queryFn: () => get<Coupon[]>('/offers') });
  const copy = (code: string) => { navigator.clipboard?.writeText(code); toast.success(`Code ${code} copied`); };
  return (
    <div className="container-page py-12">
      <p className="eyebrow">Offers</p>
      <h1 className="section-title mt-2">Deals worth packing for</h1>
      <p className="mt-3 max-w-2xl text-ink-600">Apply these codes at checkout. Discounts are verified and applied by StayVoyage at the moment you pay.</p>
      {isError ? <ErrorState className="mt-8" onRetry={() => refetch()} /> : isLoading ? <div className="mt-8 grid gap-6 md:grid-cols-2"><Skeleton className="h-60 rounded-3xl" /><Skeleton className="h-60 rounded-3xl" /></div>
        : !data?.length ? <EmptyState className="mt-8" icon={<BadgePercent className="h-7 w-7" />} title="No live offers right now" description="Check back soon — new deals drop every month." />
          : (
            <div className="mt-8 grid gap-6 md:grid-cols-2">
              {data.map((c) => (
                <article key={c.id} className="card grid overflow-hidden sm:grid-cols-[200px_1fr]">
                  <Img src={c.image_url ?? '/storage/seed/misc/hero-lake.jpg'} alt="" className="h-40 w-full sm:h-full" />
                  <div className="flex flex-col p-6">
                    <Badge tone="coral" className="w-fit">{SCOPE[c.applies_to] ?? c.applies_to}</Badge>
                    <h2 className="mt-3 font-display text-xl font-semibold text-ink-900">{c.title}</h2>
                    <p className="mt-1 text-sm text-ink-600">{c.description}</p>
                    <p className="mt-3 text-xs text-ink-500">
                      {c.discount_type === 'percentage' ? `${c.discount_value}% off` : `${money(c.discount_value)} off`}
                      {c.max_discount ? ` · up to ${money(c.max_discount)}` : ''}{c.min_purchase ? ` · min. spend ${money(c.min_purchase)}` : ''}{c.ends_at ? ` · valid till ${fmtDate(c.ends_at)}` : ''}
                    </p>
                    <button onClick={() => copy(c.code)} className="mt-4 flex w-fit items-center gap-2 rounded-xl border-2 border-dashed border-brand-400 bg-brand-50 px-4 py-2 font-mono font-bold text-brand-700 hover:bg-brand-100" aria-label={`Copy code ${c.code}`}>
                      {c.code} <Copy className="h-4 w-4" />
                    </button>
                  </div>
                </article>
              ))}
            </div>
          )}
    </div>
  );
}
