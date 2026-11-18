<?php

namespace App\Services\Booking;

use App\Models\Booking;
use Illuminate\Support\Str;

/** Non-sequential, human-friendly booking references, e.g. SVH-2611-7KQ2M (no 0/O/1/I ambiguity). */
class BookingNumber
{
    private const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public static function make(string $prefix): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $number = 'SV'.Str::upper($prefix).'-'.now()->format('ym').'-'.$code;
        } while (Booking::withTrashed()->where('booking_number', $number)->exists());

        return $number;
    }
}
