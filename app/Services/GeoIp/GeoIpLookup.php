<?php

namespace App\Services\GeoIp;

/** Turns an IP address into a place name for the "new sign-in" email. Bind a real implementation in AppServiceProvider. */
interface GeoIpLookup
{
    /** "Dhaka, Bangladesh", or null when the place is not known. */
    public function lookup(string $ip): ?string;
}
