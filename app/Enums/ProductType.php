<?php

namespace App\Enums;

/** What kind of thing a product group sells. Later steps use it to decide how an order is set up. */
enum ProductType: string
{
    case Hosting = 'hosting';
    case Server = 'server';
    case Domain = 'domain';
    case Sms = 'sms';
    case Software = 'software';
    case Service = 'service';

    public function label(): string
    {
        return match ($this) {
            self::Hosting => 'Hosting',
            self::Server => 'Servers',
            self::Domain => 'Domains',
            self::Sms => 'SMS',
            self::Software => 'Software',
            self::Service => 'Other services',
        };
    }
}
