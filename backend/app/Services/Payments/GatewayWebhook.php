<?php

namespace App\Services\Payments;

final class GatewayWebhook
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $eventType,
        public readonly ?string $orderId,       // gateway order / session id we stored on the payment
        public readonly ?string $reference,     // our payment reference, when the gateway echoes it back
        public readonly GatewayResult $result,
        public readonly array $payload,
    ) {}
}
