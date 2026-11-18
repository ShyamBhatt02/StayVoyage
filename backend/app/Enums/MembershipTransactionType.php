<?php

namespace App\Enums;

enum MembershipTransactionType: string
{
    case MembershipPurchase = 'membership_purchase';
    case BookingDeduction = 'booking_deduction';
    case BookingCancellationRefund = 'booking_cancellation_refund';
    case ManualAdjustment = 'manual_adjustment';
    case Expiration = 'expiration';
    case AdminAdjustment = 'admin_adjustment';

    public function label(): string
    {
        return match ($this) {
            self::MembershipPurchase => 'Membership Purchase',
            self::BookingDeduction => 'Booking Deduction',
            self::BookingCancellationRefund => 'Booking Cancellation Refund',
            self::ManualAdjustment => 'Manual Adjustment',
            self::Expiration => 'Expiration',
            self::AdminAdjustment => 'Admin Adjustment',
        };
    }
}
