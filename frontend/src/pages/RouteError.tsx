import { isRouteErrorResponse, Link, useRouteError } from 'react-router-dom';
import { Compass } from 'lucide-react';

export function RouteError() {
  const error = useRouteError();
  const notFound = isRouteErrorResponse(error) && error.status === 404;
  // Chunk load errors after a deploy: a reload fetches the new build.
  const chunk = error instanceof Error && /Failed to fetch dynamically imported module|Loading chunk/.test(error.message);
  return (
    <div className="flex min-h-screen flex-col items-center justify-center bg-ink-50 px-6 text-center">
      <Compass className="h-14 w-14 text-brand-600" />
      <h1 className="mt-6 font-display text-3xl font-semibold text-ink-900">{notFound ? 'Page not found' : chunk ? 'A new version is available' : 'Something went off course'}</h1>
      <p className="mt-3 max-w-md text-ink-600">{chunk ? 'Please reload the page to continue.' : 'An unexpected error occurred. Our team has been notified — please try again.'}</p>
      <div className="mt-8 flex gap-3">
        <button onClick={() => window.location.reload()} className="rounded-xl bg-brand-600 px-5 py-2.5 font-semibold text-white hover:bg-brand-700">Reload</button>
        <Link to="/" className="rounded-xl border border-ink-200 bg-white px-5 py-2.5 font-semibold text-ink-800 hover:border-brand-400">Go home</Link>
      </div>
    </div>
  );
}
