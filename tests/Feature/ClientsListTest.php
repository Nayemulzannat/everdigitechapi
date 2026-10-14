<?php

use App\Enums\ClientGroup;
use App\Enums\ClientStatus;
use App\Models\Admin;
use App\Models\ClientAccount;
use App\Models\ClientUser;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function clientsViewer(): Admin
{
    return staffWith(['clients.view'], 'Clients Viewer');
}

function client(array $attributes = [], array $owner = []): ClientAccount
{
    static $n = 0;
    // Fixed email and no company unless a test sets them, so a search never matches random faker text.
    $owner += ['email' => 'owner'.++$n.'@host.test'];
    $attributes += ['company' => null];

    return ClientAccount::factory()->state($attributes)->for(ClientUser::factory()->state($owner), 'owner')->create();
}

function clientsJson(string $query = '', ?Admin $as = null)
{
    return api($as ?? clientsViewer())->getJson('/api/admin/clients'.($query ? '?'.$query : ''));
}

function clientNames(string $query = ''): array
{
    return collect(clientsJson($query)->assertOk()->json('data'))->pluck('name')->all();
}

describe('response shape', function () {
    it('matches the frontend Client type and the paginated envelope', function () {
        $account = ClientAccount::factory()
            ->state(['name' => 'Rahim Uddin', 'company' => 'Uddin Traders', 'client_group' => ClientGroup::Reseller])
            ->summary(3, 5, 1450.5, true)
            ->joinedOn('2024-01-12 10:00:00')
            ->for(ClientUser::factory()->state(['email' => 'rahim@example.com']), 'owner')
            ->create();

        $response = clientsJson()->assertOk();

        expect($response->json('data.0'))->toBe([
            'id' => (string) $account->id, 'name' => 'Rahim Uddin', 'email' => 'rahim@example.com', 'company' => 'Uddin Traders',
            'services' => 3, 'domains' => 5, 'balanceDue' => 1450.5, 'overdue' => true,
            'group' => 'reseller', 'status' => 'active', 'joinedOn' => '2024-01-12',
        ]);
        expect(array_keys($response->json('meta')))->toEqualCanonicalizing(['current_page', 'from', 'last_page', 'links', 'path', 'per_page', 'to', 'total', 'counts']);
    });

    it('sends company as null when there is none, and zero money as a number', function () {
        client(['company' => null]);

        $row = clientsJson()->json('data.0');

        expect($row['company'])->toBeNull()->and($row['balanceDue'])->toBe(0)->and($row['overdue'])->toBeFalse();
    });

    it('shows the join date in Bangladesh time, not UTC', function () {
        // 19:00 UTC on the 11th is 01:00 on the 12th in Dhaka.
        ClientAccount::factory()->joinedOn('2024-01-11 19:00:00')->create();

        expect(clientsJson()->json('data.0.joinedOn'))->toBe('2024-01-12');
    });

    it('uses the owner email', function () {
        $account = client([], ['email' => 'owner@corp.example']);
        $account->members()->create(['user_id' => ClientUser::factory()->create()->id, 'role' => 'member']);

        expect(clientsJson()->json('data.0.email'))->toBe('owner@corp.example');
    });

    it('leaves out deleted clients everywhere', function () {
        client(['name' => 'Kept']);
        client(['name' => 'Gone'])->delete();

        $response = clientsJson()->assertOk();

        expect(collect($response->json('data'))->pluck('name')->all())->toBe(['Kept'])
            ->and($response->json('meta.counts.all'))->toBe(1)->and($response->json('meta.total'))->toBe(1);
    });

    it('still lists an account whose owner login was deleted', function () {
        $account = client([], ['email' => 'former@owner.example']);
        $account->owner->delete();

        clientsJson()->assertOk()->assertJsonPath('data.0.email', 'former@owner.example');
    });
});

