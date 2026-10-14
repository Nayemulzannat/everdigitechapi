<?php

namespace Database\Seeders;

use App\Models\ProductGroup;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The sections of the catalog, named like the pages of the public site. Only the groups: no products and no prices,
 * because those are entered in the admin panel (Products & pricing). Safe to re-run; rename, hide or delete them there.
 */
class ProductGroupSeeder extends Seeder
{
    public function run(): void
    {
        $groups = [
            ['Domain Registration', 'domain', 'Register or transfer a domain name.'],
            ['Web Hosting', 'hosting', 'Shared hosting for websites.'],
            ['BDIX Hosting', 'hosting', 'Hosting with fast access inside Bangladesh.'],
            ['Turbo Hosting', 'hosting', 'High-speed hosting for busy sites.'],
            ['Node.js Hosting', 'hosting', 'Hosting for Node.js applications.'],
            ['VPS Servers', 'server', 'Virtual private servers.'],
            ['Cloud Servers', 'server', 'Scalable cloud servers.'],
            ['Dedicated Servers', 'server', 'A whole physical server for you.'],
            ['Bulk SMS', 'sms', 'Promotional and transactional SMS.'],
            ['CRM & Call Center Software', 'software', 'CRM, auto dialer and call center software.'],
        ];

        foreach ($groups as $order => [$name, $type, $description]) {
            ProductGroup::firstOrCreate(['name' => $name], ['slug' => Str::slug($name), 'type' => $type, 'description' => $description, 'sort_order' => $order + 1]);
        }
    }
}
