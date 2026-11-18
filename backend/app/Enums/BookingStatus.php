<?php

namespace App\Enums;

enum BookingStatus: string
{
    case Pending = 'pending';
    case PaymentPending = 'payment_pending';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::PaymentPending => 'Payment Pending',
            self::Confirmed => 'Confirmed',
            self::Cancelled => 'Cancelled',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }

    /** Statuses that currently hold inventory / membership nights. */
    public static function holding(): array
    {
        return [self::Pending->value, self::PaymentPending->value, self::Confirmed->value];
    }
}
