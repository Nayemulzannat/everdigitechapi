<?php

use App\Models\Admin;
use App\Models\ClientAccount;
use App\Models\Domain;
use App\Models\DomainExtension;
use App\Services\Registrar\Registrar;
use Database\Seeders\DomainExtensionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Cache::flush();
});

function domainStaff(array $extra = []): Admin
{
    return staffWith(['domains.view', 'domains.create', 'domains.edit', 'domains.delete', ...$extra]);
}

function ext(string $extension = '.com', array $over = []): DomainExtension
{
    return DomainExtension::create(['extension' => $extension, 'register_price' => 99900, 'renew_price' => 109900, 'transfer_price' => 89900, 'is_active' => true, 'sort_order' => 1, ...$over]);
}

/** A registrar that answers from a list (domain => available), and counts how often it was asked. */
function fakeRegistrar(array $answers): object
{
    $fake = new class($answers) implements Registrar
    {
        public int $calls = 0;

        public array $asked = [];

        public function __construct(private array $answers) {}

        public function extensions(): array
        {
            return [];
        }

        public function search(array $domains): array
        {
            $this->calls++;
            $this->asked = [...$this->asked, ...$domains];

            return array_combine($domains, array_map(fn ($d) => $this->answers[$d] ?? false, $domains));
        }
    };
    app()->instance(Registrar::class, $fake);

    return $fake;
}

describe('GET /api/domains/extensions (public)', function () {
    it('lists the extensions that are on, in order, with prices worked out with tax', function () {
        ext('.net', ['sort_order' => 2, 'tag' => 'Great for business', 'is_featured' => true]);
        ext('.com', ['sort_order' => 1]);
        ext('.old', ['is_active' => false]);
        ext('.xyz', ['sort_order' => 3, 'register_price' => null, 'renew_price' => null, 'transfer_price' => null]);

        $data = test()->getJson('/api/domains/extensions')->assertOk()->json('data');

        expect(array_column($data['extensions'], 'extension'))->toBe(['.com', '.net', '.xyz'])
            ->and($data['extensions'][0]['register'])->toBe(['net' => 99900, 'tax' => 14985, 'total' => 114885])
            ->and($data['extensions'][0]['renew']['net'])->toBe(109900)
            ->and($data['extensions'][1]['tag'])->toBe('Great for business')->and($data['extensions'][1]['featured'])->toBeTrue()
            ->and($data['extensions'][2]['register'])->toBeNull()
            ->and($data['tax']['rate'])->toEqual(15);
    });
});

describe('GET /api/domains/search (public)', function () {
    it('asks the registrar and answers with availability and our price', function () {
        ext('.com');
        ext('.net', ['register_price' => 119900]);
        $registrar = fakeRegistrar(['mybrand.com' => true, 'mybrand.net' => false]);

        $results = test()->getJson('/api/domains/search?domains=MyBrand.com,mybrand.net')->assertOk()->json('data.results');

        expect($registrar->asked)->toBe(['mybrand.com', 'mybrand.net'])
            ->and($results[0])->toBe(['domain' => 'mybrand.com', 'available' => true, 'extension' => '.com', 'price' => ['net' => 99900, 'tax' => 14985, 'total' => 114885], 'renewal' => ['net' => 109900, 'tax' => 16485, 'total' => 126385]])
            ->and($results[1]['available'])->toBeFalse()->and($results[1]['price']['net'])->toBe(119900);
    });

    it('matches the longest extension, .com.bd before .bd', function () {
        ext('.bd', ['register_price' => 50000]);
        ext('.com.bd', ['register_price' => 149900]);
        fakeRegistrar(['mybrand.com.bd' => true]);

        test()->getJson('/api/domains/search?domains=mybrand.com.bd')->assertOk()->assertJsonPath('data.results.0.extension', '.com.bd')->assertJsonPath('data.results.0.price.net', 149900);
    });

    it('keeps an answer for two minutes, so the registrar is not asked twice', function () {
        ext('.com');
        $registrar = fakeRegistrar(['mybrand.com' => true]);

        test()->getJson('/api/domains/search?domains=mybrand.com')->assertOk();
        test()->getJson('/api/domains/search?domains=mybrand.com')->assertOk()->assertJsonPath('data.results.0.available', true);

        expect($registrar->calls)->toBe(1);
    });

    it('does not check extensions we do not sell, and says so when none is left', function () {
        ext('.com');
        $registrar = fakeRegistrar([]);

        test()->getJson('/api/domains/search?domains=mybrand.com,mybrand.shop')->assertOk()->assertJsonCount(1, 'data.results');
        expect($registrar->asked)->toBe(['mybrand.com']);

        test()->getJson('/api/domains/search?domains=mybrand.shop')->assertStatus(422)->assertJsonValidationErrors('domains');
    });

    it('refuses an empty list and anything that is not a domain name', function (string $query) {
        ext('.com');
        fakeRegistrar([]);

        test()->getJson('/api/domains/search'.$query)->assertStatus(422)->assertJsonValidationErrors('domains');
    })->with(['', '?domains=', '?domains=nodot', '?domains=a.com,../etc/passwd', '?domains=bad_name.com', '?domains=-bad.com']);

    it('checks at most ten names', function () {
        ext('.com');
        $registrar = fakeRegistrar([]);
        $names = implode(',', array_map(fn ($i) => "name{$i}.com", range(1, 14)));

        test()->getJson('/api/domains/search?domains='.$names)->assertOk()->assertJsonCount(10, 'data.results');
        expect($registrar->asked)->toHaveCount(10);
    });

    it('answers 503 when Dynadot has no key, and never shows a guess', function () {
        ext('.com');
        config(['services.dynadot.key' => null]);
        app()->forgetInstance(Registrar::class);

        test()->getJson('/api/domains/search?domains=mybrand.com')->assertStatus(503)->assertJsonPath('code', 'registrar_not_configured');
    });
});

