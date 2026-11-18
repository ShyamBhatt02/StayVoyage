<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Services\Payments\Gateways\SandboxGateway;
use App\Services\Payments\PaymentService;
use App\Services\InvoiceService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function index(Request $request): JsonResponse
    {
        $payments = $request->user()->payments()->with('payable')->latest()->paginate(15);

        return $this->ok(PaymentResource::collection($payments));
    }

    /** Status polling after checkout. Includes the payable's resulting state. */
    public function show(Request $request, string $reference): JsonResponse
    {
        $payment = $this->owned($request, $reference);
        $payable = $payment->payable;

        return $this->ok([
            'payment' => new PaymentResource($payment),
            'payable' => [
                'type' => $payment->payable_type,
                'reference' => $payable?->booking_number ?? $payable?->membership_number,
                'status' => $payable?->status?->value,
                'hold_expires_at' => $payable?->hold_expires_at?->toIso8601String(),
            ],
            'checkout_display' => [
                'amount' => $payment->amount,
                'amount_formatted' => Money::format($payment->amount, $payment->currency),
                'description' => $payment->purpose === 'membership' ? 'StayVoyage membership' : 'Booking '.($payable?->booking_number ?? ''),
                'customer_email' => $request->user()->email,
            ],
        ]);
    }

    /** Client returned from the gateway: verify server-side (signature / API lookup). */
    public function verify(Request $request, string $reference): JsonResponse
    {
        $data = $request->validate([
            'razorpay_payment_id' => ['nullable', 'string', 'max:100'],
            'razorpay_order_id' => ['nullable', 'string', 'max:100'],
            'razorpay_signature' => ['nullable', 'string', 'max:200'],
        ]);
        $payment = $this->payments->verifyFromClient($this->owned($request, $reference), $data);

        return $this->ok(new PaymentResource($payment), match ($payment->status->value) {
            'paid' => 'Payment successful!',
            'failed' => 'Payment failed: '.($payment->failure_reason ?: 'please try again.'),
            default => 'We are confirming your payment with the bank…',
        });
    }

    /**
     * StayVoyage test checkout: emulates the customer completing/declining payment on a hosted gateway page.
     * The outcome is delivered as an HMAC-signed webhook through the SAME verification path as real gateways.
     * Card details typed on the test page never leave the browser.
     */
    public function sandboxComplete(Request $request, string $reference, SandboxGateway $sandbox): JsonResponse
    {
        $data = $request->validate([
            'outcome' => ['required', 'in:success,failure'],
            'method' => ['required', 'in:card,upi,netbanking,wallet'],
        ]);
        if (! $sandbox->isAvailable()) {
            throw DomainException::make('Test checkout is disabled on this environment.', 'gateway_unavailable', 403);
        }
        $payment = $this->owned($request, $reference);
        if ($payment->gateway !== 'sandbox' || ! $payment->gateway_order_id) {
            throw DomainException::make('This payment is not a test-checkout payment.', 'invalid_gateway');
        }

        $event = $sandbox->buildSignedEvent($payment, $data['outcome'], $data['method']);
        $webhook = Request::create('/api/v1/webhooks/sandbox', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_'.strtoupper(str_replace('-', '_', SandboxGateway::SIGNATURE_HEADER)) => $event['signature'],
        ], $event['body']);
        $this->payments->handleWebhook('sandbox', $webhook);

        $payment->refresh();

        return $this->ok(new PaymentResource($payment), $payment->status->value === 'paid' ? 'Payment successful!' : 'Payment declined (test).');
    }

    /** Gateway → server webhooks. Public route; authenticity is proven by the signature. */
    public function webhook(Request $request, string $gateway): JsonResponse
    {
        if (! in_array($gateway, ['razorpay', 'stripe', 'sandbox'], true)) {
            return $this->fail('Unknown gateway.', 404);
        }
        try {
            $status = $this->payments->handleWebhook($gateway, $request);
        } catch (DomainException $e) {
            Log::channel('payments')->warning('Webhook rejected', ['gateway' => $gateway, 'reason' => $e->getMessage(), 'ip' => $request->ip()]);

            return $this->fail($e->getMessage(), $e->status);
        }

        return $this->ok(['status' => $status]);
    }

    public function gateways(): JsonResponse
    {
        return $this->ok($this->payments->availableGateways());
    }

    public function membershipInvoice(Request $request, string $number, InvoiceService $invoices): Response
    {
        $membership = $request->user()->memberships()->where('membership_number', $number)->whereIn('status', ['active', 'expired'])->firstOrFail();
        $invoice = $invoices->forMembership($membership);

        return response($invoices->pdf($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$invoice->invoice_number.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function owned(Request $request, string $reference): Payment
    {
        return Payment::query()->where('reference', $reference)->where('user_id', $request->user()->id)->firstOrFail();
    }
}
