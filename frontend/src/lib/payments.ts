import type { NavigateFunction } from 'react-router-dom';
import { post } from '@/lib/api';
import type { Checkout } from '@/types';

declare global {
  interface Window { Razorpay?: new (opts: Record<string, unknown>) => { open: () => void; on: (e: string, cb: (r: unknown) => void) => void } }
}

function loadScript(src: string): Promise<void> {
  return new Promise((resolve, reject) => {
    if (document.querySelector(`script[src="${src}"]`)) return resolve();
    const s = document.createElement('script');
    s.src = src;
    s.async = true;
    s.onload = () => resolve();
    s.onerror = () => reject(new Error('Could not load the payment window. Please check your connection.'));
    document.body.appendChild(s);
  });
}

/**
 * Opens the right payment UI for the checkout returned by the API. The result is ALWAYS confirmed by the
 * backend (signature/API verification or webhook) — the status page polls the server for the outcome.
 */
export async function startPayment(checkout: Checkout, navigate: NavigateFunction): Promise<void> {
  const statusPath = `/checkout/status/${checkout.reference}`;

  if (checkout.type === 'sandbox') {
    navigate(checkout.path ?? `/checkout/sandbox/${checkout.reference}`);
    return;
  }
  if (checkout.type === 'stripe' && checkout.checkout_url) {
    window.location.assign(checkout.checkout_url);
    return;
  }
  if (checkout.type === 'razorpay') {
    await loadScript('https://checkout.razorpay.com/v1/checkout.js');
    if (!window.Razorpay) throw new Error('Razorpay could not be loaded.');
    const rzp = new window.Razorpay({
      key: checkout.key,
      order_id: checkout.order_id,
      amount: checkout.amount,
      currency: checkout.currency,
      name: checkout.name ?? 'StayVoyage',
      description: checkout.description,
      prefill: checkout.prefill,
      theme: { color: '#0E6B7B' },
      handler: async (resp: Record<string, string>) => {
        try {
          await post(`/payments/${checkout.reference}/verify`, resp);
        } finally {
          navigate(statusPath);
        }
      },
      modal: { ondismiss: () => navigate(statusPath) },
    });
    rzp.open();
    return;
  }
  navigate(statusPath);
}
