<?php

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\DomainExtension;
use App\Services\DomainPricing;
use App\Services\Registrar\Registrar;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Cache::flush();
});

function syncStaff(): Admin
{
    return staffWith(['domains.view', 'domains.create', 'domains.edit', 'domains.delete']);
}

function priceList(): array
{
    return [
        ['extension' => '.com', 'usage' => 'General Websites', 'restrictions' => null, 'cost_register' => 1088, 'cost_renew' => 1088, 'cost_transfer' => 1088],
        ['extension' => '.xyz', 'usage' => 'General Websites', 'restrictions' => null, 'cost_register' => 199, 'cost_renew' => 1445, 'cost_transfer' => 1445],
        ['extension' => '.lv', 'usage' => 'Latvia', 'restrictions' => 'A person with a domicile in Latvia', 'cost_register' => 500, 'cost_renew' => 500, 'cost_transfer' => 500],
    ];
}

/** A registrar that answers from fixed lists. */
function stubRegistrar(array $list = [], array $availability = []): object
{
    $stub = new class($list, $availability) implements Registrar
    {
        public function __construct(private array $list, private array $availability) {}

        public function extensions(): array
        {
            return $this->list;
        }

        public function search(array $domains): array
        {
            return array_combine($domains, array_map(fn ($d) => $this->availability[$d] ?? false, $domains));
        }
    };
    app()->instance(Registrar::class, $stub);

    return $stub;
}

function extension(string $extension, array $over = []): DomainExtension
{
    return DomainExtension::create(['extension' => $extension, 'is_active' => true, 'sort_order' => 1, ...$over]);
}

function setRule(float $rate = 125, float $margin = 30, int $roundTo = 10): void
{
    api(syncStaff())->putJson('/api/admin/domain-pricing', ['usdRate' => $rate, 'marginPercent' => $margin, 'roundTo' => $roundTo])->assertOk();
}

describe('syncing the extensions from the registrar', function () {
    it('adds the new ones switched off, and only updates the cost of the ones already here', function () {
        extension('.com', ['tag' => 'Popular', 'is_featured' => true, 'register_price' => 99900]);
        stubRegistrar(priceList());

        api(syncStaff())->postJson('/api/admin/domain-extensions/sync')->assertOk()->assertJsonPath('data', ['added' => 2, 'updated' => 1, 'total' => 3]);

        $com = DomainExtension::where('extension', '.com')->sole();
        $xyz = DomainExtension::where('extension', '.xyz')->sole();
        expect($com->cost_register)->toBe(1088)->and($com->register_price)->toBe(99900)->and($com->tag)->toBe('Popular')->and($com->is_active)->toBeTrue()->and($com->is_featured)->toBeTrue()
            ->and($xyz->is_active)->toBeFalse()->and($xyz->register_price)->toBeNull()->and($xyz->cost_register)->toBe(199)->and($xyz->cost_renew)->toBe(1445)
            ->and(DomainExtension::where('extension', '.lv')->value('restrictions'))->toBe('A person with a domicile in Latvia');

        api(syncStaff())->postJson('/api/admin/domain-extensions/sync')->assertOk()->assertJsonPath('data', ['added' => 0, 'updated' => 3, 'total' => 3]);
        expect(ActivityLog::where('action', 'domain_extension.synced')->count())->toBe(2);
    });

    it('is refused without domains.edit', function () {
        api(staffWith(['domains.view']))->postJson('/api/admin/domain-extensions/sync')->assertStatus(403);
    });

    it('answers 503 without a Dynadot key', function () {
        config(['services.dynadot.key' => null]);
        app()->forgetInstance(Registrar::class);

        api(syncStaff())->postJson('/api/admin/domain-extensions/sync')->assertStatus(503)->assertJsonPath('code', 'registrar_not_configured');
    });

    it('reads the Dynadot price list', function () {
        config(['services.dynadot.key' => 'k']);
        app()->forgetInstance(Registrar::class);
        Http::fake(['api.dynadot.com/*' => Http::response(['TldPriceResponse' => ['ResponseCode' => 0, 'Status' => 'success', 'TldPrice' => [
            ['Tld' => '.com', 'Usage' => 'General Websites', 'Restrictions' => '--', 'Price' => ['Register' => '10.88', 'Renew' => '10.88', 'Transfer' => '10.88']],
            ['Tld' => '.lv', 'Usage' => 'Latvia', 'Restrictions' => 'Latvian residents', 'Price' => ['Register' => '5.00', 'Renew' => '--', 'Transfer' => '5']],
            ['Tld' => 'bad name', 'Price' => ['Register' => '1']],
        ]]])]);

        $list = app(Registrar::class)->extensions();

        expect($list)->toHaveCount(2)->and($list[0])->toBe(['extension' => '.com', 'usage' => 'General Websites', 'restrictions' => null, 'cost_register' => 1088, 'cost_renew' => 1088, 'cost_transfer' => 1088])
            ->and($list[1]['restrictions'])->toBe('Latvian residents')->and($list[1]['cost_renew'])->toBeNull()->and($list[1]['cost_transfer'])->toBe(500);
        Http::assertSent(fn ($r) => $r['command'] === 'tld_price' && $r['key'] === 'k');
    });

    it('answers 502 when Dynadot refuses, without showing the key', function () {
        config(['services.dynadot.key' => 'secret-key']);
        app()->forgetInstance(Registrar::class);
        Http::fake(['api.dynadot.com/*' => Http::response(['TldPriceResponse' => ['ResponseCode' => -1, 'Status' => 'error', 'Error' => 'invalid key']])]);

        $response = api(syncStaff())->postJson('/api/admin/domain-extensions/sync')->assertStatus(502)->assertJsonPath('code', 'registrar_unavailable');
        expect($response->getContent())->not->toContain('secret-key')->not->toContain('invalid key');
    });
});

