<?php

namespace App\Services\Payments\Gateways;

use App\Exceptions\DomainException;
use App\Models\Payment;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\GatewayRefund;
use App\Services\Payments\GatewayResult;
use App\Services\Payments\GatewayWebhook;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * StayVoyage's built-in payment simulator for local development and demos (no keys required, no money moves).
 *
 * It behaves like a hosted-checkout gateway: the SPA shows a test checkout page, and the outcome is delivered
 * to the backend as an HMAC-signed webhook which goes through exactly the same verification + idempotency
 * path as Razorpay/Stripe webhooks. Disabled in production unless PAYMENT_SANDBOX_ENABLED=true.
 */
class SandboxGateway implements PaymentGateway
{
    public const SIGNATURE_HEADER = 'X-Sandbox-Signature';

    public function name(): string
    {
        return 'sandbox';
    }

    public function isAvailable(): bool
    {
        return (bool) config('payments.sandbox.enabled');
    }

    public function createOrder(Payment $payment): array
    {
        $orderId = 'sbx_order_'.Str::lower(Str::random(16));

        return [
            'order_id' => $orderId,
            'checkout' => [
                'type' => 'sandbox',
                'order_id' => $orderId,
                'amount' => Money::toMinor($payment->amount),
                'currency' => $payment->currency,
                'path' => '/checkout/sandbox/'.$payment->reference,
            ],
        ];
    }

    /** Builds the signed event the simulated checkout page "sends" to our webhook endpoint. */
    public function buildSignedEvent(Payment $payment, string $outcome, string $method): array
    {
        $body = json_encode([
            'id' => 'sbx_evt_'.Str::lower(Str::random(18)),
            'type' => $outcome === 'success' ? 'payment.captured' : 'payment.failed',
            'created' => now()->timestamp,
            'data' => [
                'order_id' => $payment->gateway_order_id,
                'reference' => $payment->reference,
                'payment_id' => 'sbx_pay_'.Str::lower(Str::random(16)),
                'amount' => Money::toMinor($payment->amount),
                'currency' => $payment->currency,
                'method' => $method,
                'failure_reason' => $outcome === 'success' ? null : 'Card declined by issuing bank (simulated).',
            ],
        ], JSON_UNESCAPED_SLASHES);

        return ['body' => $body, 'signature' => $this->sign($body)];
    }

    public function sign(string $body): string
    {
        return hash_hmac('sha256', $body, (string) config('payments.sandbox.secret'));
    }

    public function verify(Payment $payment, array $data): GatewayResult
    {
        // Sandbox outcomes arrive only via the signed webhook; a client "verify" call just reports pending.
        return GatewayResult::pending();
    }

    public function parseWebhook(Request $request): ?GatewayWebhook
    {
        $body = $request->getContent();
        $signature = (string) $request->header(self::SIGNATURE_HEADER);
        if (! $this->isAvailable() || ! $signature || ! hash_equals($this->sign($body), $signature)) {
            throw DomainException::make('Invalid webhook signature.', 'invalid_signature', 400);
        }

        return $this->eventFromBody($body);
    }

    public function eventFromBody(string $body): ?GatewayWebhook
    {
        $event = json_decode($body, true);
        $d = $event['data'] ?? [];
        $result = match ($event['type'] ?? null) {
            'payment.captured' => GatewayResult::paid($d['payment_id'], $d['method'] ?? 'card', (int) $d['amount'], ['simulated' => true]),
            'payment.failed' => GatewayResult::failed($d['failure_reason'] ?? 'Payment failed', $d['payment_id'] ?? null, ['simulated' => true]),
            default => null,
        };
        if (! $result) {
            return null;
        }

        return new GatewayWebhook($event['id'], $event['type'], $d['order_id'] ?? null, $d['reference'] ?? null, $result, $event);
    }

    public function refund(Payment $payment, float $amount, string $reason): GatewayRefund
    {
        return new GatewayRefund(true, 'sbx_rfnd_'.Str::lower(Str::random(16)), null, ['simulated' => true]);
    }
}
