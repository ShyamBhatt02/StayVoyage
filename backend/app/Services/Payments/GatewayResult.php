<?php

namespace App\Services\Payments;

final class GatewayResult
{
    public function __construct(
        public readonly string $status, // paid | failed | pending
        public readonly ?string $gatewayPaymentId = null,
        public readonly ?string $method = null,
        public readonly ?string $failureReason = null,
        public readonly ?int $amountMinor = null,
        public readonly array $metadata = [],
    ) {}

    public static function paid(string $id, ?string $method, ?int $amountMinor, array $meta = []): self
    {
        return new self('paid', $id, $method, null, $amountMinor, $meta);
    }

    public static function failed(?string $reason, ?string $id = null, array $meta = []): self
    {
        return new self('failed', $id, null, $reason, null, $meta);
    }

    public static function pending(array $meta = []): self
    {
        return new self('pending', null, null, null, null, $meta);
    }
}
