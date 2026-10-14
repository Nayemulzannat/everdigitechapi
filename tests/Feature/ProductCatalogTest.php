<?php

use App\Enums\BillingCycle;
use App\Exceptions\ApiException;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductPrice;
use App\Services\Pricing;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    config(['everdigitech.tax.rate' => 15.0, 'everdigitech.tax.inclusive' => false, 'everdigitech.tax.label' => 'VAT']);
});

function catalogStaff(array $extra = []): Admin
{
    return staffWith(['products.view', 'products.create', 'products.edit', 'products.delete', ...$extra]);
}

function makeGroup(string $name = 'Web Hosting', string $type = 'hosting', array $over = []): ProductGroup
{
    return ProductGroup::create(['name' => $name, 'slug' => str($name)->slug()->toString(), 'type' => $type, 'sort_order' => 1, ...$over]);
}

function makeProduct(ProductGroup $group, string $name = 'Business', array $prices = ['monthly' => 89900, 'annually' => 899000], array $over = []): Product
{
    $product = Product::create(['product_group_id' => $group->id, 'name' => $name, 'slug' => str($name.' '.$group->id)->slug()->toString(), ...$over]);
    foreach ($prices as $cycle => $amount) {
        $product->prices()->create(['cycle' => $cycle, 'amount' => $amount]);
    }

    return $product;
}

describe('Pricing: tax and quotes', function () {
    it('adds tax on top when prices are before tax', function () {
        expect(app(Pricing::class)->breakdown(89900))->toBe(['net' => 89900, 'tax' => 13485, 'total' => 103385]);
        expect(app(Pricing::class)->breakdown(0))->toBe(['net' => 0, 'tax' => 0, 'total' => 0]);
    });

    it('finds the tax inside a price when prices already contain it', function () {
        config(['everdigitech.tax.inclusive' => true]);

        expect(app(Pricing::class)->breakdown(115000))->toBe(['net' => 100000, 'tax' => 15000, 'total' => 115000]);
        $odd = app(Pricing::class)->breakdown(89900);
        expect($odd['net'] + $odd['tax'])->toBe(89900)->and($odd['total'])->toBe(89900);
    });

    it('rounds half a paisa up, and the parts always add up', function (int $amount) {
        $b = app(Pricing::class)->breakdown($amount);

        expect($b['net'] + $b['tax'])->toBe($b['total']);
    })->with([1, 3, 7, 33, 99, 12345, 89901, 1_000_001]);

    it('rounds .5 up', function () {
        config(['everdigitech.tax.rate' => 10.0]);

        expect(app(Pricing::class)->breakdown(15)['tax'])->toBe(2); // 1.5 -> 2
    });

    it('can have no tax at all', function () {
        config(['everdigitech.tax.rate' => 0.0]);

        expect(app(Pricing::class)->breakdown(50000))->toBe(['net' => 50000, 'tax' => 0, 'total' => 50000]);
    });

    it('quotes a product with a cycle: the price, the setup fee and what is due now', function () {
        $product = makeProduct(makeGroup(), 'Business', ['monthly' => 100000, 'annually' => 1000000], ['setup_fee' => 50000]);

        $quote = app(Pricing::class)->quote($product, BillingCycle::Annually);

        expect($quote)->toBe([
            'cycle' => 'annually', 'months' => 12,
            'price' => ['net' => 1000000, 'tax' => 150000, 'total' => 1150000],
            'setup_fee' => ['net' => 50000, 'tax' => 7500, 'total' => 57500],
            'due_now' => ['net' => 1050000, 'tax' => 157500, 'total' => 1207500],
        ]);
    });

    it('refuses a cycle the product is not sold with', function () {
        $product = makeProduct(makeGroup(), 'Business', ['monthly' => 100000]);

        expect(fn () => app(Pricing::class)->quote($product, BillingCycle::Annually))->toThrow(ApiException::class, 'not sold with that billing cycle');
    });

    it('knows the length of each cycle', function () {
        expect(collect(BillingCycle::cases())->mapWithKeys(fn ($c) => [$c->value => $c->months()])->all())
            ->toBe(['monthly' => 1, 'quarterly' => 3, 'semiannually' => 6, 'annually' => 12, 'biennially' => 24, 'triennially' => 36, 'one_time' => null]);
    });
});

