import { Compass } from 'lucide-react';
import { ButtonLink } from '@/components/ui/Button';
import { usePageMeta } from '@/hooks/useDocumentTitle';

export default function NotFound() {
  usePageMeta('Page not found');
  return (
    <div className="container-page flex min-h-[60vh] flex-col items-center justify-center py-20 text-center">
      <div className="flex h-20 w-20 items-center justify-center rounded-3xl bg-brand-50"><Compass className="h-10 w-10 text-brand-600" /></div>
      <p className="eyebrow mt-6">Error 404</p>
      <h1 className="section-title mt-2">This page took a different route</h1>
      <p className="mt-3 max-w-md text-ink-600">The page you're looking for doesn't exist or has moved. Let's get you back on track.</p>
      <div className="mt-8 flex flex-wrap justify-center gap-3">
        <ButtonLink to="/">Back to home</ButtonLink>
        <ButtonLink to="/hotels" variant="outline">Search hotels</ButtonLink>
      </div>
    </div>
  );
}
