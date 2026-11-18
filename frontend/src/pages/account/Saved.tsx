import { useQuery } from '@tanstack/react-query';
import { Heart } from 'lucide-react';
import { get } from '@/lib/api';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { ButtonLink } from '@/components/ui/Button';
import { CardSkeleton, EmptyState, ErrorState } from '@/components/ui/misc';
import { HotelCard } from '@/components/cards';
import { PageHeader } from '@/components/account';
import type { HotelCard as HotelCardT } from '@/types';

export default function Saved() {
  usePageMeta('Wishlist');
  const { data, isLoading, isError, refetch } = useQuery({ queryKey: ['saved-hotels'], queryFn: () => get<HotelCardT[]>('/saved-hotels') });
  return (
    <div>
      <PageHeader title="Wishlist" description="Hotels you've saved for later." />
      {isError ? <ErrorState onRetry={() => refetch()} /> : isLoading ? <div className="grid gap-6 sm:grid-cols-2 xl:grid-cols-3"><CardSkeleton count={3} /></div>
        : !data?.length ? <EmptyState icon={<Heart className="h-7 w-7" />} title="Your wishlist is empty" description="Tap “Save” on any hotel to keep it here." action={<ButtonLink to="/hotels">Explore hotels</ButtonLink>} />
          : <div className="grid gap-6 sm:grid-cols-2 xl:grid-cols-3">{data.map((h) => <HotelCard key={h.id} hotel={h} />)}</div>}
    </div>
  );
}