describe('product groups', function () {
    it('lists every group, hidden ones too, in order, with the number of products', function () {
        $hosting = makeGroup('Web Hosting', 'hosting', ['sort_order' => 2]);
        makeGroup('Domains', 'domain', ['sort_order' => 1, 'is_active' => false]);
        makeProduct($hosting);

        $data = api(catalogStaff())->getJson('/api/admin/product-groups')->assertOk()->json('data');

        expect(collect($data)->pluck('name')->all())->toBe(['Domains', 'Web Hosting'])
            ->and(array_keys($data[0]))->toBe(['id', 'name', 'slug', 'type', 'typeLabel', 'description', 'sortOrder', 'isActive', 'productsCount'])
            ->and($data[0])->toMatchArray(['isActive' => false, 'type' => 'domain', 'typeLabel' => 'Domains', 'productsCount' => 0])
            ->and($data[1]['productsCount'])->toBe(1);
    });

    it('adds a group with a slug made from its name, at the end', function () {
        makeGroup('Web Hosting');

        api(catalogStaff())->postJson('/api/admin/product-groups', ['name' => '  VPS Servers ', 'type' => 'server', 'description' => 'Virtual servers.'])
            ->assertCreated()->assertJsonPath('data.name', 'VPS Servers')->assertJsonPath('data.slug', 'vps-servers')->assertJsonPath('data.sortOrder', 2)->assertJsonPath('data.isActive', true);

        expect(ActivityLog::where('action', 'product_group.created')->count())->toBe(1);
    });

    it('refuses bad input', function (array $body, string $field) {
        makeGroup('Web Hosting');

        api(catalogStaff())->postJson('/api/admin/product-groups', $body)->assertStatus(422)->assertJsonValidationErrors($field);

        expect(ProductGroup::count())->toBe(1);
    })->with([
        'no name' => [['type' => 'hosting'], 'name'],
        'a name that exists' => [['name' => 'web hosting', 'type' => 'hosting'], 'name'],
        'name too long' => [['name' => str_repeat('a', 101), 'type' => 'hosting'], 'name'],
        'no type' => [['name' => 'New'], 'type'],
        'an unknown type' => [['name' => 'New', 'type' => 'spaceship'], 'type'],
        'a negative order' => [['name' => 'New', 'type' => 'hosting', 'sortOrder' => -1], 'sortOrder'],
    ]);

    it('changes a group, and a rename keeps its address', function () {
        $group = makeGroup('Web Hosting');

        api(catalogStaff())->putJson("/api/admin/product-groups/{$group->id}", ['name' => 'Shared Hosting', 'isActive' => false, 'sortOrder' => 9])
            ->assertOk()->assertJsonPath('data.name', 'Shared Hosting')->assertJsonPath('data.slug', 'web-hosting')->assertJsonPath('data.isActive', false)->assertJsonPath('data.sortOrder', 9);
    });

    it('deletes an empty group, and refuses one that has products, even deleted ones', function () {
        $empty = makeGroup('Empty');
        $used = makeGroup('Used');
        $product = makeProduct($used);
        $product->delete();

        api(catalogStaff())->deleteJson("/api/admin/product-groups/{$empty->id}")->assertOk();
        api(catalogStaff())->deleteJson("/api/admin/product-groups/{$used->id}")->assertStatus(422)->assertJsonPath('code', 'group_in_use');

        expect(ProductGroup::pluck('name')->all())->toBe(['Used']);
        api(catalogStaff())->deleteJson('/api/admin/product-groups/99999')->assertStatus(404);
    });

    it('needs the right permission for each action', function () {
        $group = makeGroup();
        $reader = staffWith(['products.view']);

        api($reader)->getJson('/api/admin/product-groups')->assertOk();
        api($reader)->postJson('/api/admin/product-groups', ['name' => 'X', 'type' => 'hosting'])->assertStatus(403);
        api($reader)->putJson("/api/admin/product-groups/{$group->id}", ['name' => 'X'])->assertStatus(403);
        api($reader)->deleteJson("/api/admin/product-groups/{$group->id}")->assertStatus(403);
        api(staffWith(['clients.view']))->getJson('/api/admin/product-groups')->assertStatus(403);
    });
});

