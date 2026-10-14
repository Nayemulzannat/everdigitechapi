<?php

use App\Enums\MemberRole;
use App\Enums\MemberStatus;
use App\Models\AccountMember;
use App\Models\ClientAccount;
use App\Models\ClientUser;
use Illuminate\Http\Request;

/** account.context, account.permission and account.owner, tried on small routes made for the test. */
beforeEach(function () {
    Route::middleware(['api', 'auth:client', 'account.context'])->prefix('api/client/_test')->group(function () {
        Route::get('context', fn (Request $r) => [
            'account' => $r->currentAccount()->id, 'account_name' => $r->currentAccount()->name,
            'membership' => $r->currentMembership()->id, 'role' => $r->currentMembership()->role->value,
        ]);
        Route::get('permission', fn () => ['ok' => true])->middleware('account.permission:invoices.view');
        Route::get('two-permissions', fn () => ['ok' => true])->middleware('account.permission:invoices.view,hosting.manage');
        Route::get('owner', fn () => ['ok' => true])->middleware('account.owner');
    });
});

function ctxUser(): ClientUser
{
    $user = ClientUser::factory()->create();
    ClientAccount::factory()->create(['owner_user_id' => $user->id, 'name' => 'Own account']);

    return $user;
}

function ctxCall(ClientUser $user, string $path, ?string $accountId = null)
{
    $headers = ['Origin' => 'http://localhost:3002'] + ($accountId !== null ? ['X-Account-Id' => $accountId] : []);

    return test()->actingAs($user->fresh(), 'client')->withHeaders($headers)->getJson("/api/client/_test/{$path}");
}

function ctxJoin(ClientUser $user, string $name, array $membership = []): ClientAccount
{
    $account = ClientAccount::factory()->create(['name' => $name]);
    AccountMember::factory()->create(['account_id' => $account->id, 'user_id' => $user->id, ...$membership]);

    return $account;
}

describe('account.context', function () {
    it('needs a sign-in first', function () {
        test()->withHeaders(['Origin' => 'http://localhost:3002'])->getJson('/api/client/_test/context')->assertStatus(401);
    });

    it('uses the account the person owns when nothing else is known', function () {
        $user = ctxUser();

        ctxCall($user, 'context')->assertOk()->assertJsonPath('account_name', 'Own account')->assertJsonPath('role', 'owner');
    });

    it('uses the last-used account before the owned one', function () {
        $user = ctxUser();
        $other = ctxJoin($user, 'Karim Traders');
        $user->forceFill(['last_account_id' => $other->id])->save();

        ctxCall($user, 'context')->assertOk()->assertJsonPath('account', $other->id)->assertJsonPath('role', 'member');
    });

    it('falls back to the owned account when the last-used one is no longer usable', function () {
        $user = ctxUser();
        $other = ctxJoin($user, 'Karim Traders', ['status' => MemberStatus::Suspended]);
        $user->forceFill(['last_account_id' => $other->id])->save();

        ctxCall($user, 'context')->assertOk()->assertJsonPath('account_name', 'Own account');
    });

    it('prefers the X-Account-Id header over the last-used account', function () {
        $user = ctxUser();
        $karim = ctxJoin($user, 'Karim Traders');
        $nusrat = ctxJoin($user, 'Nusrat Bakes');
        $user->forceFill(['last_account_id' => $karim->id])->save();

        ctxCall($user, 'context', (string) $nusrat->id)->assertOk()->assertJsonPath('account_name', 'Nusrat Bakes');
    });

    it('allows the person\'s own account by header', function () {
        $user = ctxUser();
        $own = $user->memberships()->sole()->account_id;

        ctxCall($user, 'context', (string) $own)->assertOk()->assertJsonPath('role', 'owner');
    });

    it('allows another account the person is an active member of, as a member', function () {
        $user = ctxUser();
        $other = ctxJoin($user, 'Karim Traders', ['permissions' => ['invoices.view']]);

        ctxCall($user, 'context', (string) $other->id)->assertOk()->assertJsonPath('account', $other->id)->assertJsonPath('role', 'member');
    });

    it('refuses an account the person does not belong to', function () {
        $user = ctxUser();
        $stranger = ClientAccount::factory()->create(['name' => 'Not mine']);

        ctxCall($user, 'context', (string) $stranger->id)->assertStatus(403)->assertJsonPath('code', 'account_access_denied');
    });

    it('refuses someone else\'s account even for its owner\'s teammate-less stranger, whatever the id', function (string $id) {
        ctxCall(ctxUser(), 'context', $id)->assertStatus(403)->assertJsonPath('code', 'account_access_denied');
    })->with(['unknown' => '99999', 'zero' => '0', 'negative' => '-1', 'text' => 'abc', 'sql' => '1 or 1=1', 'decimal' => '1.5']);

    it('refuses an account where the membership is invited, suspended, or the account is deleted', function (string $case) {
        $user = ctxUser();
        $account = match ($case) {
            'invited' => ctxJoin($user, 'A', ['status' => MemberStatus::Invited]),
            'suspended' => ctxJoin($user, 'B', ['status' => MemberStatus::Suspended]),
            'deleted' => tap(ctxJoin($user, 'C'))->delete(),
        };

        ctxCall($user, 'context', (string) $account->id)->assertStatus(403)->assertJsonPath('code', 'account_access_denied');
    })->with(['invited', 'suspended', 'deleted']);

    it('refuses a person who belongs to no account', function () {
        ctxCall(ClientUser::factory()->create(), 'context')->assertStatus(403)->assertJsonPath('code', 'account_access_denied');
    });

    it('refuses a person whose only memberships are not active', function () {
        $user = ClientUser::factory()->create();
        ctxJoin($user, 'A', ['status' => MemberStatus::Invited]);

        ctxCall($user, 'context')->assertStatus(403);
    });

    it('does not let another person\'s membership grant access', function () {
        $alice = ctxUser();
        $bob = ctxUser();
        $bobsAccount = $bob->memberships()->sole()->account_id;

        ctxCall($alice, 'context', (string) $bobsAccount)->assertStatus(403);
    });

    it('gives the controller the current account and membership', function () {
        $user = ctxUser();
        $other = ctxJoin($user, 'Karim Traders');

        $json = ctxCall($user, 'context', (string) $other->id)->assertOk()->json();

        expect($json['account'])->toBe($other->id)
            ->and($json['membership'])->toBe(AccountMember::where('account_id', $other->id)->where('user_id', $user->id)->value('id'));
    });
});