describe('the pricing rule', function () {
    it('has a default and can be changed', function () {
        api(staffWith(['domains.view']))->getJson('/api/admin/domain-pricing')->assertOk()->assertJsonPath('data', ['usdRate' => null, 'marginPercent' => 30, 'roundTo' => 10]);

        api(syncStaff())->putJson('/api/admin/domain-pricing', ['usdRate' => 125.5, 'marginPercent' => 25, 'roundTo' => 50])->assertOk()->assertJsonPath('data', ['usdRate' => 125.5, 'marginPercent' => 25, 'roundTo' => 50]);
        expect(ActivityLog::where('action', 'settings.domain_pricing_updated')->count())->toBe(1);
    });

    it('refuses a rate that makes no sense', function (array $body, string $field) {
        api(syncStaff())->putJson('/api/admin/domain-pricing', [...['usdRate' => 125, 'marginPercent' => 30, 'roundTo' => 10], ...$body])->assertStatus(422)->assertJsonValidationErrors($field);
    })->with([
        [['usdRate' => 0], 'usdRate'],
        [['usdRate' => -3], 'usdRate'],
        [['usdRate' => 'abc'], 'usdRate'],
        [['marginPercent' => -1], 'marginPercent'],
        [['marginPercent' => 1001], 'marginPercent'],
        [['roundTo' => 0], 'roundTo'],
    ]);

    it('is read with domains.view and changed with domains.edit', function () {
        api(staffWith(['tickets.view']))->getJson('/api/admin/domain-pricing')->assertStatus(403);
        api(staffWith(['domains.view']))->putJson('/api/admin/domain-pricing', ['usdRate' => 125, 'marginPercent' => 30, 'roundTo' => 10])->assertStatus(403);
    });

    it('works a price out from the cost: cost x rate x (1 + margin), rounded up', function () {
        setRule(125, 30, 10);

        // $10.88 x 125 = 1360 x 1.3 = 1768 taka, up to the next 10 = 1770 taka
        expect(app(DomainPricing::class)->fromCost(1088))->toBe(177000)->and(app(DomainPricing::class)->fromCost(null))->toBeNull()->and(app(DomainPricing::class)->fromCost(0))->toBeNull();
    });

    it('prices an extension nobody priced, in the public list and the search, and a typed price wins', function () {
        extension('.com', ['register_price' => 99900, 'cost_register' => 1088]);
        extension('.xyz', ['cost_register' => 199, 'cost_renew' => 1445, 'sort_order' => 2]);
        extension('.new', ['sort_order' => 3]);
        setRule(125, 30, 10);
        stubRegistrar([], ['mybrand.xyz' => true]);

        $list = collect(test()->getJson('/api/domains/extensions')->json('data.extensions'))->keyBy('extension');

        // $1.99 x 125 x 1.3 = 323.4 -> 330 taka = 33000 paisa; renew $14.45 -> 2348.1 -> 2350 taka
        expect($list['.com']['register']['net'])->toBe(99900)->and($list['.xyz']['register']['net'])->toBe(33000)->and($list['.xyz']['renew']['net'])->toBe(235000)
            ->and($list['.xyz']['transfer'])->toBeNull()->and($list['.new']['register'])->toBeNull();

        test()->getJson('/api/domains/search?domains=mybrand.xyz')->assertOk()->assertJsonPath('data.results.0.price.net', 33000);
    });

    it('without a rate the cost alone prices nothing', function () {
        extension('.xyz', ['cost_register' => 199]);

        expect(test()->getJson('/api/domains/extensions')->json('data.extensions.0.register'))->toBeNull();
    });
});

