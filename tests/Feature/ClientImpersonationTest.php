<?php

use App\Enums\ClientStatus;
use App\Enums\ClientUserStatus;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\ClientAccount;
use App\Models\ClientLoginHistory;
use App\Models\ClientSession;
use App\Models\ClientUser;
use App\Models\ImpersonationToken;
use App\Models\PendingEmailChange;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

beforeEach(fn () => $this->seed(RoleSeeder::class));

/** Staff ask for the link; returns the plain token from it. */
function clIssueToken(?Admin $admin = null, ?ClientAccount $account = null, array $body = []): string
{
    $account ??= clAccountOf(clUser());
    $url = api($admin ?? superAdmin())->postJson("/api/admin/clients/{$account->id}/impersonate", $body)->assertOk()->json('url');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query['token'];
}

function clConsume(string $token)
{
    return clSpa()->postJson('/api/client/impersonate/consume', ['token' => $token]);
}

/**
 * The browser of a client who is being impersonated: signed in, with the two session values that consume writes
 * (a test cannot carry the real session cookie from one request to the next).
 */
function clImpersonated(ClientUser $user, ?Admin $admin = null, ?Carbon $startedAt = null)
{
    test()->withSession([
        'impersonated_by_admin_id' => ($admin ?? superAdmin())->id,
        'impersonation_started_at' => ($startedAt ?? now())->getTimestamp(),
    ]);

    return clSpa()->actingAs($user, 'client');
}

describe('POST /api/admin/clients/{account}/impersonate', function () {
    it('needs a sign-in and the clients.login_as permission', function () {
        $account = clAccountOf(clUser());

        test()->postJson("/api/admin/clients/{$account->id}/impersonate")->assertStatus(401);
        api(staffWith(['clients.view', 'clients.edit']))->postJson("/api/admin/clients/{$account->id}/impersonate")->assertStatus(403);
        expect(ImpersonationToken::count())->toBe(0);

        api(staffWith(['clients.login_as']))->postJson("/api/admin/clients/{$account->id}/impersonate")->assertOk();
        expect(ImpersonationToken::count())->toBe(1);
    });

    it('is allowed for Super Admin and Admin, not for the other default roles', function () {
        $account = clAccountOf(clUser());
        $as = fn (string $role) => tap(Admin::factory()->create())->assignRole($role);

        api($as('Super Admin'))->postJson("/api/admin/clients/{$account->id}/impersonate")->assertOk();
        api($as('Admin'))->postJson("/api/admin/clients/{$account->id}/impersonate")->assertOk();
        foreach (['Billing Manager', 'Support Staff', 'Sales'] as $role) {
            api($as($role))->postJson("/api/admin/clients/{$account->id}/impersonate")->assertStatus(403);
        }
    });

    it('returns a client panel URL with a 64 character token, and stores only its hash for 60 seconds', function () {
        $owner = clUser();
        $account = clAccountOf($owner);
        $admin = superAdmin();

        $url = api($admin)->postJson("/api/admin/clients/{$account->id}/impersonate")->assertOk()->json('url');

        expect($url)->toMatch('#^http://localhost:3002/impersonate\?token=[A-Za-z0-9]{64}$#');
        $token = substr($url, -64);
        $row = ImpersonationToken::sole();
        expect($row->token_hash)->toBe(hash('sha256', $token))->and($row->token_hash)->not->toBe($token)
            ->and($row->admin_id)->toBe($admin->id)->and($row->client_user_id)->toBe($owner->id)->and($row->account_id)->toBe($account->id)
            ->and($row->used_at)->toBeNull()->and($row->expires_at->isBetween(now()->addSeconds(58), now()->addSeconds(61)))->toBeTrue();
    });

    it('can pick another active member of the account, but nobody else', function () {
        $owner = clUser();
        $account = clAccountOf($owner);
        $member = ClientUser::factory()->create();
        $account->members()->create(['user_id' => $member->id, 'role' => 'member', 'permissions' => [], 'status' => 'active']);
        $stranger = clUser();

        api(superAdmin())->postJson("/api/admin/clients/{$account->id}/impersonate", ['client_user_id' => $member->id])->assertOk();
        expect(ImpersonationToken::sole()->client_user_id)->toBe($member->id);

        api(superAdmin())->postJson("/api/admin/clients/{$account->id}/impersonate", ['client_user_id' => $stranger->id])
            ->assertStatus(422)->assertJsonPath('code', 'not_a_member');
        api(superAdmin())->postJson("/api/admin/clients/{$account->id}/impersonate", ['client_user_id' => 999999])
            ->assertStatus(422)->assertJsonPath('code', 'not_a_member');
        expect(ImpersonationToken::count())->toBe(1);
    });

    it('refuses a closed account', function () {
        $account = clAccountOf(clUser());
        $account->forceFill(['status' => ClientStatus::Closed])->save();

        api(superAdmin())->postJson("/api/admin/clients/{$account->id}/impersonate")->assertStatus(422)->assertJsonPath('code', 'account_closed');

        expect(ImpersonationToken::count())->toBe(0);
    });

    it('refuses a suspended person and an account that does not exist', function () {
        $owner = clUser();
        ClientUser::whereKey($owner->id)->update(['status' => ClientUserStatus::Suspended->value]);

        api(superAdmin())->postJson('/api/admin/clients/'.clAccountOf($owner)->id.'/impersonate')->assertStatus(422)->assertJsonPath('code', 'client_suspended');
        api(superAdmin())->postJson('/api/admin/clients/999999/impersonate')->assertStatus(404);
    });
});