describe('POST /api/admin/products', function () {
    it('adds a product with its prices, features and setup fee', function () {
        $group = makeGroup();

        $response = api(catalogStaff())->postJson('/api/admin/products', [
            'groupId' => $group->id, 'name' => '  Turbo Business ', 'tagline' => 'For growing sites', 'description' => 'Long text.', 'features' => ['10 GB SSD', '  ', 'Free SSL', ''],
            'setupFee' => 50000, 'isFeatured' => true,
            'prices' => [['cycle' => 'annually', 'amount' => 899000], ['cycle' => 'monthly', 'amount' => 89900]],
        ])->assertCreated();

        $data = $response->json('data');
        expect($data)->toMatchArray(['name' => 'Turbo Business', 'slug' => 'turbo-business', 'groupName' => 'Web Hosting', 'tagline' => 'For growing sites', 'setupFee' => 50000, 'isActive' => true, 'isFeatured' => true])
            ->and($data['features'])->toBe(['10 GB SSD', 'Free SSL'])
            ->and($data['prices'])->toBe([
                ['cycle' => 'monthly', 'label' => 'Monthly', 'months' => 1, 'amount' => 89900, 'compareAmount' => null],
                ['cycle' => 'annually', 'label' => 'Yearly', 'months' => 12, 'amount' => 899000, 'compareAmount' => null],
            ]);
        expect(ProductPrice::count())->toBe(2)->and(ActivityLog::where('action', 'product.created')->count())->toBe(1);
    });

    it('can be added switched off and without prices', function () {
        $group = makeGroup();

        api(catalogStaff())->postJson('/api/admin/products', ['groupId' => $group->id, 'name' => 'Draft', 'isActive' => false])->assertCreated()->assertJsonPath('data.prices', []);
    });

    it('needs at least one price when clients can buy it', function () {
        $group = makeGroup();

        api(catalogStaff())->postJson('/api/admin/products', ['groupId' => $group->id, 'name' => 'No price'])->assertStatus(422)->assertJsonValidationErrors('prices');
        api(catalogStaff())->postJson('/api/admin/products', ['groupId' => $group->id, 'name' => 'No price', 'prices' => []])->assertStatus(422)->assertJsonValidationErrors('prices');
        expect(Product::count())->toBe(0);
    });

    it('refuses bad input', function (array $body, string $field) {
        $group = makeGroup();
        makeProduct($group, 'Existing');
        $body = ['groupId' => $group->id, 'name' => 'New', 'prices' => [['cycle' => 'monthly', 'amount' => 1000]], ...$body];

        api(catalogStaff())->postJson('/api/admin/products', $body)->assertStatus(422)->assertJsonValidationErrors($field);

        expect(Product::count())->toBe(1);
    })->with([
        'a name that exists in the group' => [['name' => 'existing'], 'name'],
        'an empty name' => [['name' => '  '], 'name'],
        'name too long' => [['name' => str_repeat('a', 151)], 'name'],
        'an unknown group' => [['groupId' => 99999], 'groupId'],
        'tagline too long' => [['tagline' => str_repeat('a', 201)], 'tagline'],
        'a negative setup fee' => [['setupFee' => -1], 'setupFee'],
        'a setup fee that is not a number' => [['setupFee' => 'free'], 'setupFee'],
        'too many features' => [['features' => array_fill(0, 21, 'x')], 'features'],
        'a feature that is too long' => [['features' => [str_repeat('a', 121)]], 'features.0'],
        'an unknown cycle' => [['prices' => [['cycle' => 'weekly', 'amount' => 1000]]], 'prices.0.cycle'],
        'a price of zero' => [['prices' => [['cycle' => 'monthly', 'amount' => 0]]], 'prices.0.amount'],
        'a price that is not a whole number' => [['prices' => [['cycle' => 'monthly', 'amount' => 99.5]]], 'prices.0.amount'],
        'a price that is far too large' => [['prices' => [['cycle' => 'monthly', 'amount' => 999_999_999_999]]], 'prices.0.amount'],
        'a cycle priced twice' => [['prices' => [['cycle' => 'monthly', 'amount' => 1000], ['cycle' => 'monthly', 'amount' => 2000]]], 'prices.0.cycle'],
    ]);

    it('allows the same name in another group, and makes slugs that never clash', function () {
        $hosting = makeGroup('Web Hosting');
        $vps = makeGroup('VPS', 'server');
        $prices = [['cycle' => 'monthly', 'amount' => 1000]];

        $a = api(catalogStaff())->postJson('/api/admin/products', ['groupId' => $hosting->id, 'name' => 'Starter', 'prices' => $prices])->assertCreated()->json('data.slug');
        $b = api(catalogStaff())->postJson('/api/admin/products', ['groupId' => $vps->id, 'name' => 'Starter', 'prices' => $prices])->assertCreated()->json('data.slug');

        expect($a)->toBe('starter')->and($b)->toBe('starter-2');
    });

    it('needs products.create', function () {
        $group = makeGroup();

        api(staffWith(['products.view', 'products.edit']))->postJson('/api/admin/products', ['groupId' => $group->id, 'name' => 'X', 'isActive' => false])->assertStatus(403);
    });
});

