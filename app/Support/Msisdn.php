<?php

namespace App\Support;

/**
 * Kenyan mobile numbers in the shape M-PESA expects.
 */
class Msisdn
{
    /**
     * The shapes a caller may send: 0712..., 254712..., +254712..., 712...
     */
    public const RULE = 'regex:/^(?:\+?254|0)?(?:7|1)\d{8}$/';

    /**
     * Normalise a mobile number to the 2547XXXXXXXX form M-PESA uses.
     */
    public static function normalize(string $mobile): string
    {
        $digits = preg_replace('/\D/', '', $mobile) ?? '';

        return match (true) {
            str_starts_with($digits, '254') => $digits,
            str_starts_with($digits, '0') => '254'.substr($digits, 1),
            strlen($digits) === 9 => '254'.$digits,
            default => $digits,
        };
    }
}
