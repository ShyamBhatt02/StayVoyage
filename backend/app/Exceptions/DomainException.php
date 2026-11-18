<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A business-rule violation that is safe to show to the customer, e.g. "Insufficient membership nights".
 * Rendered as { success:false, message, code } with a 422 (or custom) status.
 */
class DomainException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'business_rule_violation',
        public readonly int $status = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function make(string $message, string $code, int $status = 422, array $context = []): self
    {
        return new self($message, $code, $status, $context);
    }
}