describe('PUT /api/admin/products/{id}', function () {
    it('changes fields and leaves the rest, and the prices when none are sent', function () {
        $product = makeProduct(makeGroup(), 'Business', ['monthly' => 89900], ['tagline' => 'Old']);

        api(catalogStaff())->putJson("/api/admin/products/{$product->id}", ['name' => 'Business Plus', 'tagline' => 'New', 'isFeatured' => true])
            ->assertOk()->assertJsonPath('data.name', 'Business Plus')->assertJsonPath('data.tagline', 'New')->assertJsonPath('data.isFeatured', true)->assertJsonPath('data.slug', $product->slug)
            ->assertJsonPath('data.prices.0.amount', 89900);
    });

    it('replaces the prices when they are sent: new ones added, changed ones updated, missing ones removed', function () {
        $product = makeProduct(makeGroup(), 'Business', ['monthly' => 89900, 'annually' => 899000]);

        $data = api(catalogStaff())->putJson("/api/admin/products/{$product->id}", ['prices' => [['cycle' => 'annually', 'amount' => 850000], ['cycle' => 'biennially', 'amount' => 1600000]]])
            ->assertOk()->json('data');

        expect(collect($data['prices'])->pluck('amount', 'cycle')->all())->toBe(['annually' => 850000, 'biennially' => 1600000]);
        expect(ProductPrice::where('product_id', $product->id)->count())->toBe(2);
    });

    it('moves a product to another group', function () {
        $product = makeProduct(makeGroup('Web Hosting'));
        $vps = makeGroup('VPS', 'server');

        api(catalogStaff())->putJson("/api/admin/products/{$product->id}", ['groupId' => $vps->id])->assertOk()->assertJsonPath('data.groupName', 'VPS');
    });

    it('can keep its own name, but not take another name of its group', function () {
        $group = makeGroup();
        $a = makeProduct($group, 'Alpha');
        makeProduct($group, 'Beta');

        api(catalogStaff())->putJson("/api/admin/products/{$a->id}", ['name' => 'Alpha', 'tagline' => 'Same name'])->assertOk();
        api(catalogStaff())->putJson("/api/admin/products/{$a->id}", ['name' => 'beta'])->assertStatus(422)->assertJsonValidationErrors('name');
    });

    it('will not switch a product on while it has no price, but may switch it off', function () {
        $draft = makeProduct(makeGroup(), 'Draft', [], ['is_active' => false]);
        $live = makeProduct(makeGroup('Other', 'service'), 'Live', ['monthly' => 1000]);

        api(catalogStaff())->putJson("/api/admin/products/{$draft->id}", ['isActive' => true])->assertStatus(422)->assertJsonValidationErrors('prices');
        api(catalogStaff())->putJson("/api/admin/products/{$draft->id}", ['isActive' => true, 'prices' => [['cycle' => 'monthly', 'amount' => 5000]]])->assertOk();
        api(catalogStaff())->putJson("/api/admin/products/{$live->id}", ['isActive' => false, 'prices' => []])->assertOk()->assertJsonPath('data.prices', []);
        api(catalogStaff())->putJson("/api/admin/products/{$live->id}", ['prices' => []])->assertOk(); // off already
    });

    it('will not remove all prices from a product that stays on', function () {
        $product = makeProduct(makeGroup(), 'Live', ['monthly' => 1000]);

        api(catalogStaff())->putJson("/api/admin/products/{$product->id}", ['prices' => []])->assertStatus(422)->assertJsonValidationErrors('prices');

        expect(ProductPrice::where('product_id', $product->id)->count())->toBe(1);
    });

    it('answers 404 for a product that does not exist or was deleted, and needs products.edit', function () {
        $product = makeProduct(makeGroup());

        api(catalogStaff())->putJson('/api/admin/products/99999', ['name' => 'X'])->assertStatus(404);
        api(staffWith(['products.view', 'products.create']))->putJson("/api/admin/products/{$product->id}", ['name' => 'X'])->assertStatus(403);
        $product->delete();
        api(catalogStaff())->putJson("/api/admin/products/{$product->id}", ['name' => 'X'])->assertStatus(404);
    });
});

