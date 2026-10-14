<?php

namespace Database\Seeders;

use App\Models\DomainExtension;
use Illuminate\Database\Seeder;

/** The extensions the website listed before they moved to the admin panel. Safe to re-run: an extension that exists is left alone. */
class DomainExtensionSeeder extends Seeder
{
    public function run(): void
    {
        // [extension, tag, register price in taka (renewal and transfer the same), featured]
        $list = [
            ['.com', 'Popular', 999, true],
            ['.net', 'Great for business', 1199, true],
            ['.org', 'Non-profit friendly', 1299, true],
            ['.info', 'Information & tech', 998, true],
            ['.com.bd', 'Local favorite', 1499, true],
            ['.xyz', 'Budget friendly', 498, true],
            ['.online', null, null, false],
            ['.site', null, null, false],
            ['.shop', null, null, false],
            ['.tech', null, null, false],
            ['.app', null, null, false],
            ['.dev', null, null, false],
        ];

        foreach ($list as $order => [$extension, $tag, $taka, $featured]) {
            DomainExtension::firstOrCreate(['extension' => $extension], [
                'tag' => $tag,
                'register_price' => $taka ? $taka * 100 : null,
                'renew_price' => $taka ? $taka * 100 : null,
                'transfer_price' => $taka ? $taka * 100 : null,
                'is_featured' => $featured,
                'sort_order' => $order + 1,
            ]);
        }
    }
}
