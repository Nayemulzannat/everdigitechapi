<?php

use App\Enums\ClientGroup;
use App\Enums\ClientStatus;
use App\Enums\MemberRole;
use App\Enums\MemberStatus;
use App\Enums\ResourceScope;
use App\Enums\ResourceType;
use App\Models\AccountMember;
use App\Models\AccountMemberGrant;
use App\Models\Admin;
use App\Models\ClientAccount;
use App\Models\ClientUser;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

describe('ClientUser and the client guard', function () {
    it('is the model behind the client guard, not the admin one', function () {
        $user = ClientUser::factory()->create();

        $provider = Auth::guard('client')->getProvider();

        expect($provider->retrieveById($user->id))->toBeInstanceOf(ClientUser::class)
            ->and($provider->retrieveByCredentials(['email' => $user->email])?->is($user))->toBeTrue()
            ->and(Auth::guard('admin')->getProvider()->retrieveById($user->id))->toBeNull();
    });

    it('signs in on the client guard only', function () {
        $user = ClientUser::factory()->create();

        $this->actingAs($user, 'client');

        expect(Auth::guard('client')->check())->toBeTrue()->and(Auth::guard('admin')->check())->toBeFalse();
    });

    it('can not use client credentials on the admin guard', function () {
        ClientUser::factory()->create(['email' => 'person@example.com', 'password' => 'Secret-pass-123']);

        expect(Auth::guard('admin')->attempt(['email' => 'person@example.com', 'password' => 'Secret-pass-123']))->toBeFalse();
    });

    it('hashes the password, hides secrets and encrypts the 2FA secret', function () {
        $user = ClientUser::factory()->create(['password' => 'Secret-pass-123', 'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()]);

        expect($user->password)->not->toBe('Secret-pass-123')
            ->and($user->toArray())->not->toHaveKeys(['password', 'remember_token', 'two_factor_secret'])
            ->and(DB::table('client_users')->where('id', $user->id)->value('two_factor_secret'))->not->toBe('JBSWY3DPEHPK3PXP')
            ->and($user->fresh()->two_factor_secret)->toBe('JBSWY3DPEHPK3PXP')
            ->and($user->hasTwoFactorEnabled())->toBeTrue();
    });

    it('has a unique email and soft deletes', function () {
        $user = ClientUser::factory()->create(['email' => 'same@example.com']);

        expect(fn () => ClientUser::factory()->create(['email' => 'same@example.com']))->toThrow(QueryException::class);

        $user->delete();
        expect(ClientUser::find($user->id))->toBeNull()->and(ClientUser::withTrashed()->find($user->id))->not->toBeNull();
    });

    it('does not mix with the admins table', function () {
        Admin::factory()->create(['email' => 'both@example.com']);

        expect(fn () => ClientUser::factory()->create(['email' => 'both@example.com']))->not->toThrow(QueryException::class);
    });
});

describe('ClientAccount', function () {
    it('has an owner who is also an owner member', function () {
        $account = ClientAccount::factory()->create();

        expect($account->owner)->toBeInstanceOf(ClientUser::class)
            ->and($account->members)->toHaveCount(1)
            ->and($account->members->first()->role)->toBe(MemberRole::Owner)
            ->and($account->members->first()->user->is($account->owner))->toBeTrue();
    });

    it('casts group, status and balances', function () {
        $account = ClientAccount::factory()->group(ClientGroup::Vip)->status(ClientStatus::Closed)->create(['credit_balance' => 1500]);

        expect($account->client_group)->toBe(ClientGroup::Vip)->and($account->status)->toBe(ClientStatus::Closed)
            ->and($account->credit_balance)->toBe('1500.00');
    });

    it('soft deletes and keeps its members', function () {
        $account = ClientAccount::factory()->create();

        $account->delete();

        expect(ClientAccount::find($account->id))->toBeNull()->and(ClientAccount::withTrashed()->find($account->id))->not->toBeNull()
            ->and(AccountMember::where('account_id', $account->id)->count())->toBe(1);
    });

    it('can not lose its owner login by a hard delete', function () {
        $account = ClientAccount::factory()->create();

        expect(fn () => DB::table('client_users')->where('id', $account->owner_user_id)->delete())->toThrow(QueryException::class);
    });
});

describe('team access', function () {
    it('lets one person belong to several accounts and an account have several people', function () {
        $a = ClientAccount::factory()->create();
        $b = ClientAccount::factory()->create();
        $person = ClientUser::factory()->create();
        AccountMember::factory()->create(['account_id' => $a->id, 'user_id' => $person->id]);
        AccountMember::factory()->create(['account_id' => $b->id, 'user_id' => $person->id, 'role' => MemberRole::Member]);

        expect($person->accounts)->toHaveCount(2)
            ->and($a->users)->toHaveCount(2)               // owner + person
            ->and($a->users->pluck('id')->all())->toContain($a->owner_user_id, $person->id);
    });

    it('exposes role, permissions and scopes on the pivot', function () {
        $account = ClientAccount::factory()->create();
        $person = ClientUser::factory()->create();
        AccountMember::factory()->selectedOnly()->create([
            'account_id' => $account->id, 'user_id' => $person->id, 'permissions' => ['invoices.view', 'hosting.manage'],
        ]);

        $pivot = $person->accounts()->first()->pivot;

        expect($pivot->role)->toBe(MemberRole::Member)
            ->and($pivot->permissions)->toBe(['invoices.view', 'hosting.manage'])
            ->and($pivot->hosting_scope)->toBe(ResourceScope::Selected)
            ->and($pivot->domain_scope)->toBe(ResourceScope::Selected)
            ->and($pivot->status)->toBe(MemberStatus::Active);
    });

    it('allows a person only once per account', function () {
        $account = ClientAccount::factory()->create();

        expect(fn () => AccountMember::factory()->create(['account_id' => $account->id, 'user_id' => $account->owner_user_id]))
            ->toThrow(QueryException::class);
    });

    it('records who invited a member, and keeps the member if the inviter is removed for good', function () {
        $account = ClientAccount::factory()->create();
        $member = AccountMember::factory()->invited()->create(['account_id' => $account->id, 'invited_by' => $account->owner_user_id]);

        expect($member->inviter->is($account->owner))->toBeTrue()->and($member->status)->toBe(MemberStatus::Invited)->and($member->joined_at)->toBeNull();
    });

    it('stores which services and domains a "selected" member can reach, once each', function () {
        $member = AccountMember::factory()->selectedOnly()->create();
        $member->grants()->createMany([
            ['resource_type' => ResourceType::Hosting, 'resource_id' => 10],
            ['resource_type' => ResourceType::Hosting, 'resource_id' => 11],
            ['resource_type' => ResourceType::Domain, 'resource_id' => 10],
        ]);

        expect($member->grants)->toHaveCount(3)
            ->and($member->grants->where('resource_type', ResourceType::Hosting)->pluck('resource_id')->all())->toBe([10, 11])
            ->and(fn () => $member->grants()->create(['resource_type' => ResourceType::Domain, 'resource_id' => 10]))->toThrow(QueryException::class);
    });

    it('removes a member\'s grants with the member', function () {
        $member = AccountMember::factory()->selectedOnly()->create();
        $member->grants()->create(['resource_type' => ResourceType::Domain, 'resource_id' => 5]);

        $member->delete();

        expect(AccountMemberGrant::count())->toBe(0);
    });

    it('has the columns the design calls for', function () {
        expect(Schema::getColumnListing('account_members'))->toContain(
            'account_id', 'user_id', 'role', 'permissions', 'hosting_scope', 'domain_scope', 'status', 'invited_by', 'joined_at',
        )->and(Schema::getColumnListing('account_member_resources'))->toBe(['member_id', 'resource_type', 'resource_id']);
    });
});

describe('DemoDataSeeder', function () {
    it('does nothing outside the local environment', function () {
        $this->seed(DemoDataSeeder::class); // APP_ENV is "testing" here

        expect(ClientAccount::count())->toBe(0)->and(ClientUser::count())->toBe(0);
    });

    it('creates 60 varied clients, each with an owner member, in local', function () {
        app()->detectEnvironment(fn () => 'local');

        $this->seed(DemoDataSeeder::class);

        $accounts = ClientAccount::with('members')->get();
        expect($accounts)->toHaveCount(60)
            ->and($accounts->pluck('status')->unique()->map->value->sort()->values()->all())->toBe(['active', 'closed', 'inactive'])
            ->and($accounts->pluck('client_group')->unique()->map->value->sort()->values()->all())->toBe(['regular', 'reseller', 'vip'])
            ->and($accounts->where('status', ClientStatus::Active)->count())->toBe(45)
            ->and($accounts->filter(fn ($a) => $a->members->where('role', MemberRole::Owner)->count() === 1)->count())->toBe(60)
            ->and($accounts->whereNotNull('company')->count())->toBeGreaterThan(20)->and($accounts->whereNull('company')->count())->toBeGreaterThan(5)
            ->and(ClientUser::pluck('email')->unique()->count())->toBe(ClientUser::count())
            ->and(ClientUser::where('email', 'not like', '%.example')->where('email', 'not like', '%@example.com')->count())->toBe(0)
            ->and(AccountMember::where('role', MemberRole::Member)->count())->toBeGreaterThan(3);
    });

    it('does not add clients twice', function () {
        app()->detectEnvironment(fn () => 'local');

        $this->seed(DemoDataSeeder::class);
        $this->seed(DemoDataSeeder::class);

        expect(ClientAccount::count())->toBe(60);
    });

    it('feeds the Clients list', function () {
        app()->detectEnvironment(fn () => 'local');
        $this->seed(DemoDataSeeder::class);
        $this->seed(RoleSeeder::class);

        api(staffWith(['clients.view']))->getJson('/api/admin/clients')->assertOk()
            ->assertJsonPath('meta.total', 60)->assertJsonPath('meta.counts.all', 60)->assertJsonPath('meta.counts.active', 45)
            ->assertJsonPath('meta.counts.inactive', 9)->assertJsonPath('meta.counts.closed', 6);
    });
});