describe('account.permission', function () {
    it('lets the owner do everything, with no permissions listed', function () {
        $user = ctxUser();

        ctxCall($user, 'permission')->assertOk();
        ctxCall($user, 'two-permissions')->assertOk();
    });

    it('lets a member with the permission through', function () {
        $user = ctxUser();
        $other = ctxJoin($user, 'Karim Traders', ['permissions' => ['invoices.view']]);

        ctxCall($user, 'permission', (string) $other->id)->assertOk();
    });

    it('refuses a member without it (403 account_permission_denied)', function () {
        $user = ctxUser();
        $other = ctxJoin($user, 'Karim Traders', ['permissions' => ['hosting.view']]);

        ctxCall($user, 'permission', (string) $other->id)->assertStatus(403)->assertJsonPath('code', 'account_permission_denied');
    });

    it('refuses a member with no permissions at all', function () {
        $user = ctxUser();
        $other = ctxJoin($user, 'Karim Traders', ['permissions' => null]);

        ctxCall($user, 'permission', (string) $other->id)->assertStatus(403)->assertJsonPath('code', 'account_permission_denied');
    });

    it('needs every listed permission', function () {
        $user = ctxUser();
        $partial = ctxJoin($user, 'A', ['permissions' => ['invoices.view']]);
        $full = ctxJoin($user, 'B', ['permissions' => ['invoices.view', 'hosting.manage']]);

        ctxCall($user, 'two-permissions', (string) $partial->id)->assertStatus(403);
        ctxCall($user, 'two-permissions', (string) $full->id)->assertOk();
    });

    it('judges by the account in use: the same person is owner of one account and a limited member of another', function () {
        $user = ctxUser();
        $other = ctxJoin($user, 'Karim Traders', ['permissions' => []]);

        ctxCall($user, 'permission')->assertOk(); // own account
        ctxCall($user, 'permission', (string) $other->id)->assertStatus(403);
    });

    it('does not let a permission held in one account count in another', function () {
        $user = ctxUser();
        ctxJoin($user, 'Has it', ['permissions' => ['invoices.view']]);
        $without = ctxJoin($user, 'Does not', ['permissions' => ['hosting.view']]);

        ctxCall($user, 'permission', (string) $without->id)->assertStatus(403);
    });

    it('does not treat a stored "*" as a member permission unless it is listed', function () {
        $user = ctxUser();
        $other = ctxJoin($user, 'Karim Traders', ['permissions' => ['invoices.*']]);

        ctxCall($user, 'permission', (string) $other->id)->assertStatus(403);
    });
});

describe('account.owner', function () {
    it('lets the owner through', function () {
        ctxCall(ctxUser(), 'owner')->assertOk();
    });

    it('refuses a member, whatever their permissions', function () {
        $user = ctxUser();
        $other = ctxJoin($user, 'Karim Traders', ['permissions' => ['invoices.view', 'hosting.manage', 'billing.manage']]);

        ctxCall($user, 'owner', (string) $other->id)->assertStatus(403)->assertJsonPath('code', 'account_owner_only');
    });

    it('is decided per account: the owner of one account is not the owner of another', function () {
        $user = ctxUser();
        $other = ctxJoin($user, 'Karim Traders');

        ctxCall($user, 'owner')->assertOk();
        ctxCall($user, 'owner', (string) $other->id)->assertStatus(403);
    });

    it('refuses a role set to owner on an inactive membership', function () {
        $user = ctxUser();
        $other = ctxJoin($user, 'Karim Traders', ['role' => MemberRole::Owner, 'status' => MemberStatus::Suspended]);

        ctxCall($user, 'owner', (string) $other->id)->assertStatus(403)->assertJsonPath('code', 'account_access_denied');
    });
});