describe('GET /api/admin/products', function () {
    it('lists products by group, with prices, filters and counts', function () {
        $hosting = makeGroup('Web Hosting', 'hosting', ['sort_order' => 1]);
        $vps = makeGroup('VPS', 'server', ['sort_order' => 2]);
        makeProduct($hosting, 'Starter', ['monthly' => 50000], ['sort_order' => 1, 'tagline' => 'Tiny sites']);
        makeProduct($hosting, 'Business', ['monthly' => 89900], ['sort_order' => 2]);
        makeProduct($vps, 'Cloud One', ['monthly' => 150000], ['is_active' => false]);
        $names = fn (string $q) => collect(api(catalogStaff())->getJson("/api/admin/products{$q}")->assertOk()->json('data'))->pluck('name')->all();

        expect($names(''))->toBe(['Starter', 'Business', 'Cloud One'])
            ->and($names('?status=inactive'))->toBe(['Cloud One'])->and($names('?status=active'))->toBe(['Starter', 'Business'])
            ->and($names('?group='.$vps->id))->toBe(['Cloud One'])->and($names('?search=tiny'))->toBe(['Starter'])->and($names('?search=cloud'))->toBe(['Cloud One']);

        $response = api(catalogStaff())->getJson('/api/admin/products')->assertOk();
        expect($response->json('meta.counts'))->toBe(['all' => 3, 'active' => 2, 'inactive' => 1])->and($response->json('meta.total'))->toBe(3)
            ->and(api(catalogStaff())->getJson('/api/admin/products?group='.$hosting->id)->json('meta.counts'))->toBe(['all' => 2, 'active' => 2, 'inactive' => 0]);
    });

    it('does not list deleted products, and paginates 20 to a page', function () {
        $group = makeGroup();
        foreach (range(1, 22) as $i) {
            makeProduct($group, "Plan {$i}", ['monthly' => 1000], ['sort_order' => $i]);
        }
        Product::where('name', 'Plan 1')->first()->delete();

        api(catalogStaff())->getJson('/api/admin/products')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 21)->assertJsonPath('meta.last_page', 2);
        api(catalogStaff())->getJson('/api/admin/products?page=2')->assertJsonCount(1, 'data');
    });

    it('rejects a bad status', function () {
        api(catalogStaff())->getJson('/api/admin/products?status=nope')->assertStatus(422);
    });

    it('shows one product, and gives the form its options', function () {
        $product = makeProduct(makeGroup());

        api(catalogStaff())->getJson("/api/admin/products/{$product->id}")->assertOk()->assertJsonPath('data.name', 'Business');
        api(catalogStaff())->getJson('/api/admin/products/99999')->assertStatus(404);

        $options = api(catalogStaff())->getJson('/api/admin/products/options')->assertOk()->json('data');
        expect(collect($options['cycles'])->pluck('value')->all())->toBe(['monthly', 'quarterly', 'semiannually', 'annually', 'biennially', 'triennially', 'one_time'])
            ->and($options['cycles'][3])->toBe(['value' => 'annually', 'label' => 'Yearly', 'months' => 12])
            ->and($options['currency'])->toBe('BDT')->and($options['tax'])->toBe(['label' => 'VAT', 'rate' => 15, 'inclusive' => false])
            ->and(collect($options['groups'])->pluck('name')->all())->toBe(['Web Hosting'])
            ->and(collect($options['types'])->pluck('value')->all())->toBe(['hosting', 'server', 'domain', 'sms', 'software', 'service']);
    });
});