describe('the Dynadot registrar', function () {
    beforeEach(function () {
        config(['services.dynadot.key' => 'secret-key']);
        app()->forgetInstance(Registrar::class);
    });

    it('asks once for each domain, one after the other, with the key', function () {
        Http::fake(['api.dynadot.com/*' => Http::sequence()
            ->push(['SearchResponse' => ['ResponseCode' => '0', 'SearchResults' => [['DomainName' => 'a.com', 'Available' => 'yes']]]])
            ->push(['SearchResponse' => ['ResponseCode' => '0', 'SearchResults' => [['DomainName' => 'b.com', 'Available' => 'no']]]])]);

        expect(app(Registrar::class)->search(['a.com', 'b.com']))->toBe(['a.com' => true, 'b.com' => false]);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request['key'] === 'secret-key' && $request['command'] === 'search' && $request['domain0'] === 'a.com' && ! isset($request['domain1']));
    });

    it('tries again when Dynadot says it is busy', function () {
        Http::fake(['api.dynadot.com/*' => Http::sequence()
            ->push(['Response' => ['ResponseCode' => '-1', 'Error' => 'currently processing another request from this account']])
            ->push(['SearchResponse' => ['ResponseCode' => '0', 'SearchResults' => [['DomainName' => 'a.com', 'Available' => 'yes']]]])]);

        expect(app(Registrar::class)->search(['a.com']))->toBe(['a.com' => true]);
        Http::assertSentCount(2);
    });

    it('answers 502 for a wrong key or an IP address that is not allowed, without showing the key', function () {
        Http::fake(['api.dynadot.com/*' => Http::response(['SearchResponse' => ['ResponseCode' => '-1', 'Status' => 'error', 'Error' => 'invalid key']])]);
        ext('.com');

        $response = test()->getJson('/api/domains/search?domains=a.com')->assertStatus(502)->assertJsonPath('code', 'registrar_unavailable');
        expect($response->getContent())->not->toContain('secret-key')->not->toContain('invalid key');
    });
});

