<?php

use App\Models\Admin;
use App\Models\AdminLoginHistory;
use App\Notifications\AdminResetPasswordNotification;
use Illuminate\Foundation\Auth\User as GenericUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

const STRONG_PASSWORD = 'Correct-Horse-9-Battery';

/** Calls the API like the admin SPA does: from a stateful origin, so the session middleware runs. */
function spa()
{
    return test()->withHeaders(['Origin' => 'http://localhost:3003', 'Referer' => 'http://localhost:3003/login', 'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36']);
}

function login(string $email, string $password, bool $remember = false)
{
    return spa()->postJson('/api/admin/login', compact('email', 'password', 'remember'));
}

function adminWithRole(string $roleName, array $permissions = [], array $attributes = []): Admin
{
    $role = Role::findOrCreate($roleName, 'admin');
    foreach ($permissions as $name) {
        $role->givePermissionTo(Permission::findOrCreate($name, 'admin'));
    }

    return tap(Admin::factory()->create($attributes))->assignRole($role);
}

describe('login', function () {
    it('signs in and returns the Me shape', function () {
        $admin = adminWithRole('Billing Manager', ['invoices.view', 'invoices.refund'], ['password' => STRONG_PASSWORD]);

        login($admin->email, STRONG_PASSWORD, true)
            ->assertOk()
            ->assertExactJson(['data' => [
                'user' => ['id' => (string) $admin->id, 'name' => $admin->name, 'email' => $admin->email],
                'role' => ['id' => (string) $admin->roles->first()->id, 'name' => 'Billing Manager', 'isSuperAdmin' => false],
                'permissions' => ['invoices.view', 'invoices.refund'],
            ]]);

        expect(Auth::guard('admin')->check())->toBeTrue();
    });

    it('records the sign-in and clears earlier failures', function () {
        $admin = adminWithRole('Support Staff', [], ['password' => STRONG_PASSWORD, 'failed_login_attempts' => 3]);

        login(strtoupper($admin->email), STRONG_PASSWORD)->assertOk();

        $admin->refresh();
        expect($admin->failed_login_attempts)->toBe(0)
            ->and($admin->locked_until)->toBeNull()
            ->and($admin->last_login_at)->not->toBeNull()
            ->and($admin->last_login_ip)->toBe('127.0.0.1');

        $history = AdminLoginHistory::sole();
        expect($history->admin_id)->toBe($admin->id)
            ->and($history->successful)->toBeTrue()
            ->and($history->failure_reason)->toBeNull()
            ->and($history->browser)->toBe('Chrome')
            ->and($history->platform)->toBe('Windows');
    });

    it('regenerates the session id on login', function () {
        $admin = adminWithRole('Support Staff', [], ['password' => STRONG_PASSWORD]);
        $this->startSession();
        $before = session()->getId();

        login($admin->email, STRONG_PASSWORD)->assertOk();

        expect(session()->getId())->not->toBe($before);
    });

    it('gives a Super Admin every permission name', function () {
        Permission::findOrCreate('clients.view', 'admin');
        Permission::findOrCreate('invoices.refund', 'admin');
        Permission::findOrCreate('roles.delete', 'admin');
        $admin = adminWithRole(Admin::SUPER_ADMIN_ROLE, [], ['password' => STRONG_PASSWORD]);

        login($admin->email, STRONG_PASSWORD)
            ->assertOk()
            ->assertJsonPath('data.role.isSuperAdmin', true)
            ->assertJsonPath('data.permissions', ['clients.view', 'invoices.refund', 'roles.delete']);
    });

    it('rejects a wrong password with a generic 422', function () {
        $admin = adminWithRole('Support Staff', [], ['password' => STRONG_PASSWORD]);

        login($admin->email, 'wrong-password-123')
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_credentials')
            ->assertJsonMissingPath('errors');

        expect(Auth::guard('admin')->check())->toBeFalse();

        $history = AdminLoginHistory::sole();
        expect($history->admin_id)->toBe($admin->id)
            ->and($history->successful)->toBeFalse()
            ->and($history->failure_reason)->toBe('invalid_credentials')
            ->and($admin->fresh()->failed_login_attempts)->toBe(1);
    });

    it('answers an unknown email exactly like a wrong password', function () {
        $admin = adminWithRole('Support Staff', [], ['password' => STRONG_PASSWORD]);

        $unknown = login('nobody@example.com', 'wrong-password-123');
        $wrong = login($admin->email, 'wrong-password-123');

        expect($unknown->status())->toBe(422)
            ->and($unknown->json())->toBe($wrong->json());

        $history = AdminLoginHistory::whereNull('admin_id')->sole();
        expect($history->email_attempted)->toBe('nobody@example.com')->and($history->successful)->toBeFalse();
    });

    it('validates the request body', function () {
        spa()->postJson('/api/admin/login', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['email', 'password']);
    });

    it('locks for 15 minutes after 5 failures and reports the minutes', function () {
        $admin = adminWithRole('Support Staff', [], ['password' => STRONG_PASSWORD]);

        foreach (range(1, 4) as $i) {
            login($admin->email, 'wrong-password-123')->assertStatus(422);
        }
        // The 5th failure triggers the lock and already gets the lock response.
        login($admin->email, 'wrong-password-123')
            ->assertStatus(429)
            ->assertJsonPath('code', 'account_locked')
            ->assertJsonPath('retry_after_minutes', 15)
            ->assertJsonPath('message', 'Too many failed sign-in attempts. Try again in 15 minutes.')
            ->assertHeader('Retry-After');

        expect($admin->fresh()->locked_until->isFuture())->toBeTrue();

        // Even the right password is refused while locked.
        login($admin->email, STRONG_PASSWORD)->assertStatus(429)->assertJsonPath('code', 'account_locked');
        expect(Auth::guard('admin')->check())->toBeFalse();
        expect(AdminLoginHistory::where('failure_reason', 'locked')->count())->toBe(1);

        // The lock ends after 15 minutes.
        $this->travel(16)->minutes();
        login($admin->email, STRONG_PASSWORD)->assertOk();
    });

    it('locks unknown emails the same way', function () {
        foreach (range(1, 4) as $i) {
            login('ghost@example.com', 'wrong-password-123')->assertStatus(422);
        }
        login('ghost@example.com', 'wrong-password-123')->assertStatus(429)->assertJsonPath('code', 'account_locked');
    });

    it('counts attempts per email and IP', function () {
        $admin = adminWithRole('Support Staff', [], ['password' => STRONG_PASSWORD]);

        foreach (range(1, 4) as $i) {
            login($admin->email, 'wrong-password-123');
        }
        // A different IP has its own counter.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);
        login($admin->email, 'wrong-password-123')->assertStatus(422);
    });

    it('refuses a suspended account with 403', function () {
        $admin = adminWithRole('Support Staff', [], ['password' => STRONG_PASSWORD, 'status' => 'suspended']);

        login($admin->email, STRONG_PASSWORD)
            ->assertStatus(403)
            ->assertJsonPath('code', 'account_suspended');

        expect(Auth::guard('admin')->check())->toBeFalse()
            ->and(AdminLoginHistory::sole()->failure_reason)->toBe('suspended')
            ->and($admin->fresh()->last_login_at)->toBeNull();
    });

    it('does not reveal a suspended account to a wrong password', function () {
        $admin = adminWithRole('Support Staff', [], ['password' => STRONG_PASSWORD, 'status' => 'suspended']);

        login($admin->email, 'wrong-password-123')->assertStatus(422)->assertJsonPath('code', 'invalid_credentials');
    });
});