describe('DELETE /api/admin/products/{id}', function () {
    it('deletes a product from the catalog but keeps it in the database, and its name can be used again', function () {
        $group = makeGroup();
        $product = makeProduct($group, 'Old plan');

        api(catalogStaff())->deleteJson("/api/admin/products/{$product->id}")->assertOk();

        expect(Product::count())->toBe(0)->and(Product::withTrashed()->count())->toBe(1)->and(ProductPrice::count())->toBe(2);
        api(catalogStaff())->postJson('/api/admin/products', ['groupId' => $group->id, 'name' => 'Old plan', 'prices' => [['cycle' => 'monthly', 'amount' => 1000]]])->assertCreated()->assertJsonPath('data.slug', 'old-plan');

        // A deleted product keeps its address, so an old link or order never points at a different product.
        $again = Product::where('slug', 'old-plan')->sole();
        api(catalogStaff())->deleteJson("/api/admin/products/{$again->id}")->assertOk();
        api(catalogStaff())->postJson('/api/admin/products', ['groupId' => $group->id, 'name' => 'Old plan', 'prices' => [['cycle' => 'monthly', 'amount' => 1000]]])->assertCreated()->assertJsonPath('data.slug', 'old-plan-2');
        expect(ActivityLog::where('action', 'product.deleted')->count())->toBe(2);
    });

    it('needs products.delete', function () {
        $product = makeProduct(makeGroup());

        api(staffWith(['products.view', 'products.edit']))->deleteJson("/api/admin/products/{$product->id}")->assertStatus(403);
        api(catalogStaff())->deleteJson('/api/admin/products/99999')->assertStatus(404);
    });
});

