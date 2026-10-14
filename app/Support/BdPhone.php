<?php

namespace App\Support;

/**
 * Bangladeshi mobile numbers. Accepts 01XXXXXXXXX, 8801XXXXXXXXX and +8801XXXXXXXXX (spaces, dashes and
 * brackets are ignored) and always stores +8801XXXXXXXXX. The digit after 01 is the operator: 3 to 9
 * (013/014/015/016/017/018/019 are the mobile operators, 011/012 and landlines are not).
 */
class BdPhone
{
    /** National form without the leading 0: 1XXXXXXXXX (10 digits, operator digit 3-9). */
    private const NATIONAL = '/^1[3-9]\d{8}$/';

    /** "+8801711223344" for any accepted spelling, null when the value is not a Bangladeshi mobile number. */
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        $plus = str_starts_with($value, '+');
        $digits = preg_replace('/[\s\-().]/', '', $plus ? substr($value, 1) : $value);
        if ($digits === null || $digits === '' || ! ctype_digit($digits)) {
            return null;
        }

        $national = match (true) {
            ! $plus && str_starts_with($digits, '0') => substr($digits, 1),       // 01XXXXXXXXX
            str_starts_with($digits, '880') => substr($digits, 3),                // 8801XXXXXXXXX and +8801XXXXXXXXX
            default => null,
        };

        return $national !== null && preg_match(self::NATIONAL, $national) === 1 ? '+880'.$national : null;
    }

    public static function isValid(?string $value): bool
    {
        return self::normalize($value) !== null;
    }
}
