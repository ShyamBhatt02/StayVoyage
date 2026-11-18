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
use Razorpay\Api\Api;
use Throwable;

/** Razorpay Standard Checkout (use TEST MODE keys only). */
class RazorpayGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'razorpay';
    }

    public function isAvailable(): bool
    {
        return filled(config('payments.razorpay.key_id')) && filled(config('payments.razorpay.key_secret'));
    }

    private function api(): Api
    {
        return new Api(config('payments.razorpay.key_id'), config('payments.razorpay.key_secret'));
    }

    public function createOrder(Payment $payment): array
    {
        $order = $this->api()->order->create([
            'receipt' => $payment->reference,
            'amount' => Money::toMinor($payment->amount),
            'currency' => $payment->currency,
            'payment_capture' => 1,
            'notes' => ['reference' => $payment->reference],
        ]);
        $user = $payment->user;

        return [
            'order_id' => $order['id'],
            'checkout' => [
                'type' => 'razorpay',
                'key' => config('payments.razorpay.key_id'),
                'order_id' => $order['id'],
                'amount' => Money::toMinor($payment->amount),
                'currency' => $payment->currency,
                'name' => 'StayVoyage',
                'description' => ucfirst($payment->purpose).' payment '.$payment->reference,
                'prefill' => ['name' => $user?->name, 'email' => $user?->email, 'contact' => $user?->phone],
            ],
        ];
    }

    public function verify(Payment $payment, array $data): GatewayResult
    {
        $orderId = (string) ($data['razorpay_order_id'] ?? '');
        $paymentId = (string) ($data['razorpay_payment_id'] ?? '');
        $signature = (string) ($data['razorpay_signature'] ?? '');

        if ($orderId !== $payment->gateway_order_id || ! $paymentId || ! $signature) {
            return GatewayResult::failed('Payment details did not match this order.');
        }
        $expected = hash_hmac('sha256', $orderId.'|'.$paymentId, (string) config('payments.razorpay.key_secret'));
        if (! hash_equals($expected, $signature)) {
            Log::channel('payments')->warning('Razorpay signature mismatch', ['reference' => $payment->reference]);

            return GatewayResult::failed('Payment signature verification failed.');
        }

        try {
            $rp = $this->api()->payment->fetch($paymentId);
            if ($rp['status'] === 'authorized') {
                $rp = $rp->capture(['amount' => $rp['amount'], 'currency' => $rp['currency']]);
            }
            if ($rp['status'] === 'captured') {
                return GatewayResult::paid($paymentId, $rp['method'] ?? null, (int) $rp['amount'], ['order_id' => $orderId]);
            }
            if ($rp['status'] === 'failed') {
                return GatewayResult::failed($rp['error_description'] ?? 'Payment failed', $paymentId);
            }
        } catch (Throwable $e) {
            Log::channel('payments')->error('Razorpay verify error', ['reference' => $payment->reference, 'error' => $e->getMessage()]);
        }

        return GatewayResult::pending();
    }

    public function parseWebhook(Request $request): ?GatewayWebhook
    {
        $body = $request->getContent();
        $signature = (string) $request->header('X-Razorpay-Signature');
        $secret = (string) config('payments.razorpay.webhook_secret');
        if (! $secret || ! $signature || ! hash_equals(hash_hmac('sha256', $body, $secret), $signature)) {
            throw DomainException::make('Invalid webhook signature.', 'invalid_signature', 400);
        }

        $event = json_decode($body, true);
        $entity = $event['payload']['payment']['entity'] ?? null;
        if (! $entity) {
            return null;
        }
        $result = match ($event['event'] ?? null) {
            'payment.captured' => GatewayResult::paid($entity['id'], $entity['method'] ?? null, (int) $entity['amount']),
            'payment.failed' => GatewayResult::failed($entity['error_description'] ?? 'Payment failed', $entity['id']),
            default => null,
        };
        if (! $result) {
            return null;
        }
        $eventId = (string) ($request->header('X-Razorpay-Event-Id') ?: $event['event'].':'.$entity['id']);

        return new GatewayWebhook($eventId, $event['event'], $entity['order_id'] ?? null, $entity['notes']['reference'] ?? null, $result, $event);
    }

    public function refund(Payment $payment, float $amount, string $reason): GatewayRefund
    {
        try {
            $refund = $this->api()->payment->fetch($payment->gateway_payment_id)->refund([
                'amount' => Money::toMinor($amount),
                'notes' => ['reason' => mb_substr($reason, 0, 200), 'reference' => $payment->reference],
            ]);

            return new GatewayRefund(true, $refund['id'], null, ['status' => $refund['status'] ?? null]);
        } catch (Throwable $e) {
            Log::channel('payments')->error('Razorpay refund failed', ['reference' => $payment->reference, 'error' => $e->getMessage()]);

            return new GatewayRefund(false, null, $e->getMessage());
        }
    }
}