describe('pagination', function () {
    it('returns 15 per page with the paginator meta', function () {
        ClientAccount::factory()->count(40)->create();

        clientsJson()->assertOk()
            ->assertJsonCount(15, 'data')->assertJsonPath('meta.total', 40)->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.last_page', 3)->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.from', 1)->assertJsonPath('meta.to', 15);

        clientsJson('page=3')->assertJsonCount(10, 'data')->assertJsonPath('meta.from', 31)->assertJsonPath('meta.to', 40);
    });

    it('does not repeat or skip a row between pages', function () {
        ClientAccount::factory()->count(40)->joinedOn('2025-05-05 10:00:00')->create(); // identical sort values

        $ids = collect([1, 2, 3])->flatMap(fn ($p) => collect(clientsJson("page={$p}")->json('data'))->pluck('id'));

        expect($ids)->toHaveCount(40)->and($ids->unique())->toHaveCount(40);
    });

    it('gives the last page when asked for one past the end', function () {
        ClientAccount::factory()->count(20)->create();

        clientsJson('page=99')->assertOk()
            ->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.last_page', 2)->assertJsonCount(5, 'data');
    });

    it('answers an empty list with one page', function () {
        clientsJson()->assertOk()
            ->assertJsonPath('data', [])->assertJsonPath('meta.total', 0)->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('meta.from', null)->assertJsonPath('meta.to', null)
            ->assertJsonPath('meta.counts', ['all' => 0, 'active' => 0, 'inactive' => 0, 'closed' => 0]);
    });

    it('rejects a bad page', function () {
        clientsJson('page=0')->assertStatus(422)->assertJsonValidationErrors('page');
        clientsJson('page=abc')->assertStatus(422)->assertJsonValidationErrors('page');
    });
});

describe('filters', function () {
    beforeEach(function () {
        client(['name' => 'Active Regular', 'status' => ClientStatus::Active, 'client_group' => ClientGroup::Regular]);
        client(['name' => 'Active VIP', 'status' => ClientStatus::Active, 'client_group' => ClientGroup::Vip]);
        client(['name' => 'Inactive Reseller', 'status' => ClientStatus::Inactive, 'client_group' => ClientGroup::Reseller]);
        client(['name' => 'Inactive VIP', 'status' => ClientStatus::Inactive, 'client_group' => ClientGroup::Vip]);
        client(['name' => 'Closed Regular', 'status' => ClientStatus::Closed, 'client_group' => ClientGroup::Regular]);
    });

    it('filters by status', function () {
        expect(clientNames('status=active'))->toEqualCanonicalizing(['Active Regular', 'Active VIP'])
            ->and(clientNames('status=inactive'))->toEqualCanonicalizing(['Inactive Reseller', 'Inactive VIP'])
            ->and(clientNames('status=closed'))->toBe(['Closed Regular']);
    });

    it('filters by group', function () {
        expect(clientNames('group=vip'))->toEqualCanonicalizing(['Active VIP', 'Inactive VIP'])
            ->and(clientNames('group=reseller'))->toBe(['Inactive Reseller'])
            ->and(clientNames('group=regular'))->toEqualCanonicalizing(['Active Regular', 'Closed Regular']);
    });

    it('combines status and group', function () {
        expect(clientNames('status=inactive&group=vip'))->toBe(['Inactive VIP'])
            ->and(clientNames('status=closed&group=vip'))->toBe([]);
    });

    it('treats "all" and empty values as no filter', function () {
        expect(clientNames('status=all&group=all'))->toHaveCount(5)
            ->and(clientNames('search='))->toHaveCount(5);
    });

    it('rejects unknown values', function () {
        clientsJson('status=banned')->assertStatus(422)->assertJsonValidationErrors('status');
        clientsJson('group=gold')->assertStatus(422)->assertJsonValidationErrors('group');
    });
});

