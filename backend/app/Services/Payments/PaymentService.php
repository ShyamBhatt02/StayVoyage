<?php

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Events\PaymentFailed;
use App\Events\PaymentSucceeded;
use App\Events\RefundProcessed;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Models\Refund;
use App\Models\User;
use App\Services\Booking\BookingLifecycleService;
use App\Services\Membership\MembershipService;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Gateways\RazorpayGateway;
use App\Services\Payments\Gateways\SandboxGateway;
use App\Services\Payments\Gateways\StripeGateway;
use App\Services\SettingsService;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Payment orchestration. A payable (booking / membership) becomes "paid" ONLY through markPaid(), which is
 * reached exclusively from a server-side gateway verification or a signature-verified webhook.
 */
class PaymentService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function gateway(string $name): PaymentGateway
    {
        return match ($name) {
            'sandbox' => app(SandboxGateway::class),
            'razorpay' => app(RazorpayGateway::class),
            'stripe' => app(StripeGateway::class),
            default => throw DomainException::make('Unsupported payment method.', 'invalid_gateway'),
        };
    }

    /** @return array<int,array{name:string,label:string,default:bool}> */
    public function availableGateways(): array
    {
        $enabled = (array) $this->settings->get('payments.enabled_gateways', ['sandbox']);
        $default = $this->defaultGateway();
        $labels = ['sandbox' => 'StayVoyage Test Checkout', 'razorpay' => 'Razorpay (UPI, Cards, Netbanking)', 'stripe' => 'Stripe (International Cards)'];

        return collect(['sandbox', 'razorpay', 'stripe'])
            ->filter(fn ($g) => in_array($g, $enabled, true) && $this->gateway($g)->isAvailable())
            ->map(fn ($g) => ['name' => $g, 'label' => $labels[$g], 'default' => $g === $default])
            ->values()
            ->all();
    }

    public function defaultGateway(): string
    {
        $configured = (string) $this->settings->get('payments.default_gateway', config('payments.default'));
        if ($this->gateway($configured)->isAvailable()) {
            return $configured;
        }
        foreach (['razorpay', 'stripe', 'sandbox'] as $g) {
            if ($this->gateway($g)->isAvailable()) {
                return $g;
            }
        }
        throw DomainException::make('Online payments are temporarily unavailable.', 'gateway_unavailable', 503);
    }

    public function resolveGateway(?string $requested): string
    {
        if (! $requested) {
            return $this->defaultGateway();
        }
        $names = array_column($this->availableGateways(), 'name');
        if (! in_array($requested, $names, true)) {
            throw DomainException::make('The selected payment method is not available.', 'invalid_gateway');
        }

        return $requested;
    }

    /** Create a pending payment row. Call inside the booking/membership transaction. */
    public function createPayment(User $user, Model $payable, float $amount, string $purpose, string $gateway): Payment
    {
        return Payment::query()->create([
            'reference' => 'PAY-'.strtoupper(Str::random(12)),
            'user_id' => $user->id,
            'payable_type' => $payable->getMorphClass(),
            'payable_id' => $payable->getKey(),
            'purpose' => $purpose,
            'gateway' => $gateway,
            'amount' => Money::round($amount),
            'currency' => $payable->currency ?? 'INR',
            'status' => PaymentStatus::Pending,
        ]);
    }

    /** Create the gateway order (outside DB locks — network call) and return what the SPA needs. */
    public function startCheckout(Payment $payment): array
    {
        if ($payment->status !== PaymentStatus::Pending) {
            throw DomainException::make('This payment can no longer be completed.', 'payment_not_pending', 409);
        }
        try {
            $order = $this->gateway($payment->gateway)->createOrder($payment->loadMissing('user'));
        } catch (DomainException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::channel('payments')->error('Gateway order creation failed', ['reference' => $payment->reference, 'gateway' => $payment->gateway, 'error' => $e->getMessage()]);
            throw DomainException::make('We could not reach the payment provider. Please try again or choose another payment method.', 'payment_gateway_error', 502);
        }
        $payment->forceFill(['gateway_order_id' => $order['order_id']])->save();
        Log::channel('payments')->info('Checkout started', ['reference' => $payment->reference, 'gateway' => $payment->gateway, 'amount' => $payment->amount]);

        return array_merge($order['checkout'], ['reference' => $payment->reference, 'gateway' => $payment->gateway]);
    }

    /** Client returned from checkout — verify with the gateway server-side. */
    public function verifyFromClient(Payment $payment, array $data): Payment
    {
        if (in_array($payment->status, [PaymentStatus::Paid, PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded], true)) {
            return $payment;
        }
        $result = $this->gateway($payment->gateway)->verify($payment, $data);
        $this->applyResult($payment, $result);

        return $payment->refresh();
    }

    /** Signature-verified, idempotent webhook processing. */
    public function handleWebhook(string $gatewayName, Request $request): string
    {
        $gateway = $this->gateway($gatewayName);
        $event = $gateway->parseWebhook($request); // throws on bad signature

        if (! $event) {
            return 'ignored';
        }

        try {
            $record = PaymentWebhookEvent::query()->create([
                'gateway' => $gatewayName,
                'event_id' => $event->eventId,
                'event_type' => $event->eventType,
                'payload' => $event->payload,
            ]);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                Log::channel('payments')->info('Duplicate webhook ignored', ['gateway' => $gatewayName, 'event_id' => $event->eventId]);

                return 'duplicate';
            }
            throw $e;
        }

        $payment = Payment::query()
            ->where('gateway', $gatewayName)
            ->where(fn ($q) => $q->where('gateway_order_id', $event->orderId ?? '__none__')->orWhere('reference', $event->reference ?? '__none__'))
            ->first();

        if (! $payment) {
            $record->update(['status' => 'ignored', 'error' => 'Payment not found', 'processed_at' => now()]);
            Log::channel('payments')->warning('Webhook for unknown payment', ['gateway' => $gatewayName, 'event' => $event->eventId]);

            return 'ignored';
        }

        try {
            $this->applyResult($payment, $event->result);
            $record->update(['status' => 'processed', 'processed_at' => now()]);
        } catch (Throwable $e) {
            $record->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 250)]);
            throw $e;
        }

        return 'processed';
    }

    public function applyResult(Payment $payment, GatewayResult $result): void
    {
        match ($result->status) {
            'paid' => $this->markPaid($payment, $result),
            'failed' => $this->markFailed($payment, $result->failureReason ?? 'Payment failed', $result->gatewayPaymentId),
            default => null,
        };
    }

    public function markPaid(Payment $payment, GatewayResult $result): void
    {
        $paidNow = DB::transaction(function () use ($payment, $result) {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($locked->status === PaymentStatus::Paid || $locked->paid_at) {
                return false; // already processed (duplicate callback/webhook)
            }

            if ($result->amountMinor !== null && $result->amountMinor !== Money::toMinor($locked->amount)) {
                Log::channel('payments')->critical('Payment amount mismatch', [
                    'reference' => $locked->reference, 'expected' => Money::toMinor($locked->amount), 'received' => $result->amountMinor,
                ]);
                $locked->forceFill([
                    'status' => PaymentStatus::Failed,
                    'failure_reason' => 'Amount mismatch — flagged for review',
                    'gateway_payment_id' => $result->gatewayPaymentId,
                    'failed_at' => now(),
                ])->save();

                return false;
            }

            $locked->forceFill([
                'status' => PaymentStatus::Paid,
                'gateway_payment_id' => $result->gatewayPaymentId,
                'method' => $result->method,
                'gateway_metadata' => array_merge($locked->gateway_metadata ?? [], $result->metadata),
                'paid_at' => now(),
                'failure_reason' => null,
            ])->save();

            // Confirm the booking / activate the membership in the same transaction.
            $payable = $this->lockPayable($locked);
            match (true) {
                $payable instanceof Booking => app(BookingLifecycleService::class)->onPaymentSucceeded($payable, $locked),
                $payable instanceof Membership => app(MembershipService::class)->onPaymentSucceeded($payable, $locked),
                default => throw new \LogicException('Unknown payable for payment '.$locked->reference),
            };

            Log::channel('payments')->info('Payment captured', ['reference' => $locked->reference, 'amount' => $locked->amount, 'gateway' => $locked->gateway]);

            return true;
        });

        if ($paidNow) {
            event(new PaymentSucceeded($payment->refresh()));
        }
    }

    public function markFailed(Payment $payment, string $reason, ?string $gatewayPaymentId = null): void
    {
        $failedNow = DB::transaction(function () use ($payment, $reason, $gatewayPaymentId) {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if (in_array($locked->status, [PaymentStatus::Paid, PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded, PaymentStatus::Failed], true)) {
                return false;
            }
            $locked->forceFill([
                'status' => PaymentStatus::Failed,
                'failure_reason' => mb_substr($reason, 0, 250),
                'gateway_payment_id' => $gatewayPaymentId ?? $locked->gateway_payment_id,
                'failed_at' => now(),
            ])->save();

            $payable = $this->lockPayable($locked);
            if ($payable instanceof Booking) {
                app(BookingLifecycleService::class)->onPaymentFailed($payable, $locked);
            }
            Log::channel('payments')->warning('Payment failed', ['reference' => $locked->reference, 'reason' => $reason]);

            return true;
        });

        if ($failedNow) {
            event(new PaymentFailed($payment->refresh()));
        }
    }

    private function lockPayable(Payment $payment): Booking|Membership|null
    {
        return match ($payment->payable_type) {
            'booking' => Booking::withTrashed()->lockForUpdate()->find($payment->payable_id),
            'membership' => Membership::withTrashed()->lockForUpdate()->find($payment->payable_id),
            default => null,
        };
    }

    /**
     * Refund (part of) a captured payment through its gateway. Records a Refund row either way so failures are visible.
     */
    public function refund(Payment $payment, float $amount, string $reason, ?int $initiatedBy = null, ?Booking $booking = null): Refund
    {
        $amount = Money::round($amount);

        $refund = DB::transaction(function () use ($payment, $amount, $reason, $initiatedBy, $booking) {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if (! in_array($locked->status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded], true)) {
                throw DomainException::make('Only captured payments can be refunded.', 'refund_not_allowed');
            }
            if ($amount <= 0 || $amount > $locked->refundableAmount()) {
                throw DomainException::make('Refund amount exceeds the refundable balance of '.Money::format($locked->refundableAmount()).'.', 'refund_not_allowed');
            }

            return Refund::query()->create([
                'reference' => 'RFD-'.strtoupper(Str::random(12)),
                'payment_id' => $locked->id,
                'booking_id' => $booking?->id ?? ($locked->payable_type === 'booking' ? $locked->payable_id : null),
                'user_id' => $locked->user_id,
                'amount' => $amount,
                'currency' => $locked->currency,
                'status' => 'pending',
                'reason' => mb_substr($reason, 0, 250),
                'initiated_by' => $initiatedBy,
            ]);
        });

        $result = $this->gateway($payment->gateway)->refund($payment, $amount, $reason);

        DB::transaction(function () use ($refund, $result, $amount) {
            $locked = Payment::query()->lockForUpdate()->findOrFail($refund->payment_id);
            if (! $result->success) {
                $refund->update(['status' => 'failed', 'gateway_metadata' => ['error' => $result->error]]);

                return;
            }
            $refund->update([
                'status' => 'processed',
                'gateway_refund_id' => $result->refundId,
                'gateway_metadata' => $result->metadata,
                'processed_at' => now(),
            ]);
            $refunded = Money::round($locked->amount_refunded + $amount);
            $locked->forceFill([
                'amount_refunded' => $refunded,
                'status' => $refunded >= $locked->amount ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded,
            ])->save();

            if ($locked->payable_type === 'booking') {
                $booking = Booking::query()->lockForUpdate()->find($locked->payable_id);
                if ($booking) {
                    $bookingRefunded = Money::round($booking->amount_refunded + $amount);
                    $booking->forceFill([
                        'amount_refunded' => min($bookingRefunded, $booking->amount_paid),
                        'payment_status' => $bookingRefunded >= $booking->amount_paid ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded,
                    ])->save();
                }
            }
        });

        Log::channel('payments')->info('Refund '.($result->success ? 'processed' : 'FAILED'), [
            'refund' => $refund->reference, 'payment' => $payment->reference, 'amount' => $amount, 'error' => $result->error,
        ]);

        if ($result->success) {
            event(new RefundProcessed($refund->refresh()));
        }

        return $refund->refresh();
    }
}