describe('me', function () {
    it('returns 401 without a login', function () {
        spa()->getJson('/api/admin/me')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
    });

    it('returns the signed-in admin', function () {
        $admin = adminWithRole('Sales', ['clients.view']);

        $this->actingAs($admin, 'admin');
        spa()->getJson('/api/admin/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', $admin->email)
            ->assertJsonPath('data.role.name', 'Sales')
            ->assertJsonPath('data.permissions', ['clients.view']);
    });

    it('signs out an admin who was suspended while signed in', function () {
        $admin = adminWithRole('Sales', [], ['status' => 'suspended']);

        $this->actingAs($admin, 'admin');
        spa()->getJson('/api/admin/me')->assertStatus(403)->assertJsonPath('code', 'account_suspended');
        expect(Auth::guard('admin')->check())->toBeFalse();
    });

    it('lists the login history, newest first, capped at 50', function () {
        $admin = adminWithRole('Sales');
        $other = adminWithRole('Support Staff');
        AdminLoginHistory::factory()->count(60)->for($admin)->create();
        AdminLoginHistory::factory()->count(3)->for($other)->create();

        $this->actingAs($admin, 'admin');
        $page1 = spa()->getJson('/api/admin/me/login-history')->assertOk();
        $page1->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 50)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.last_page', 5)
            ->assertJsonStructure(['data' => [['id', 'ipAddress', 'browser', 'platform', 'successful', 'failureReason', 'createdAt']]]);

        $dates = collect($page1->json('data'))->pluck('createdAt');
        expect($dates->sortDesc()->values()->all())->toBe($dates->all())
            ->and($dates->first())->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
    });

    it('requires a login for the history', function () {
        spa()->getJson('/api/admin/me/login-history')->assertStatus(401);
    });
});

describe('logout', function () {
    it('signs out only the admin guard and keeps the client session', function () {
        $admin = adminWithRole('Sales');
        $client = new GenericUser;
        $client->id = 7;

        $this->actingAs($admin, 'admin')->actingAs($client, 'client');
        $this->withSession(['marker' => 'kept']);
        $token = csrf_token();

        spa()->postJson('/api/admin/logout')->assertNoContent();

        expect(Auth::guard('admin')->check())->toBeFalse()
            ->and(Auth::guard('client')->check())->toBeTrue()
            ->and(session('marker'))->toBe('kept')
            ->and(csrf_token())->not->toBe($token);
    });

    it('does not flush the session', function () {
        $this->withSession(['login_client_abc' => 7, 'marker' => 'kept']);

        spa()->postJson('/api/admin/logout')->assertNoContent();

        expect(session('login_client_abc'))->toBe(7)->and(session('marker'))->toBe('kept');
    });

    it('is harmless when nobody is signed in', function () {
        spa()->postJson('/api/admin/logout')->assertNoContent();
    });
});

