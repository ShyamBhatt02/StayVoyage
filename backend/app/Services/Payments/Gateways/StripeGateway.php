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
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;
use Stripe\Webhook;
use Throwable;

/** Stripe hosted Checkout Sessions (use TEST MODE keys only). */
class StripeGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'stripe';
    }

    public function isAvailable(): bool
    {
        return filled(config('payments.stripe.secret'));
    }

    private function client(): StripeClient
    {
        return new StripeClient((string) config('payments.stripe.secret'));
    }

    public function createOrder(Payment $payment): array
    {
        $front = rtrim((string) config('payments.frontend_url'), '/');
        $session = $this->client()->checkout->sessions->create([
            'mode' => 'payment',
            'client_reference_id' => $payment->reference,
            'customer_email' => $payment->user?->email,
            'metadata' => ['reference' => $payment->reference],
            'payment_intent_data' => ['metadata' => ['reference' => $payment->reference]],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($payment->currency),
                    'unit_amount' => Money::toMinor($payment->amount),
                    'product_data' => ['name' => 'StayVoyage '.ucfirst($payment->purpose).' '.$payment->reference],
                ],
            ]],
            'success_url' => $front.'/payments/return?ref='.$payment->reference.'&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $front.'/payments/return?ref='.$payment->reference.'&cancelled=1',
            'expires_at' => now()->addMinutes(31)->timestamp,
        ]);

        return [
            'order_id' => $session->id,
            'checkout' => ['type' => 'stripe', 'order_id' => $session->id, 'checkout_url' => $session->url],
        ];
    }

    public function verify(Payment $payment, array $data): GatewayResult
    {
        try {
            $session = $this->client()->checkout->sessions->retrieve($payment->gateway_order_id, ['expand' => ['payment_intent']]);

            return $this->resultFromSession($session->toArray());
        } catch (Throwable $e) {
            Log::channel('payments')->error('Stripe verify error', ['reference' => $payment->reference, 'error' => $e->getMessage()]);

            return GatewayResult::pending();
        }
    }

    public function parseWebhook(Request $request): ?GatewayWebhook
    {
        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                (string) config('payments.stripe.webhook_secret'),
            );
        } catch (Throwable) {
            throw DomainException::make('Invalid webhook signature.', 'invalid_signature', 400);
        }

        $session = $event->data->object->toArray();
        $result = match ($event->type) {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => $this->resultFromSession($session),
            'checkout.session.async_payment_failed' => GatewayResult::failed('Payment failed'),
            'checkout.session.expired' => GatewayResult::failed('Checkout session expired'),
            default => null,
        };
        if (! $result) {
            return null;
        }

        return new GatewayWebhook($event->id, $event->type, $session['id'] ?? null, $session['client_reference_id'] ?? null, $result, $event->toArray());
    }

    private function resultFromSession(array $session): GatewayResult
    {
        $intent = $session['payment_intent'] ?? null;
        $intentId = is_array($intent) ? ($intent['id'] ?? null) : $intent;
        if (($session['payment_status'] ?? null) === 'paid' && $intentId) {
            return GatewayResult::paid($intentId, $session['payment_method_types'][0] ?? 'card', (int) ($session['amount_total'] ?? 0));
        }
        if (($session['status'] ?? null) === 'expired') {
            return GatewayResult::failed('Checkout session expired');
        }

        return GatewayResult::pending();
    }

    public function refund(Payment $payment, float $amount, string $reason): GatewayRefund
    {
        try {
            $refund = $this->client()->refunds->create([
                'payment_intent' => $payment->gateway_payment_id,
                'amount' => Money::toMinor($amount),
                'metadata' => ['reference' => $payment->reference, 'reason' => mb_substr($reason, 0, 200)],
            ]);

            return new GatewayRefund(true, $refund->id, null, ['status' => $refund->status]);
        } catch (Throwable $e) {
            Log::channel('payments')->error('Stripe refund failed', ['reference' => $payment->reference, 'error' => $e->getMessage()]);

            return new GatewayRefund(false, null, $e->getMessage());
        }
    }
}