describe('POST /api/client/impersonate/consume', function () {
    beforeEach(fn () => Notification::fake());

    it('signs staff in as the client, on the account they asked for', function () {
        $owner = clUser();
        $account = clAccountOf($owner);
        ClientUser::whereKey($owner->id)->update(['last_account_id' => null, 'last_login_at' => null]);
        $admin = superAdmin();
        $token = clIssueToken($admin, $account);

        clConsume($token)->assertOk()->assertJsonPath('data.user.email', $owner->email)->assertJsonPath('data.current_account_id', (string) $account->id)
            ->assertJsonPath('data.impersonation.admin_name', $admin->name);

        $session = Session::driver()->all();
        expect(collect(array_keys($session))->contains(fn ($key) => str_starts_with($key, 'login_client_')))->toBeTrue()
            ->and($session['impersonated_by_admin_id'])->toBe($admin->id)
            ->and($session['impersonation_started_at'])->toBe(now()->getTimestamp())
            ->and($owner->fresh()->last_account_id)->toBe($account->id)->and($owner->fresh()->last_login_at)->toBeNull()
            ->and(ImpersonationToken::sole()->used_at)->not->toBeNull();
    });

    it('writes a login history row flagged with the admin, and sends no new-device email', function () {
        $owner = clUser();
        $admin = superAdmin();

        clConsume(clIssueToken($admin, clAccountOf($owner)))->assertOk();

        $row = ClientLoginHistory::where('client_user_id', $owner->id)->sole();
        expect($row->impersonated_by_admin_id)->toBe($admin->id)->and($row->successful)->toBeTrue()->and($row->browser)->toBe('Chrome');
        Notification::assertNothingSent();
        clSpa()->actingAs($owner, 'client')->getJson('/api/client/login-history')->assertOk()->assertJsonPath('data.0.impersonated', true);
    });

    it('is not one of the devices of the client', function () {
        $owner = clUser();
        clConsume(clIssueToken(null, clAccountOf($owner)))->assertOk();
        clImpersonated($owner)->getJson('/api/client/me')->assertOk();

        expect(ClientSession::count())->toBe(0);
    });

    it('writes client.impersonation_started with admin, client, account and IP', function () {
        $owner = clUser();
        $account = clAccountOf($owner);
        $admin = superAdmin();

        clConsume(clIssueToken($admin, $account))->assertOk();

        $log = ActivityLog::where('action', 'client.impersonation_started')->sole();
        expect($log->actor_id)->toBe($admin->id)->and($log->actor_type)->toBe($admin->getMorphClass())
            ->and($log->subject_id)->toBe($owner->id)->and($log->ip_address)->toBe('127.0.0.1')
            ->and($log->properties)->toMatchArray(['admin_id' => $admin->id, 'client_user_id' => $owner->id, 'account_id' => $account->id, 'client_email' => $owner->email]);
    });

    it('works once only', function () {
        $token = clIssueToken();
        clConsume($token)->assertOk();

        clConsume($token)->assertStatus(422)->assertJsonPath('code', 'invalid_impersonation_token');

        expect(ActivityLog::where('action', 'client.impersonation_started')->count())->toBe(1)->and(ClientLoginHistory::count())->toBe(1);
    });

    it('expires after 60 seconds', function () {
        $token = clIssueToken();

        $this->travel(61)->seconds();

        clConsume($token)->assertStatus(422)->assertJsonPath('code', 'invalid_impersonation_token');
        expect(ClientLoginHistory::count())->toBe(0)->and(ImpersonationToken::sole()->used_at)->toBeNull();
    });

    it('still works just before it expires', function () {
        $token = clIssueToken();
        $this->travel(55)->seconds();

        clConsume($token)->assertOk();
    });

    it('refuses an unknown token and a malformed one', function () {
        clConsume(str_repeat('a', 64))->assertStatus(422)->assertJsonPath('code', 'invalid_impersonation_token');
        clConsume('short')->assertStatus(422)->assertJsonValidationErrors('token');
        clSpa()->postJson('/api/client/impersonate/consume', [])->assertStatus(422)->assertJsonValidationErrors('token');
    });

    it('refuses when the account was closed after the link was made', function () {
        $account = clAccountOf(clUser());
        $token = clIssueToken(null, $account);
        $account->forceFill(['status' => ClientStatus::Closed])->save();

        clConsume($token)->assertStatus(422)->assertJsonPath('code', 'account_closed');

        expect(ClientLoginHistory::count())->toBe(0)->and(ActivityLog::where('action', 'client.impersonation_started')->count())->toBe(0);
    });

    it('refuses when the staff member lost the permission in the meantime', function () {
        $admin = staffWith(['clients.login_as']);
        $token = clIssueToken($admin);
        $admin->roles->each->syncPermissions([]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        clConsume($token)->assertStatus(422)->assertJsonPath('code', 'invalid_impersonation_token');

        expect(ClientLoginHistory::count())->toBe(0);
    });
});

describe('while impersonating', function () {
    beforeEach(fn () => Notification::fake());

    it('shows the impersonation in /me', function () {
        $admin = superAdmin();

        $me = clImpersonated(clUser(), $admin)->getJson('/api/client/me')->assertOk()->json('data.impersonation');

        expect($me)->toHaveKeys(['admin_name', 'started_at', 'expires_at'])->and($me['admin_name'])->toBe($admin->name)
            ->and($me['started_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
            ->and(Carbon::parse($me['started_at'])->diffInMinutes(Carbon::parse($me['expires_at'])))->toBe(30.0);
    });

    it('shows nothing for a normal sign-in', function () {
        clSpa()->actingAs(clUser(), 'client')->getJson('/api/client/me')->assertOk()->assertJsonPath('data.impersonation', null);
    });

    it('blocks the sensitive actions with 403 not_allowed_while_impersonating', function () {
        $owner = clUser(['password' => CL_PASSWORD]);
        clImpersonated($owner);

        $calls = [
            fn () => clSpa()->putJson('/api/client/password', ['current_password' => CL_PASSWORD, 'password' => 'Another-Strong-Pass-42', 'password_confirmation' => 'Another-Strong-Pass-42']),
            fn () => clSpa()->postJson('/api/client/email/change', ['new_email' => 'new@uddintraders.com', 'password' => CL_PASSWORD]),
            fn () => clSpa()->deleteJson('/api/client/sessions/others', ['password' => CL_PASSWORD]),
        ];
        foreach ($calls as $call) {
            $call()->assertStatus(403)->assertJsonPath('code', 'not_allowed_while_impersonating');
        }

        expect(Hash::check(CL_PASSWORD, $owner->fresh()->password))->toBeTrue()->and(PendingEmailChange::count())->toBe(0);
        Notification::assertNothingSent();
    });

    it('still allows reading, the profile and the avatar', function () {
        Storage::fake('public');
        $owner = clUser(['name' => 'Rahim Uddin']);
        clImpersonated($owner);

        clSpa()->getJson('/api/client/sessions')->assertOk();
        clSpa()->getJson('/api/client/login-history')->assertOk();
        clSpa()->withHeader('Accept', 'application/json')->post('/api/client/profile', [
            '_method' => 'PUT', 'name' => 'Rahim Uddin', 'phone' => '01711223344', 'avatar' => UploadedFile::fake()->image('a.png', 300, 300),
        ])->assertOk();

        Storage::disk('public')->assertExists($owner->fresh()->avatar_path);
    });

    it('does not block a normal client from the same routes', function () {
        $user = clUser(['password' => CL_PASSWORD]);

        clAs($user)->putJson('/api/client/password', ['current_password' => CL_PASSWORD, 'password' => 'Another-Strong-Pass-42', 'password_confirmation' => 'Another-Strong-Pass-42'])->assertOk();
    });
});

describe('ending it', function () {
    beforeEach(fn () => Notification::fake());

    it('exit signs out the client guard only, and the staff sign-in stays', function () {
        $admin = superAdmin();
        $owner = clUser();
        clImpersonated($owner, $admin);
        $this->actingAs($admin, 'admin');

        clSpa()->postJson('/api/client/impersonate/exit')->assertNoContent();

        clSpa()->getJson('/api/client/me')->assertStatus(401);
        api($admin)->getJson('/api/admin/me')->assertOk()->assertJsonPath('data.user.email', $admin->email);
        expect(Session::driver()->has('impersonated_by_admin_id'))->toBeFalse();
        $log = ActivityLog::where('action', 'client.impersonation_ended')->sole();
        expect($log->actor_id)->toBe($admin->id)->and($log->subject_id)->toBe($owner->id)->and($log->ip_address)->toBe('127.0.0.1')
            ->and($log->properties)->toMatchArray(['admin_id' => $admin->id, 'client_user_id' => $owner->id, 'reason' => 'exit']);
    });

    it('exit needs a sign-in, and is refused for a client who is not impersonated', function () {
        clSpa()->postJson('/api/client/impersonate/exit')->assertStatus(401);

        $user = clUser();
        clAs($user)->postJson('/api/client/impersonate/exit')->assertStatus(403)->assertJsonPath('code', 'not_impersonating');
        clAs($user)->getJson('/api/client/me')->assertOk();
        expect(ActivityLog::where('action', 'client.impersonation_ended')->count())->toBe(0);
    });

    it('a normal sign-out during impersonation is logged as the end of it', function () {
        clImpersonated(clUser());

        clSpa()->postJson('/api/client/logout')->assertNoContent();

        expect(ActivityLog::where('action', 'client.impersonation_ended')->sole()->properties['reason'])->toBe('logout');
    });

    it('a sign-out of a normal client writes no impersonation log', function () {
        clAs(clUser())->postJson('/api/client/logout')->assertNoContent();

        expect(ActivityLog::where('action', 'like', 'client.impersonation%')->count())->toBe(0);
    });

    it('ends by itself after 30 minutes', function () {
        $admin = superAdmin();
        $owner = clUser();
        clImpersonated($owner, $admin);

        $this->travel(29)->minutes();
        clSpa()->getJson('/api/client/me')->assertOk();

        $this->travel(2)->minutes();
        clSpa()->getJson('/api/client/me')->assertStatus(401)->assertJsonPath('code', 'impersonation_expired');

        clSpa()->getJson('/api/client/me')->assertStatus(401);
        expect(ActivityLog::where('action', 'client.impersonation_ended')->sole()->properties)->toMatchArray(['reason' => 'expired', 'admin_id' => $admin->id, 'client_user_id' => $owner->id]);
    });

    it('leaves nothing behind for the next sign-in of the same browser', function () {
        $owner = clUser(['password' => CL_PASSWORD]);
        clImpersonated($owner);
        clSpa()->postJson('/api/client/impersonate/exit')->assertNoContent();

        clAs($owner)->putJson('/api/client/password', ['current_password' => CL_PASSWORD, 'password' => 'Another-Strong-Pass-42', 'password_confirmation' => 'Another-Strong-Pass-42'])->assertOk();
        clAs($owner)->getJson('/api/client/me')->assertOk()->assertJsonPath('data.impersonation', null);
    });
});
