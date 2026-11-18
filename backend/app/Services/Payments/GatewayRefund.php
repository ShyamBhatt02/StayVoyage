<?php

namespace App\Services\Payments;

final class GatewayRefund
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $refundId = null,
        public readonly ?string $error = null,
        public readonly array $metadata = [],
    ) {}
}
