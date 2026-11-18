import { useState } from 'react';
import { Navigate, useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Building2, CreditCard, FlaskConical, Lock, Smartphone, Wallet, XCircle } from 'lucide-react';
import { get, post } from '@/lib/api';
import { cn } from '@/lib/format';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { LogoMark } from '@/components/brand/Logo';
import { Button } from '@/components/ui/Button';
import { ErrorState, Spinner } from '@/components/ui/misc';
import type { Payment } from '@/types';

interface PaymentInfo {
  payment: Payment;
  payable: { type: string; reference: string; status: string; hold_expires_at: string | null };
  checkout_display: { amount: number; amount_formatted: string; description: string; customer_email: string };
}

const METHODS = [
  { id: 'card', label: 'Card', icon: CreditCard },
  { id: 'upi', label: 'UPI', icon: Smartphone },
  { id: 'netbanking', label: 'Netbanking', icon: Building2 },
  { id: 'wallet', label: 'Wallet', icon: Wallet },
] as const;

/**
 * StayVoyage Test Checkout — stands in for a hosted gateway page in local/test environments.
 * Nothing typed here is sent anywhere: only the chosen outcome is posted, and the backend turns it into an
 * HMAC-signed webhook that is verified exactly like a real Razorpay/Stripe webhook.
 */
export default function SandboxCheckout() {
  usePageMeta('Secure checkout (test mode)');
  const { reference = '' } = useParams();
  const navigate = useNavigate();
  const [method, setMethod] = useState<(typeof METHODS)[number]['id']>('card');
  const [card, setCard] = useState('4111 1111 1111 1111');
  const [upi, setUpi] = useState('success@stayvoyage');

  const info = useQuery({ queryKey: ['payment', reference], queryFn: () => get<PaymentInfo>(`/payments/${reference}`) });
  const complete = useMutation({
    mutationFn: (outcome: 'success' | 'failure') => post<Payment>(`/payments/${reference}/sandbox-complete`, { outcome, method }),
    onSuccess: () => navigate(`/checkout/status/${reference}`, { replace: true }),
    onError: (e) => toast.error((e as Error).message),
  });

  const declines = (method === 'card' && card.replace(/\s/g, '').endsWith('0002')) || (method === 'upi' && upi.startsWith('failure'));

  if (info.isLoading) return <Spinner className="min-h-[60vh]" />;
  if (info.isError || !info.data) return <div className="container-page py-16"><ErrorState message={(info.error as Error)?.message} /></div>;
  const { payment, checkout_display: d } = info.data;
  if (payment.status !== 'pending') return <Navigate to={`/checkout/status/${reference}`} replace />;

  return (
    <div className="flex min-h-[80vh] items-center justify-center bg-gradient-to-br from-brand-50 to-ink-50 px-4 py-12">
      <div className="w-full max-w-md overflow-hidden rounded-3xl bg-white shadow-lift">
        <div className="bg-brand-800 px-6 py-5 text-white">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2"><LogoMark light className="h-8 w-8" /><span className="font-display text-lg font-semibold">StayVoyage Pay</span></div>
            <span className="flex items-center gap-1 rounded-full bg-white/15 px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide"><FlaskConical className="h-3.5 w-3.5" /> Test mode</span>
          </div>
          <p className="mt-4 text-sm text-brand-100">{d.description}</p>
          <p className="text-3xl font-bold">{d.amount_formatted}</p>
          <p className="mt-1 text-xs text-brand-200">Ref {payment.reference} · {d.customer_email}</p>
        </div>
        <div className="p-6">
          <div className="grid grid-cols-4 gap-2" role="radiogroup" aria-label="Payment method">
            {METHODS.map((m) => (
              <button key={m.id} role="radio" aria-checked={method === m.id} onClick={() => setMethod(m.id)}
                className={cn('flex flex-col items-center gap-1 rounded-xl border p-2.5 text-xs font-semibold transition', method === m.id ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-ink-200 text-ink-600 hover:border-ink-300')}>
                <m.icon className="h-5 w-5" />{m.label}
              </button>
            ))}
          </div>

          <div className="mt-5 space-y-3">
            {method === 'card' && (
              <>
                <label className="block text-xs font-semibold text-ink-600">Card number (test)
                  <input className="input mt-1 font-mono" value={card} onChange={(e) => setCard(e.target.value)} inputMode="numeric" autoComplete="off" />
                </label>
                <div className="grid grid-cols-2 gap-3">
                  <label className="block text-xs font-semibold text-ink-600">Expiry<input className="input mt-1" defaultValue="12/30" autoComplete="off" /></label>
                  <label className="block text-xs font-semibold text-ink-600">CVV<input className="input mt-1" defaultValue="123" autoComplete="off" /></label>
                </div>
                <p className="text-xs text-ink-500">Use any card number. Numbers ending in <span className="font-mono font-semibold">0002</span> simulate a decline.</p>
              </>
            )}
            {method === 'upi' && (
              <label className="block text-xs font-semibold text-ink-600">UPI ID (test)
                <input className="input mt-1" value={upi} onChange={(e) => setUpi(e.target.value)} autoComplete="off" />
                <span className="mt-1 block font-normal text-ink-500">IDs starting with “failure” simulate a decline.</span>
              </label>
            )}
            {(method === 'netbanking' || method === 'wallet') && (
              <select className="input" aria-label={method === 'netbanking' ? 'Bank' : 'Wallet'}>
                {(method === 'netbanking' ? ['Test Bank of India', 'Sandbox National Bank', 'Demo Co-operative Bank'] : ['Test Wallet', 'Demo Pay']).map((b) => <option key={b}>{b}</option>)}
              </select>
            )}
          </div>

          <Button className="mt-6 w-full" size="lg" loading={complete.isPending && complete.variables === (declines ? 'failure' : 'success')}
            onClick={() => complete.mutate(declines ? 'failure' : 'success')} icon={<Lock className="h-4 w-4" />}>
            Pay {d.amount_formatted}
          </Button>
          <button onClick={() => complete.mutate('failure')} disabled={complete.isPending}
            className="mt-3 flex w-full items-center justify-center gap-1.5 text-sm font-semibold text-ink-500 hover:text-danger-600">
            <XCircle className="h-4 w-4" /> Simulate a failed payment
          </button>
          <p className="mt-5 rounded-xl bg-ink-50 p-3 text-center text-xs text-ink-500">
            This is StayVoyage's built-in test gateway — no real money moves. Add Razorpay or Stripe test keys in <span className="font-mono">.env</span> to use their sandboxes instead.
          </p>
        </div>
      </div>
    </div>
  );
}