describe('admin: extensions', function () {
    it('lists them all, switched off ones too, for domains.view', function () {
        ext('.com');
        ext('.old', ['is_active' => false, 'sort_order' => 2]);

        $rows = api(staffWith(['domains.view']))->getJson('/api/admin/domain-extensions')->assertOk()->assertJsonCount(2, 'data')->json('data');

        expect($rows[0])->toMatchArray(['id' => (string) DomainExtension::where('extension', '.com')->value('id'), 'extension' => '.com', 'tag' => null, 'registerPrice' => 99900,
            'renewPrice' => 109900, 'transferPrice' => 89900, 'effectiveRegisterPrice' => 99900, 'priceSource' => 'manual', 'isFeatured' => false, 'isActive' => true, 'sortOrder' => 1]);
    });

    it('adds an extension (a dot is added when missing), at the end of the list', function () {
        ext('.com');

        api(domainStaff())->postJson('/api/admin/domain-extensions', ['extension' => 'Shop', 'tag' => ' New ', 'registerPrice' => 150000])->assertCreated()
            ->assertJsonPath('data.extension', '.shop')->assertJsonPath('data.tag', 'New')->assertJsonPath('data.renewPrice', null)->assertJsonPath('data.sortOrder', 2);
    });

    it('refuses a duplicate, a bad extension and a bad price', function (array $body, string $field) {
        ext('.com');

        api(domainStaff())->postJson('/api/admin/domain-extensions', $body)->assertStatus(422)->assertJsonValidationErrors($field);
    })->with([
        [['extension' => '.com'], 'extension'],
        [['extension' => 'a b'], 'extension'],
        [['extension' => '.c'], 'extension'],
        [['extension' => '.shop', 'registerPrice' => 0], 'registerPrice'],
        [['extension' => '.shop', 'registerPrice' => 'abc'], 'registerPrice'],
        [['extension' => '.shop', 'registerPrice' => 100_000_000_000], 'registerPrice'],
    ]);

    it('changes prices and switches off, and a price sent as null is cleared', function () {
        $e = ext('.com');

        api(domainStaff())->putJson("/api/admin/domain-extensions/{$e->id}", ['registerPrice' => 120000, 'transferPrice' => null, 'isActive' => false, 'isFeatured' => true])->assertOk()
            ->assertJsonPath('data.registerPrice', 120000)->assertJsonPath('data.transferPrice', null)->assertJsonPath('data.isActive', false)->assertJsonPath('data.renewPrice', 109900);

        expect(test()->getJson('/api/domains/extensions')->json('data.extensions'))->toBe([]);
    });

    it('deletes one', function () {
        $e = ext('.com');

        api(domainStaff())->deleteJson("/api/admin/domain-extensions/{$e->id}")->assertOk();
        expect(DomainExtension::count())->toBe(0);
        api(domainStaff())->deleteJson("/api/admin/domain-extensions/{$e->id}")->assertStatus(404);
    });

    it('needs domains.view to read, domains.edit to change and domains.delete to delete', function () {
        $e = ext('.com');

        api(staffWith(['tickets.view']))->getJson('/api/admin/domain-extensions')->assertStatus(403);
        api(staffWith(['domains.view']))->postJson('/api/admin/domain-extensions', ['extension' => '.shop'])->assertStatus(403);
        api(staffWith(['domains.view', 'domains.edit']))->putJson("/api/admin/domain-extensions/{$e->id}", ['tag' => 'x'])->assertOk();
        api(staffWith(['domains.view', 'domains.edit']))->deleteJson("/api/admin/domain-extensions/{$e->id}")->assertStatus(403);
    });
});

function newDomain(ClientAccount $account, array $over = []): Domain
{
    return Domain::create(['account_id' => $account->id, 'name' => 'mybrand.com', 'registrar' => 'manual', 'status' => 'active', 'registered_at' => now()->subYear()->toDateString(), 'expires_at' => now()->addDays(100)->toDateString(), ...$over]);
}