describe('password reset', function () {
    it('sends a reset link to an existing admin', function () {
        Notification::fake();
        $admin = adminWithRole('Sales');

        spa()->postJson('/api/admin/forgot-password', ['email' => $admin->email])
            ->assertOk()
            ->assertExactJson(['message' => 'If that email belongs to an admin account, we have sent a password reset link.']);

        Notification::assertSentTo($admin, AdminResetPasswordNotification::class);
    });

    it('gives the same answer for an unknown email and sends nothing', function () {
        Notification::fake();
        $admin = adminWithRole('Sales');

        $known = spa()->postJson('/api/admin/forgot-password', ['email' => $admin->email]);
        $unknown = spa()->postJson('/api/admin/forgot-password', ['email' => 'nobody@example.com']);

        expect($unknown->status())->toBe($known->status())->and($unknown->json())->toBe($known->json());
        Notification::assertSentToTimes($admin, AdminResetPasswordNotification::class, 1);
        Notification::assertCount(1);
    });

    it('builds the link to the admin panel with the token and email', function () {
        $admin = adminWithRole('Sales');
        $mail = (new AdminResetPasswordNotification('tok123'))->toMail($admin);

        expect($mail->actionUrl)->toBe('http://localhost:3003/reset-password?token=tok123&email='.urlencode($admin->email))
            ->and($mail->outroLines[0])->toContain('60 minutes');
    });

    it('resets the password with a valid token', function () {
        $admin = adminWithRole('Sales', [], ['password' => 'Old-password-123', 'failed_login_attempts' => 5, 'locked_until' => now()->addMinutes(10)]);
        $token = Password::broker('admins')->createToken($admin);

        spa()->postJson('/api/admin/reset-password', [
            'token' => $token, 'email' => $admin->email, 'password' => STRONG_PASSWORD, 'password_confirmation' => STRONG_PASSWORD,
        ])->assertOk()->assertJsonStructure(['message']);

        $admin->refresh();
        expect(Hash::check(STRONG_PASSWORD, $admin->password))->toBeTrue()
            ->and($admin->locked_until)->toBeNull()
            ->and($admin->failed_login_attempts)->toBe(0);

        login($admin->email, STRONG_PASSWORD)->assertOk();
    });

    it('rejects the token a second time', function () {
        $admin = adminWithRole('Sales');
        $token = Password::broker('admins')->createToken($admin);
        $payload = ['token' => $token, 'email' => $admin->email, 'password' => STRONG_PASSWORD, 'password_confirmation' => STRONG_PASSWORD];

        spa()->postJson('/api/admin/reset-password', $payload)->assertOk();
        spa()->postJson('/api/admin/reset-password', $payload)->assertStatus(422)->assertJsonPath('code', 'invalid_reset_token');
    });

    it('rejects a wrong token, an unknown email and an expired token alike', function () {
        $admin = adminWithRole('Sales');
        $token = Password::broker('admins')->createToken($admin);
        $body = fn (array $over) => ['token' => $token, 'email' => $admin->email, 'password' => STRONG_PASSWORD, 'password_confirmation' => STRONG_PASSWORD, ...$over];

        $wrong = spa()->postJson('/api/admin/reset-password', $body(['token' => 'nope']))->assertStatus(422)->assertJsonPath('code', 'invalid_reset_token');
        $unknown = spa()->postJson('/api/admin/reset-password', $body(['email' => 'nobody@example.com']))->assertStatus(422);
        expect($unknown->json())->toBe($wrong->json());

        $this->travel(61)->minutes();
        spa()->postJson('/api/admin/reset-password', $body([]))->assertStatus(422)->assertJsonPath('code', 'invalid_reset_token');
    });

    it('enforces the password rules', function (string $password) {
        $admin = adminWithRole('Sales');
        $token = Password::broker('admins')->createToken($admin);

        spa()->postJson('/api/admin/reset-password', [
            'token' => $token, 'email' => $admin->email, 'password' => $password, 'password_confirmation' => $password,
        ])->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('password');
    })->with(['too short' => 'Aa1-short', 'no uppercase' => 'lowercase-only-123', 'no lowercase' => 'UPPERCASE-ONLY-123', 'no number' => 'No-Numbers-In-Here']);

    it('requires the confirmation to match', function () {
        $admin = adminWithRole('Sales');
        $token = Password::broker('admins')->createToken($admin);

        spa()->postJson('/api/admin/reset-password', [
            'token' => $token, 'email' => $admin->email, 'password' => STRONG_PASSWORD, 'password_confirmation' => 'Different-Pass-9',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    });
});
