<?php

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductPrice;
use App\Services\Pricing;
use Database\Seeders\RoleSeeder;

beforeEach(fn () => $this->seed(RoleSeeder::class));

describe('tax settings', function () {
    it('shows the defaults from .env until someone saves a tax', function () {
        api(staffWith(['settings.view']))->getJson('/api/admin/settings/tax')->assertOk()->assertJsonPath('data', ['label' => 'VAT', 'rate' => 15, 'inclusive' => false]);
    });

    it('saves the tax, and the catalog and the product form use it at once', function () {
        $group = ProductGroup::create(['name' => 'Web Hosting', 'slug' => 'web-hosting', 'type' => 'hosting', 'is_active' => true]);
        $product = Product::create(['product_group_id' => $group->id, 'name' => 'Basic', 'slug' => 'basic', 'is_active' => true]);
        ProductPrice::create(['product_id' => $product->id, 'cycle' => 'monthly', 'amount' => 100000]);

        api(staffWith(['settings.edit', 'products.view']))->putJson('/api/admin/settings/tax', ['label' => 'GST', 'rate' => 7.5, 'inclusive' => false])
            ->assertOk()->assertJsonPath('data', ['label' => 'GST', 'rate' => 7.5, 'inclusive' => false]);

        $catalog = test()->getJson('/api/catalog')->assertOk();
        expect($catalog->json('data.tax'))->toBe(['label' => 'GST', 'rate' => 7.5, 'inclusive' => false])
            ->and($catalog->json('data.groups.0.products.0.prices.0.tax'))->toBe(7500)
            ->and($catalog->json('data.groups.0.products.0.prices.0.total'))->toBe(107500);
        expect(app(Pricing::class)->breakdown(100000)['tax'])->toBe(7500);
    });

    it('can switch the tax off, and say that prices already include it', function () {
        $admin = staffWith(['settings.edit']);

        api($admin)->putJson('/api/admin/settings/tax', ['label' => 'VAT', 'rate' => 0, 'inclusive' => false])->assertOk();
        expect(app(Pricing::class)->breakdown(100000))->toBe(['net' => 100000, 'tax' => 0, 'total' => 100000]);

        api($admin)->putJson('/api/admin/settings/tax', ['label' => 'VAT', 'rate' => 15, 'inclusive' => true])->assertOk();
        expect(app(Pricing::class)->breakdown(115000))->toBe(['net' => 100000, 'tax' => 15000, 'total' => 115000]);
    });

    it('refuses a rate that is not a percent, too many decimals, and an empty name', function () {
        $admin = staffWith(['settings.edit']);

        foreach ([['rate' => -1], ['rate' => 101], ['rate' => 7.555], ['rate' => 'abc'], ['label' => '']] as $bad) {
            api($admin)->putJson('/api/admin/settings/tax', [...['label' => 'VAT', 'rate' => 15, 'inclusive' => false], ...$bad])->assertStatus(422);
        }
    });

    it('needs settings.view to read and settings.edit to change', function () {
        api(staffWith(['products.view']))->getJson('/api/admin/settings/tax')->assertStatus(403);
        api(staffWith(['settings.view']))->putJson('/api/admin/settings/tax', ['label' => 'VAT', 'rate' => 10, 'inclusive' => false])->assertStatus(403);
    });

    it('needs a sign-in', function () {
        test()->getJson('/api/admin/settings/tax')->assertStatus(401);
    });

    it('writes an activity log entry with the old and the new tax', function () {
        api(staffWith(['settings.edit']))->putJson('/api/admin/settings/tax', ['label' => 'VAT', 'rate' => 10, 'inclusive' => false])->assertOk();

        $log = ActivityLog::where('action', 'settings.tax_updated')->sole();
        expect($log->properties['before']['rate'])->toEqual(15)->and($log->properties['after']['rate'])->toEqual(10);
    });
});
