<?php

namespace App\Services\Payments\Contracts;

use App\Models\Payment;
use App\Services\Payments\GatewayRefund;
use App\Services\Payments\GatewayResult;
use App\Services\Payments\GatewayWebhook;
use Illuminate\Http\Request;

interface PaymentGateway
{
    public function name(): string;

    /** True when credentials are present (test keys) and the gateway may be offered. */
    public function isAvailable(): bool;

    /**
     * Create the order at the gateway.
     * @return array{order_id:string, checkout:array} checkout = what the SPA needs to open the payment UI
     */
    public function createOrder(Payment $payment): array;

    /** Verify a client-side callback SERVER-SIDE (signature / API lookup). Never trusts the client's claim. */
    public function verify(Payment $payment, array $data): GatewayResult;

    /** Verify the webhook signature and normalise the event. Returns null for events we don't handle. */
    public function parseWebhook(Request $request): ?GatewayWebhook;

    public function refund(Payment $payment, float $amount, string $reason): GatewayRefund;
}
