<?php

namespace App\Support;

class Money
{
    /** Round half-up to 2 decimals (avoids float drift by working in paise). */
    public static function round(float|int|string $amount): float
    {
        return round(round((float) $amount * 100) / 100, 2);
    }

    public static function percent(float $amount, float $percent): float
    {
        return self::round($amount * $percent / 100);
    }

    public static function toMinor(float $amount): int
    {
        return (int) round($amount * 100);
    }

    public static function format(float $amount, string $currency = 'INR'): string
    {
        $symbol = $currency === 'INR' ? '₹' : ($currency === 'USD' ? '$' : $currency.' ');
        if ($currency === 'INR') {
            return $symbol.self::indian($amount);
        }

        return $symbol.number_format($amount, 2);
    }

    /** Indian digit grouping: 1,23,45,678.00 */
    public static function indian(float $amount): string
    {
        $negative = $amount < 0;
        $amount = abs($amount);
        $whole = (string) floor($amount);
        $decimals = str_pad((string) round(($amount - floor($amount)) * 100), 2, '0', STR_PAD_LEFT);
        if ($decimals === '100') {
            $whole = (string) ((int) $whole + 1);
            $decimals = '00';
        }
        $last3 = substr($whole, -3);
        $rest = substr($whole, 0, -3);
        if ($rest !== '' && $rest !== false) {
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $whole = $rest.','.$last3;
        }
        $out = $decimals === '00' ? $whole : $whole.'.'.$decimals;

        return ($negative ? '-' : '').$out;
    }
}