describe('who may use the catalog screens by default', function () {
    it('Super Admin and Admin manage it, Sales can only look, Support and Billing have nothing', function () {
        makeGroup();
        $as = fn (string $role) => tap(Admin::factory()->create())->assignRole($role);

        foreach (['Super Admin', 'Admin'] as $role) {
            api($as($role))->postJson('/api/admin/product-groups', ['name' => "By {$role}", 'type' => 'service'])->assertCreated();
        }
        api($as('Sales'))->getJson('/api/admin/products')->assertOk();
        api($as('Sales'))->postJson('/api/admin/product-groups', ['name' => 'X', 'type' => 'service'])->assertStatus(403);
        foreach (['Support Staff', 'Billing Manager'] as $role) {
            api($as($role))->getJson('/api/admin/products')->assertStatus(403);
        }
    });

    it('is closed to visitors who are not signed in', function (string $method, string $path) {
        test()->json($method, $path)->assertStatus(401);
    })->with([
        ['GET', '/api/admin/product-groups'], ['POST', '/api/admin/product-groups'], ['PUT', '/api/admin/product-groups/1'], ['DELETE', '/api/admin/product-groups/1'],
        ['GET', '/api/admin/products'], ['GET', '/api/admin/products/options'], ['GET', '/api/admin/products/1'], ['POST', '/api/admin/products'],
        ['PUT', '/api/admin/products/1'], ['DELETE', '/api/admin/products/1'],
    ]);
});

describe('GET /api/catalog (public)', function () {
    it('shows groups and products with prices worked out with tax, needing no sign-in', function () {
        $hosting = makeGroup('Web Hosting', 'hosting', ['description' => 'Fast sites.']);
        makeProduct($hosting, 'Business', ['annually' => 1000000, 'monthly' => 100000], ['tagline' => 'For growing sites', 'features' => ['10 GB SSD'], 'setup_fee' => 50000, 'is_featured' => true]);

        $data = test()->getJson('/api/catalog')->assertOk()->json('data');

        expect($data['currency'])->toBe('BDT')->and($data['tax'])->toBe(['label' => 'VAT', 'rate' => 15, 'inclusive' => false])
            ->and($data['groups'])->toHaveCount(1)->and($data['groups'][0])->toMatchArray(['name' => 'Web Hosting', 'slug' => 'web-hosting', 'type' => 'hosting', 'description' => 'Fast sites.']);
        $product = $data['groups'][0]['products'][0];
        expect($product)->toMatchArray(['name' => 'Business', 'tagline' => 'For growing sites', 'features' => ['10 GB SSD'], 'featured' => true])
            ->and($product['setupFee'])->toBe(['net' => 50000, 'tax' => 7500, 'total' => 57500])
            ->and($product['prices'])->toBe([
                ['cycle' => 'monthly', 'label' => 'Monthly', 'months' => 1, 'net' => 100000, 'tax' => 15000, 'total' => 115000, 'compare' => null],
                ['cycle' => 'annually', 'label' => 'Yearly', 'months' => 12, 'net' => 1000000, 'tax' => 150000, 'total' => 1150000, 'compare' => null],
            ])
            ->and(array_keys($product))->toBe(['id', 'name', 'slug', 'tagline', 'description', 'features', 'featureGroups', 'featured', 'setupFee', 'prices']);
    });

    it('follows the tax setting', function () {
        makeProduct(makeGroup(), 'Business', ['monthly' => 115000]);
        config(['everdigitech.tax.inclusive' => true]);

        $price = test()->getJson('/api/catalog')->json('data.groups.0.products.0.prices.0');

        expect($price)->toMatchArray(['net' => 100000, 'tax' => 15000, 'total' => 115000]);
    });

    it('leaves out what is hidden or deleted, and groups with nothing to show', function () {
        $hosting = makeGroup('Web Hosting', 'hosting', ['sort_order' => 1]);
        makeProduct($hosting, 'Shown', ['monthly' => 1000]);
        makeProduct($hosting, 'Off', ['monthly' => 1000], ['is_active' => false]);
        makeProduct($hosting, 'No price', []);
        makeProduct($hosting, 'Deleted', ['monthly' => 1000])->delete();
        $hidden = makeGroup('Hidden group', 'service', ['sort_order' => 2, 'is_active' => false]);
        makeProduct($hidden, 'In a hidden group', ['monthly' => 1000]);
        makeGroup('Empty group', 'service', ['sort_order' => 3]);

        $groups = test()->getJson('/api/catalog')->assertOk()->json('data.groups');

        expect(collect($groups)->pluck('name')->all())->toBe(['Web Hosting'])->and(collect($groups[0]['products'])->pluck('name')->all())->toBe(['Shown']);
    });

    it('keeps the order of the groups and the products', function () {
        $b = makeGroup('B group', 'service', ['sort_order' => 2]);
        $a = makeGroup('A group', 'service', ['sort_order' => 1]);
        makeProduct($a, 'Second', ['monthly' => 1000], ['sort_order' => 2]);
        makeProduct($a, 'First', ['monthly' => 1000], ['sort_order' => 1]);
        makeProduct($b, 'Only', ['monthly' => 1000]);

        $groups = test()->getJson('/api/catalog')->json('data.groups');

        expect(collect($groups)->pluck('name')->all())->toBe(['A group', 'B group'])->and(collect($groups[0]['products'])->pluck('name')->all())->toBe(['First', 'Second']);
    });

    it('can show one group by its slug', function () {
        makeProduct(makeGroup('Web Hosting'), 'Starter', ['monthly' => 1000]);
        makeProduct(makeGroup('VPS', 'server'), 'Cloud', ['monthly' => 1000]);

        $groups = test()->getJson('/api/catalog?group=vps')->assertOk()->json('data.groups');

        expect(collect($groups)->pluck('name')->all())->toBe(['VPS']);
        expect(test()->getJson('/api/catalog?group=nothing')->json('data.groups'))->toBe([]);
    });

    it('never shows internal fields', function () {
        makeProduct(makeGroup(), 'Business', ['monthly' => 1000]);

        $json = test()->getJson('/api/catalog')->getContent();

        expect($json)->not->toContain('created_at')->not->toContain('deleted_at')->not->toContain('sortOrder')->not->toContain('isActive');
    });

    it('is limited to 60 requests a minute', function () {
        foreach (range(1, 60) as $i) {
            test()->getJson('/api/catalog')->assertOk();
        }

        test()->getJson('/api/catalog')->assertStatus(429);
    });
});

