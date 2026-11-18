import { useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { CheckCircle2, Loader2, XCircle } from 'lucide-react';
import { http } from '@/lib/api';
import { useAuth } from '@/providers/AuthProvider';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { ButtonLink } from '@/components/ui/Button';

export default function VerifyEmail() {
  usePageMeta('Verify your email');
  const [params] = useSearchParams();
  const { refresh } = useAuth();
  const [state, setState] = useState<'loading' | 'ok' | 'error'>('loading');
  const [message, setMessage] = useState('');
  const ran = useRef(false);

  useEffect(() => {
    if (ran.current) return;
    ran.current = true;
    const id = params.get('id');
    const hash = params.get('hash');
    if (!id || !hash) { setState('error'); setMessage('This verification link is incomplete.'); return; }
    const q = new URLSearchParams({ expires: params.get('expires') ?? '', signature: params.get('signature') ?? '' });
    http.get(`/auth/email/verify/${id}/${hash}?${q.toString()}`)
      .then((r) => { setState('ok'); setMessage(r.data.message); refresh(); })
      .catch((e) => { setState('error'); setMessage(e.message); });
  }, [params, refresh]);

  return (
    <div className="container-page flex min-h-[60vh] items-center justify-center py-16">
      <div className="card max-w-md p-10 text-center">
        {state === 'loading' && <Loader2 className="mx-auto h-12 w-12 animate-spin text-brand-600" />}
        {state === 'ok' && <CheckCircle2 className="mx-auto h-14 w-14 text-success-600" />}
        {state === 'error' && <XCircle className="mx-auto h-14 w-14 text-danger-600" />}
        <h1 className="mt-5 font-display text-2xl font-semibold text-ink-900">{state === 'loading' ? 'Verifying…' : state === 'ok' ? 'Email verified' : 'Verification failed'}</h1>
        <p className="mt-2 text-ink-600">{message}</p>
        {state !== 'loading' && <ButtonLink to="/account" className="mt-6">Go to my account</ButtonLink>}
      </div>
    </div>
  );
}
