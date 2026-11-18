import { useId, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { ChevronDown, LifeBuoy, Search } from 'lucide-react';
import { get } from '@/lib/api';
import { cn } from '@/lib/format';
import { ButtonLink } from '@/components/ui/Button';
import { EmptyState, ErrorState, Skeleton } from '@/components/ui/misc';
import { usePageMeta } from '@/hooks/useDocumentTitle';

interface Faq { id: number; category: string; question: string; answer: string }

export function FaqList({ faqs }: { faqs: Faq[] }) {
  const [open, setOpen] = useState<number | null>(faqs[0]?.id ?? null);
  const base = useId();
  return (
    <div className="divide-y divide-ink-200 overflow-hidden rounded-2xl border border-ink-200 bg-white">
      {faqs.map((f) => {
        const isOpen = open === f.id;
        return (
          <div key={f.id}>
            <h3>
              <button onClick={() => setOpen(isOpen ? null : f.id)} aria-expanded={isOpen} aria-controls={`${base}-${f.id}`}
                className="flex w-full items-center justify-between gap-4 px-5 py-4 text-left font-sans text-[15px] font-semibold text-ink-900 hover:bg-ink-50">
                {f.question}
                <ChevronDown className={cn('h-5 w-5 shrink-0 text-ink-400 transition', isOpen && 'rotate-180 text-brand-600')} />
              </button>
            </h3>
            <div id={`${base}-${f.id}`} role="region" hidden={!isOpen} className="px-5 pb-5 text-sm leading-relaxed text-ink-600">{f.answer}</div>
          </div>
        );
      })}
    </div>
  );
}

export default function FaqPage() {
  usePageMeta('Help & FAQ', 'Answers about bookings, membership nights, payments, cancellations and refunds.');
  const { data, isLoading, isError, refetch } = useQuery({ queryKey: ['public', 'faqs'], queryFn: () => get<Faq[]>('/faqs') });
  const [q, setQ] = useState('');
  const [cat, setCat] = useState('All');
  const cats = useMemo(() => ['All', ...Array.from(new Set((data ?? []).map((f) => f.category)))], [data]);
  const filtered = (data ?? []).filter((f) => (cat === 'All' || f.category === cat) && (f.question + f.answer).toLowerCase().includes(q.toLowerCase()));

  return (
    <div className="container-page py-12">
      <div className="mx-auto max-w-3xl text-center">
        <p className="eyebrow">Help centre</p>
        <h1 className="section-title mt-2">How can we help?</h1>
        <div className="relative mx-auto mt-6 max-w-xl">
          <Search className="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-ink-400" />
          <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search questions, e.g. refund, membership nights" className="input h-12 pl-12" aria-label="Search FAQs" />
        </div>
      </div>
      <div className="mx-auto mt-10 max-w-3xl">
        <div className="mb-6 flex flex-wrap justify-center gap-2">
          {cats.map((c) => <button key={c} onClick={() => setCat(c)} className={cn('chip', cat === c && 'chip-active')}>{c}</button>)}
        </div>
        {isLoading ? <Skeleton className="h-96 rounded-2xl" /> : isError ? <ErrorState onRetry={() => refetch()} /> : filtered.length ? <FaqList key={cat + q} faqs={filtered} />
          : <EmptyState title="No answers match your search" description="Try different words, or contact our team — we reply within one business day." />}
        <div className="card mt-10 flex flex-col items-center gap-4 p-8 text-center sm:flex-row sm:text-left">
          <LifeBuoy className="h-10 w-10 text-brand-600" />
          <div className="flex-1">
            <p className="font-display text-lg font-semibold text-ink-900">Still need help?</p>
            <p className="text-sm text-ink-600">Our travel experts are available 9 am – 9 pm IST, every day.</p>
          </div>
          <ButtonLink to="/contact">Contact us</ButtonLink>
        </div>
      </div>
    </div>
  );
}