describe('admin: domains', function () {
    it('adds a domain by hand for an account, and writes its history', function () {
        $account = clAccountOf(clUser());
        $admin = domainStaff();

        $response = api($admin)->postJson('/api/admin/domains', [
            'accountId' => $account->id, 'name' => ' MyBrand.com ', 'registrar' => 'dynadot', 'status' => 'active', 'registeredAt' => '2026-01-10', 'expiresAt' => '2027-01-10',
            'nameservers' => ['NS1.example.com', '', 'ns2.example.com'], 'notes' => 'Bought by phone',
        ])->assertCreated();

        $response->assertJsonPath('data.name', 'mybrand.com')->assertJsonPath('data.account.id', (string) $account->id)->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.nameservers', ['ns1.example.com', 'ns2.example.com'])->assertJsonPath('data.autoRenew', true)->assertJsonPath('data.locked', true)
            ->assertJsonPath('data.events.0.type', 'created')->assertJsonPath('data.events.0.by', $admin->name);
        expect(Domain::sole()->created_by_admin_id)->toBe($admin->id);
    });

    it('refuses a duplicate, a bad name, a missing or unknown account, and an expiry before registration', function (array $body, string $field) {
        $account = clAccountOf(clUser());
        newDomain($account);

        api(domainStaff())->postJson('/api/admin/domains', [...['accountId' => $account->id, 'name' => 'other.com'], ...$body])->assertStatus(422)->assertJsonValidationErrors($field);
    })->with([
        [['name' => 'mybrand.com'], 'name'],
        [['name' => 'no spaces.com'], 'name'],
        [['name' => 'nodot'], 'name'],
        [['accountId' => 99999], 'accountId'],
        [['status' => 'gone'], 'status'],
        [['registeredAt' => '2026-05-01', 'expiresAt' => '2026-01-01'], 'expiresAt'],
        [['nameservers' => ['bad server']], 'nameservers.0'],
    ]);

    it('lists with counts per status, search by name or account, and the ones ending soon', function () {
        $a = clAccountOf(clUser(['name' => 'Rahim']));
        $b = ClientAccount::factory()->create(['name' => 'Karim Traders']);
        newDomain($a, ['name' => 'soon.com', 'expires_at' => now()->addDays(10)->toDateString()]);
        newDomain($a, ['name' => 'later.com', 'expires_at' => now()->addDays(200)->toDateString()]);
        newDomain($b, ['name' => 'karim.com', 'status' => 'pending', 'expires_at' => null]);
        newDomain($b, ['name' => 'gone.com', 'status' => 'expired', 'expires_at' => now()->subDays(5)->toDateString()]);
        $admin = staffWith(['domains.view']);

        $all = api($admin)->getJson('/api/admin/domains')->assertOk();
        expect($all->json('meta.counts'))->toBe(['all' => 4, 'expiring' => 1, 'pending' => 1, 'active' => 2, 'expired' => 1, 'cancelled' => 0])
            ->and(array_column($all->json('data'), 'name'))->toBe(['gone.com', 'soon.com', 'later.com', 'karim.com']);

        expect(array_column(api($admin)->getJson('/api/admin/domains?status=expiring')->json('data'), 'name'))->toBe(['soon.com'])
            ->and(array_column(api($admin)->getJson('/api/admin/domains?status=pending')->json('data'), 'name'))->toBe(['karim.com'])
            ->and(array_column(api($admin)->getJson('/api/admin/domains?search=Karim')->json('data'), 'name'))->toBe(['gone.com', 'karim.com'])
            ->and(api($admin)->getJson('/api/admin/domains?search=soon')->json('meta.counts.all'))->toBe(1);

        api($admin)->getJson('/api/admin/domains?status=bogus')->assertStatus(422);
    });

    it('shows one with its days left and its history', function () {
        $domain = newDomain(clAccountOf(clUser()), ['expires_at' => now()->addDays(40)->toDateString()]);

        api(staffWith(['domains.view']))->getJson("/api/admin/domains/{$domain->id}")->assertOk()->assertJsonPath('data.daysLeft', 40)->assertJsonPath('data.events', []);
        api(staffWith(['domains.view']))->getJson('/api/admin/domains/99999')->assertStatus(404);
    });

    it('changes only what is sent, and writes the history', function () {
        $domain = newDomain(clAccountOf(clUser()));

        api(domainStaff())->putJson("/api/admin/domains/{$domain->id}", ['expiresAt' => '2030-05-05', 'autoRenew' => false, 'locked' => false])->assertOk()
            ->assertJsonPath('data.expiresAt', '2030-05-05')->assertJsonPath('data.autoRenew', false)->assertJsonPath('data.name', 'mybrand.com')
            ->assertJsonPath('data.events.0.type', 'updated')->assertJsonPath('data.events.0.properties.changed', ['expires_at', 'auto_renew', 'locked']);
    });

    it('removes a domain from the list', function () {
        $domain = newDomain(clAccountOf(clUser()));

        api(domainStaff())->deleteJson("/api/admin/domains/{$domain->id}")->assertOk();
        expect(Domain::count())->toBe(0);
    });

    it('needs the right permissions', function () {
        $domain = newDomain(clAccountOf(clUser()));
        $account = $domain->account_id;

        api(staffWith(['tickets.view']))->getJson('/api/admin/domains')->assertStatus(403);
        api(staffWith(['domains.view']))->postJson('/api/admin/domains', ['accountId' => $account, 'name' => 'x.com'])->assertStatus(403);
        api(staffWith(['domains.view', 'domains.create']))->putJson("/api/admin/domains/{$domain->id}", ['notes' => 'x'])->assertStatus(403);
        api(staffWith(['domains.view', 'domains.edit']))->deleteJson("/api/admin/domains/{$domain->id}")->assertStatus(403);
    });

    it('is closed to visitors', function (string $method, string $path) {
        test()->json($method, $path)->assertStatus(401);
    })->with([['GET', '/api/admin/domains'], ['POST', '/api/admin/domains'], ['GET', '/api/admin/domains/1'], ['GET', '/api/admin/domain-extensions'], ['POST', '/api/admin/domain-extensions']]);
});

describe('the starting list', function () {
    it('puts the extensions of the website into the admin panel, once', function () {
        $this->seed(DomainExtensionSeeder::class);
        DomainExtension::where('extension', '.com')->update(['register_price' => 123400]);
        $this->seed(DomainExtensionSeeder::class);

        expect(DomainExtension::count())->toBe(12)
            ->and(DomainExtension::where('extension', '.com')->value('register_price'))->toBe(123400)
            ->and(DomainExtension::where('extension', '.shop')->value('register_price'))->toBeNull()
            ->and(DomainExtension::where('extension', '.com.bd')->value('register_price'))->toBe(149900);
    });
});