function manyExtensions(): void
{
    foreach (range(1, 60) as $i) {
        DomainExtension::create(['extension' => ".ext{$i}", 'is_active' => false, 'cost_register' => 500, 'sort_order' => $i]);
    }
    extension('.com', ['is_featured' => true, 'sort_order' => 100]);
    DomainExtension::create(['extension' => '.lv', 'is_active' => false, 'restrictions' => 'Latvia only', 'cost_register' => 500, 'sort_order' => 101]);
}

describe('admin: the long list', function () {
    it('is paged, 50 at a time, popular and on sale first, with counts', function () {
        manyExtensions();

        $page = api(staffWith(['domains.view']))->getJson('/api/admin/domain-extensions')->assertOk();
        expect($page->json('data'))->toHaveCount(50)->and($page->json('data.0.extension'))->toBe('.com')->and($page->json('meta.total'))->toBe(62)->and($page->json('meta.last_page'))->toBe(2)
            ->and($page->json('meta.counts'))->toBe(['all' => 62, 'on' => 1, 'off' => 61, 'no_price' => 62, 'popular' => 1]);
        expect(api(staffWith(['domains.view']))->getJson('/api/admin/domain-extensions?page=2')->json('data'))->toHaveCount(12);
    });

    it('searches, ignoring the dot and the case, and filters', function () {
        manyExtensions();
        $admin = staffWith(['domains.view']);

        expect(array_column(api($admin)->getJson('/api/admin/domain-extensions?search=.COM')->json('data'), 'extension'))->toBe(['.com'])
            ->and(api($admin)->getJson('/api/admin/domain-extensions?search=ext1')->json('meta.total'))->toBe(11)
            ->and(api($admin)->getJson('/api/admin/domain-extensions?status=on')->json('meta.total'))->toBe(1)
            ->and(api($admin)->getJson('/api/admin/domain-extensions?status=popular')->json('meta.total'))->toBe(1)
            ->and(api($admin)->getJson('/api/admin/domain-extensions?status=off')->json('meta.total'))->toBe(61);
    });

    it('refuses an unknown status', function () {
        api(staffWith(['domains.view']))->getJson('/api/admin/domain-extensions?status=bogus')->assertStatus(422);
    });

    it('says which have no price at all, once a rate is set', function () {
        DomainExtension::create(['extension' => '.nocost', 'is_active' => false, 'sort_order' => 1]);
        DomainExtension::create(['extension' => '.hascost', 'is_active' => false, 'cost_register' => 500, 'sort_order' => 2]);
        $admin = syncStaff();

        // no rate yet: a cost alone is no price
        expect(api($admin)->getJson('/api/admin/domain-extensions?status=no_price')->json('meta.total'))->toBe(2);
        setRule(125, 30, 10);
        expect(array_column(api($admin)->getJson('/api/admin/domain-extensions?status=no_price')->json('data'), 'extension'))->toBe(['.nocost']);

        $hascost = collect(api($admin)->getJson('/api/admin/domain-extensions')->json('data'))->firstWhere('extension', '.hascost');
        // $5.00 x 125 x 1.3 = 812.5 -> 820 taka
        expect($hascost['priceSource'])->toBe('auto')->and($hascost['effectiveRegisterPrice'])->toBe(82000)->and($hascost['registerPrice'])->toBeNull()->and($hascost['costRegister'])->toBe(500);
    });

    it('switches on everything that has a price and no restriction', function () {
        DomainExtension::create(['extension' => '.priced', 'is_active' => false, 'register_price' => 100000, 'sort_order' => 1]);
        DomainExtension::create(['extension' => '.cost', 'is_active' => false, 'cost_register' => 500, 'sort_order' => 2]);
        DomainExtension::create(['extension' => '.none', 'is_active' => false, 'sort_order' => 3]);
        DomainExtension::create(['extension' => '.lv', 'is_active' => false, 'cost_register' => 500, 'restrictions' => 'Latvia only', 'sort_order' => 4]);
        $admin = syncStaff();

        // no rate: only the typed price counts
        api($admin)->postJson('/api/admin/domain-extensions/activate-priced')->assertOk()->assertJsonPath('data.activated', 1);
        setRule(125, 30, 10);
        api($admin)->postJson('/api/admin/domain-extensions/activate-priced')->assertOk()->assertJsonPath('data.activated', 1);

        expect(DomainExtension::where('is_active', true)->pluck('extension')->sort()->values()->all())->toBe(['.cost', '.priced']);
    });

    it('needs domains.edit to switch them on', function () {
        api(staffWith(['domains.view']))->postJson('/api/admin/domain-extensions/activate-priced')->assertStatus(403);
    });
});