describe('search', function () {
    beforeEach(function () {
        client(['name' => 'Nusrat Jahan', 'company' => 'Nusrat Bakes'], ['email' => 'nusrat@sweets.example']);
        client(['name' => 'Arif Hasan', 'company' => 'TechNest Ltd'], ['email' => 'arif@technest.example']);
        client(['name' => 'Sabbir Hossain', 'company' => null], ['email' => 'sabbir@gmail.example']);
    });

    it('finds by name, company or email, part of a word, any case', function () {
        expect(clientNames('search=arif'))->toBe(['Arif Hasan'])                  // name
            ->and(clientNames('search=HOSSA'))->toBe(['Sabbir Hossain'])          // part of the name, other case
            ->and(clientNames('search=technest'))->toBe(['Arif Hasan'])           // company (and email)
            ->and(clientNames('search=bakes'))->toBe(['Nusrat Jahan'])            // company only
            ->and(clientNames('search=gmail.example'))->toBe(['Sabbir Hossain'])  // email only
            ->and(clientNames('search=nobody'))->toBe([]);
    });

    it('matches any of the three fields', function () {
        // "nusrat" is in a name, a company and an email, all on the same client: one row, not three.
        expect(clientNames('search=nusrat'))->toBe(['Nusrat Jahan']);
    });

    it('ignores surrounding spaces', function () {
        expect(clientNames('search='.urlencode('  arif  ')))->toBe(['Arif Hasan']);
    });

    it('searches % and _ literally', function () {
        client(['name' => '100% Organic']);
        client(['name' => 'Ab_cd Foods']);

        expect(clientNames('search='.urlencode('%')))->toBe(['100% Organic'])
            ->and(clientNames('search='.urlencode('b_c')))->toBe(['Ab_cd Foods']);
    });

    it('combines with the filters', function () {
        client(['name' => 'Arif Closed', 'status' => ClientStatus::Closed]);

        expect(clientNames('search=arif'))->toHaveCount(2)
            ->and(clientNames('search=arif&status=closed'))->toBe(['Arif Closed']);
    });

    it('does not match a deleted owner login by other accounts', function () {
        expect(clientNames('search=sweets.example'))->toBe(['Nusrat Jahan']);
    });
});

describe('tab counts', function () {
    beforeEach(function () {
        client(['name' => 'Ann', 'status' => ClientStatus::Active, 'client_group' => ClientGroup::Regular]);
        client(['name' => 'Anna', 'status' => ClientStatus::Active, 'client_group' => ClientGroup::Vip]);
        client(['name' => 'Bob', 'status' => ClientStatus::Inactive, 'client_group' => ClientGroup::Regular]);
        client(['name' => 'Anders', 'status' => ClientStatus::Closed, 'client_group' => ClientGroup::Vip]);
        client(['name' => 'Carl', 'status' => ClientStatus::Closed, 'client_group' => ClientGroup::Regular]);
    });

    it('counts every status and the total', function () {
        clientsJson()->assertJsonPath('meta.counts', ['all' => 5, 'active' => 2, 'inactive' => 1, 'closed' => 2]);
    });

    it('does not shrink with the status filter, so the other tabs keep their numbers', function () {
        $response = clientsJson('status=closed')->assertOk();

        expect($response->json('meta.counts'))->toBe(['all' => 5, 'active' => 2, 'inactive' => 1, 'closed' => 2])
            ->and($response->json('meta.total'))->toBe(2);
    });

    it('follows the group filter', function () {
        clientsJson('group=vip&status=active')
            ->assertJsonPath('meta.counts', ['all' => 2, 'active' => 1, 'inactive' => 0, 'closed' => 1])
            ->assertJsonPath('meta.total', 1);
    });

    it('follows the search', function () {
        clientsJson('search=an')
            ->assertJsonPath('meta.counts', ['all' => 3, 'active' => 2, 'inactive' => 0, 'closed' => 1]); // Ann, Anna, Anders
    });

    it('follows group and search together', function () {
        clientsJson('search=an&group=vip')
            ->assertJsonPath('meta.counts', ['all' => 2, 'active' => 1, 'inactive' => 0, 'closed' => 1]);
    });

    it('keeps meta.total equal to the count of the selected tab', function () {
        foreach (['active', 'inactive', 'closed'] as $status) {
            $response = clientsJson("status={$status}");
            expect($response->json('meta.total'))->toBe($response->json("meta.counts.{$status}"));
        }
        $all = clientsJson();
        expect($all->json('meta.total'))->toBe($all->json('meta.counts.all'));
    });
});

