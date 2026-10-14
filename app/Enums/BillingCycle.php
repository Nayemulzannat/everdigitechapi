<?php

namespace App\Enums;

/** How often a product is paid for. A price is for the whole cycle (the yearly price is the price of the year, not per month). */
enum BillingCycle: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Semiannually = 'semiannually';
    case Annually = 'annually';
    case Biennially = 'biennially';
    case Triennially = 'triennially';
    case OneTime = 'one_time';

    /** Length in months; null for a one-time payment. */
    public function months(): ?int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::Semiannually => 6,
            self::Annually => 12,
            self::Biennially => 24,
            self::Triennially => 36,
            self::OneTime => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Quarterly => 'Every 3 months',
            self::Semiannually => 'Every 6 months',
            self::Annually => 'Yearly',
            self::Biennially => 'Every 2 years',
            self::Triennially => 'Every 3 years',
            self::OneTime => 'One time',
        };
    }
}