describe('feature sections and the regular price', function () {
    it('saves sections and a regular price, and the public catalog shows them', function () {
        $group = makeGroup();
        $id = api(catalogStaff())->postJson('/api/admin/products', [
            'groupId' => $group->id, 'name' => 'Basic', 'features' => ['5 GB NVMe'],
            'featureGroups' => [['title' => 'Server', 'items' => ['1 Core CPU', '', ' 1 GB RAM ']], ['title' => '', 'items' => []]],
            'prices' => [['cycle' => 'annually', 'amount' => 100000, 'compareAmount' => 200000]],
        ])->assertCreated()
            ->assertJsonPath('data.featureGroups', [['title' => 'Server', 'items' => ['1 Core CPU', '1 GB RAM']]])
            ->assertJsonPath('data.prices.0.compareAmount', 200000)->json('data.id');

        $product = test()->getJson('/api/catalog')->assertOk()->json('data.groups.0.products.0');
        expect($product['featureGroups'])->toEqual([['title' => 'Server', 'items' => ['1 Core CPU', '1 GB RAM']]])
            ->and($product['prices'][0]['compare'])->toBe(['net' => 200000, 'tax' => 30000, 'total' => 230000]);

        api(catalogStaff())->putJson("/api/admin/products/{$id}", ['prices' => [['cycle' => 'annually', 'amount' => 100000]]])->assertOk()->assertJsonPath('data.prices.0.compareAmount', null);
    });

    it('refuses a regular price that is not higher than the real one', function () {
        $group = makeGroup();

        api(catalogStaff())->postJson('/api/admin/products', ['groupId' => $group->id, 'name' => 'Basic', 'prices' => [['cycle' => 'annually', 'amount' => 100000, 'compareAmount' => 100000]]])
            ->assertStatus(422)->assertJsonValidationErrors('prices.0.compareAmount');
    });
});
