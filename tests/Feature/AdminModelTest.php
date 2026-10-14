<?php

use App\Enums\AdminStatus;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\AdminLoginHistory;
use App\Services\ActivityLogger;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;

it('creates an admin with casts and hidden secrets', function () {
    $admin = Admin::factory()->create(['two_factor_secret' => 'JBSWY3DPEHPK3PXP']);

    expect($admin->status)->toBe(AdminStatus::Active)
        ->and(Guard::getNames($admin)->all())->toBe(['admin'])
        ->and($admin->toArray())->not->toHaveKeys(['password', 'remember_token', 'two_factor_secret']);

    // Stored encrypted, read back decrypted.
    expect(DB::table('admins')->where('id', $admin->id)->value('two_factor_secret'))->not->toBe('JBSWY3DPEHPK3PXP')
        ->and($admin->fresh()->two_factor_secret)->toBe('JBSWY3DPEHPK3PXP');
});

it('knows when it is suspended, locked or has 2FA', function () {
    expect(Admin::factory()->suspended()->make()->isSuspended())->toBeTrue()
        ->and(Admin::factory()->locked()->make()->isLocked())->toBeTrue()
        ->and(Admin::factory()->make()->isLocked())->toBeFalse()
        ->and(Admin::factory()->make()->hasTwoFactorEnabled())->toBeFalse();
});

it('soft deletes and records login history', function () {
    $admin = Admin::factory()->create();
    $admin->loginHistories()->create(['email_attempted' => $admin->email, 'successful' => true]);
    $admin->delete();

    expect(Admin::find($admin->id))->toBeNull()
        ->and(Admin::withTrashed()->find($admin->id))->not->toBeNull()
        ->and($admin->loginHistories()->count())->toBe(1);
});

it('keeps failed attempts for unknown emails', function () {
    $history = AdminLoginHistory::create([
        'email_attempted' => 'nobody@example.com', 'successful' => false, 'failure_reason' => 'invalid_credentials',
    ]);

    expect($history->admin_id)->toBeNull()->and($history->fresh()->successful)->toBeFalse();
});

it('assigns spatie roles on the admin guard', function () {
    $admin = Admin::factory()->create();
    $admin->assignRole(Role::findOrCreate('Support Staff', 'admin'));

    expect($admin->hasRole('Support Staff'))->toBeTrue();
});

it('logs activity with the signed-in admin as actor', function () {
    $admin = Admin::factory()->create();
    $this->actingAs($admin, 'admin');

    app(ActivityLogger::class)->log('admin.updated', $admin, ['before' => ['name' => 'A'], 'after' => ['name' => 'B']]);

    $log = ActivityLog::first();
    expect($log->action)->toBe('admin.updated')
        ->and($log->actor->is($admin))->toBeTrue()
        ->and($log->subject->is($admin))->toBeTrue()
        ->and($log->properties['after']['name'])->toBe('B');
});

it('logs as the system when nobody is signed in', function () {
    app(ActivityLogger::class)->log('cron.ran');

    expect(ActivityLog::first())->actor_type->toBeNull()->subject_type->toBeNull();
});

describe('SuperAdminSeeder', function () {
    it('creates the Super Admin once', function () {
        config(['everdigitech.super_admin' => ['name' => 'Boss', 'email' => 'Boss@Example.com', 'password' => 'a-long-password-1']]);

        $this->seed(SuperAdminSeeder::class);
        $this->seed(SuperAdminSeeder::class);

        $admin = Admin::where('email', 'boss@example.com')->sole();
        expect($admin->hasRole('Super Admin'))->toBeTrue()
            ->and(Admin::count())->toBe(1)
            ->and(Hash::check('a-long-password-1', $admin->password))->toBeTrue();
    });

    it('refuses a short or missing password', function (?string $password) {
        config(['everdigitech.super_admin' => ['name' => 'Boss', 'email' => 'boss@example.com', 'password' => $password]]);

        expect(fn () => $this->seed(SuperAdminSeeder::class))->toThrow(RuntimeException::class);
        expect(Admin::count())->toBe(0);
    })->with([null, '', 'short-pass1']);
});
