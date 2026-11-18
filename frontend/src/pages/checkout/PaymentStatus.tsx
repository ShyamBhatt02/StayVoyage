import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { CheckCircle2, Clock, Loader2, RefreshCw, XCircle } from 'lucide-react';
import { get, post } from '@/lib/api';
import { startPayment } from '@/lib/payments';
import { usePageMeta } from '@/hooks/useDocumentTitle';
import { Button, ButtonLink } from '@/components/ui/Button';
import { ErrorState, Spinner } from '@/components/ui/misc';
import type { Checkout, Payment } from '@/types';

interface PaymentInfo {
  payment: Payment;
  payable: { type: string; reference: string; status: string; hold_expires_at: string | null };
  checkout_display: { amount: number; amount_formatted: string; description: string };
}

/** Polls the SERVER for the verified payment result — never trusts the redirect alone. */
export default function PaymentStatus() {
  usePageMeta('Payment status');
  const params = useParams();
  const [search] = useSearchParams();
  const reference = params.reference ?? search.get('ref') ?? '';
  const navigate = useNavigate();
  const qc = useQueryClient();
  const [polls, setPolls] = useState(0);
  const verified = useRef(false);

  // Returning from Stripe Checkout: ask the server to verify the session.
  useEffect(() => {
    if (!verified.current && reference && (search.get('session_id') || search.get('cancelled'))) {
      verified.current = true;
      post(`/payments/${reference}/verify`).catch(() => undefined).finally(() => qc.invalidateQueries({ queryKey: ['payment-status', reference] }));
    }
  }, [reference, search, qc]);

  const q = useQuery({
    queryKey: ['payment-status', reference],
    queryFn: () => get<PaymentInfo>(`/payments/${reference}`),
    enabled: !!reference,
    refetchInterval: (query) => {
      const s = query.state.data?.payment.status;
      return s === 'pending' || s === 'processing' ? (polls < 30 ? 2000 : false) : false;
    },
  });
  useEffect(() => { if (q.data && ['pending', 'processing'].includes(q.data.payment.status)) setPolls((p) => p + 1); }, [q.dataUpdatedAt, q.data]);
  useEffect(() => {
    if (q.data?.payment.status === 'paid') {
      qc.invalidateQueries({ queryKey: ['auth'] });
      qc.invalidateQueries({ predicate: (x) => ['dashboard', 'bookings', 'membership'].includes(String(x.queryKey[0])) });
    }
  }, [q.data?.payment.status, qc]);

  const retry = useMutation({
    mutationFn: () => post<{ checkout: Checkout }>(`/bookings/${q.data!.payable.reference}/pay`, {}),
    onSuccess: ({ data }) => startPayment(data.checkout, navigate),
    onError: (e) => toast.error((e as Error).message),
  });

  if (!reference) return <div className="container-page py-16"><ErrorState message="Missing payment reference." /></div>;
  if (q.isLoading) return <Spinner className="min-h-[60vh]" label="Checking payment" />;
  if (q.isError || !q.data) return <div className="container-page py-16"><ErrorState message={(q.error as Error)?.message} onRetry={() => q.refetch()} /></div>;

  const { payment, payable, checkout_display: d } = q.data;
  const isBooking = payable.type === 'booking';
  const target = isBooking ? `/account/bookings/${payable.reference}` : '/account/membership';

  return (
    <div className="container-page flex min-h-[70vh] items-center justify-center py-16">
      <div className="card w-full max-w-lg p-8 text-center sm:p-10" role="status" aria-live="polite">
        {payment.status === 'paid' ? (
          <>
            <CheckCircle2 className="mx-auto h-16 w-16 text-success-600" />
            <h1 className="mt-5 font-display text-3xl font-semibold text-ink-900">{isBooking ? 'Booking confirmed!' : 'Membership activated!'}</h1>
            <p className="mt-3 text-ink-600">We received {d.amount_formatted}. {isBooking ? `Your booking ${payable.reference} is confirmed and a confirmation email is on its way.` : 'Your membership nights are ready to use.'}</p>
            <div className="mt-8 flex flex-wrap justify-center gap-3">
              <ButtonLink to={target}>{isBooking ? 'View booking' : 'Open membership wallet'}</ButtonLink>
              <ButtonLink to={isBooking ? '/account/bookings' : '/hotels?membership=1'} variant="outline">{isBooking ? 'All bookings' : 'Find a member hotel'}</ButtonLink>
            </div>
          </>
        ) : payment.status === 'failed' ? (
          <>
            <XCircle className="mx-auto h-16 w-16 text-danger-600" />
            <h1 className="mt-5 font-display text-3xl font-semibold text-ink-900">Payment failed</h1>
            <p className="mt-3 text-ink-600">{payment.failure_reason ?? 'The payment could not be completed.'} No money was taken, and your booking is not confirmed.</p>
            {isBooking && payable.status === 'payment_pending' && payable.hold_expires_at && (
              <p className="mt-2 flex items-center justify-center gap-1.5 text-sm font-semibold text-warning-700"><Clock className="h-4 w-4" /> Your room is held until {new Date(payable.hold_expires_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</p>
            )}
            <div className="mt-8 flex flex-wrap justify-center gap-3">
              {isBooking && payable.status === 'payment_pending'
                ? <Button onClick={() => retry.mutate()} loading={retry.isPending} icon={<RefreshCw className="h-4 w-4" />}>Try payment again</Button>
                : !isBooking && <ButtonLink to="/membership">Try again</ButtonLink>}
              <ButtonLink to={isBooking ? target : '/account'} variant="outline">{isBooking ? 'View booking' : 'Go to dashboard'}</ButtonLink>
            </div>
          </>
        ) : (
          <>
            <Loader2 className="mx-auto h-14 w-14 animate-spin text-brand-600" />
            <h1 className="mt-5 font-display text-2xl font-semibold text-ink-900">Confirming your payment…</h1>
            <p className="mt-3 text-ink-600">We're waiting for confirmation from the payment provider. This usually takes a few seconds — please don't close this page.</p>
            {polls >= 30 && (
              <p className="mt-4 text-sm text-ink-500">Taking longer than usual. You can safely check <Link to={target} className="link">your booking</Link> later — we'll email you as soon as it's confirmed.</p>
            )}
          </>
        )}
      </div>
    </div>
  );
}