describe('sorting', function () {
    beforeEach(function () {
        ClientAccount::factory()->state(['name' => 'Charlie'])->summary(2, 9, 500)->joinedOn('2024-01-01 10:00:00')->create();
        ClientAccount::factory()->state(['name' => 'Alice'])->summary(7, 1, 0)->joinedOn('2026-01-01 10:00:00')->create();
        ClientAccount::factory()->state(['name' => 'Bob'])->summary(5, 4, 1200)->joinedOn('2025-01-01 10:00:00')->create();
    });

    it('lists the newest clients first by default', function () {
        expect(clientNames())->toBe(['Alice', 'Bob', 'Charlie']);
    });

    it('sorts by each key in both directions', function (string $sort, array $expected) {
        expect(clientNames("sort={$sort}"))->toBe($expected);
    })->with([
        'name' => ['name', ['Alice', 'Bob', 'Charlie']],
        'name desc' => ['-name', ['Charlie', 'Bob', 'Alice']],
        'joined' => ['joined', ['Charlie', 'Bob', 'Alice']],
        'joined desc' => ['-joined', ['Alice', 'Bob', 'Charlie']],
        'balance' => ['balance', ['Alice', 'Charlie', 'Bob']],
        'balance desc' => ['-balance', ['Bob', 'Charlie', 'Alice']],
        'services desc' => ['-services', ['Alice', 'Bob', 'Charlie']],
        'domains desc' => ['-domains', ['Charlie', 'Bob', 'Alice']],
    ]);

    it('sorts before paginating', function () {
        ClientAccount::factory()->count(20)->state(fn () => ['name' => 'Zed '.fake()->unique()->numberBetween(100, 999)])->create();

        $page2 = collect(clientsJson('sort=name&page=2')->json('data'))->pluck('name')->all();
        $sorted = $page2;
        sort($sorted);

        expect($page2)->toBe($sorted)->and($page2[0])->toStartWith('Zed');
    });

    it('rejects an unknown sort', function () {
        clientsJson('sort=password')->assertStatus(422)->assertJsonValidationErrors('sort');
        clientsJson('sort=-email')->assertStatus(422);
    });
});

describe('access', function () {
    it('returns 403 without clients.view', function () {
        client();

        api(staffWith(['clients.create', 'clients.edit', 'invoices.view'], 'No View'))
            ->getJson('/api/admin/clients')->assertStatus(403)->assertJsonPath('code', 'forbidden');
    });

    it('returns 401 without a login', function () {
        test()->withHeaders(['Origin' => 'http://localhost:3003'])->getJson('/api/admin/clients')->assertStatus(401);
    });

    it('allows a Super Admin and the default Sales role', function () {
        client();

        api(superAdmin())->getJson('/api/admin/clients')->assertOk()->assertJsonCount(1, 'data');
        api(tap(Admin::factory()->create())->assignRole('Sales'))->getJson('/api/admin/clients')->assertOk();
    });

    it('blocks a role that has the clients module but not view', function () {
        // Direct DB grant of clients.edit only (the role editor would add view; the API must still hold).
        api(staffWith(['clients.edit'], 'Edit Only'))->getJson('/api/admin/clients')->assertStatus(403);
    });
});

describe('queries', function () {
    /** The SQL statements one request runs. */
    function queriesFor(Admin $viewer, string $query = ''): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        api($viewer)->getJson('/api/admin/clients'.($query ? '?'.$query : ''))->assertOk();
        $log = collect(DB::getQueryLog())->pluck('query')->all();
        DB::disableQueryLog();

        return $log;
    }

    it('uses the same number of queries for 3 clients as for 15 (no N+1)', function () {
        $viewer = clientsViewer();
        queriesFor($viewer); // the first request also loads and caches the viewer's permissions

        ClientAccount::factory()->count(3)->create();
        $few = queriesFor($viewer);

        ClientAccount::factory()->count(12)->create();
        $many = queriesFor($viewer);

        expect(count($many))->toBe(count($few))->and(count($few))->toBeLessThanOrEqual(5);
    });

    it('needs one query each for the rows, the total, the owners and the counts', function () {
        $viewer = clientsViewer();
        client();
        queriesFor($viewer);

        $log = collect(queriesFor($viewer, 'search=a&status=active&group=regular'));

        expect($log->filter(fn ($q) => str_contains($q, 'client_accounts') || str_contains($q, 'client_users'))->count())->toBeLessThanOrEqual(4);
    });
});

describe('indexes', function () {
    it('has indexes for the filtered, searched and sorted columns', function () {
        $indexes = collect(Schema::getIndexes('client_accounts'))->map(fn ($i) => implode(',', $i['columns']))->all();

        expect($indexes)->toContain('status,client_group', 'client_group', 'name', 'company', 'created_at');
        expect(collect(Schema::getIndexes('client_users'))->contains(fn ($i) => $i['columns'] === ['email'] && $i['unique']))->toBeTrue();
    });
});
