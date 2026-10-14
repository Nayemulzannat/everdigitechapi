<?php

namespace App\Services\GeoIp;

/** No GeoIP database yet: the place is always unknown, and the email says so. */
class NullGeoIpLookup implements GeoIpLookup
{
    public function lookup(string $ip): ?string
    {
        return null;
    }
}
