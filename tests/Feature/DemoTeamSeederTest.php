<?php

use App\Models\ActivityLog;
use App\Models\Admin;
use Database\Seeders\DemoTeamSeeder;
use Database\Seeders\SuperAdminSeeder;

it('does nothing outside the local environment', function () {
    $this->seed(DemoTeamSeeder::class); // APP_ENV is "testing" here

    expect(Admin::count())->toBe(0);
});

describe('in local', function () {
    beforeEach(function () {
        app()->detectEnvironment(fn () => 'local');
        config(['everdigitech.super_admin' => ['name' => 'Boss', 'email' => 'boss@example.com', 'password' => 'a-long-password-1']]);
        $this->seed(SuperAdminSeeder::class);
        $this->seed(DemoTeamSeeder::class);
    });

    it('creates ten people in every state the Staff page shows', function () {
        $team = Admin::where('email', 'like', '%@everdigitech.test')->get();

        expect($team)->toHaveCount(10)
            ->and($team->filter(fn ($a) => $a->displayStatus() === 'invited'))->toHaveCount(1)
            ->and($team->filter(fn ($a) => $a->displayStatus() === 'suspended'))->toHaveCount(1)
            ->and($team->filter(fn ($a) => $a->displayStatus() === 'active'))->toHaveCount(8)
            ->and($team->filter->hasTwoFactorEnabled())->toHaveCount(5)
            ->and($team->every(fn ($a) => $a->roles->count() === 1))->toBeTrue();
    });

    it('gives the invited person no sign-in and the others a device', function () {
        $invited = Admin::where('email', 'rezaul@everdigitech.test')->sole();
        $active = Admin::where('email', 'rakib@everdigitech.test')->sole();

        expect($invited->invited_at)->not->toBeNull()->and($invited->last_login_at)->toBeNull()->and($invited->loginHistories()->count())->toBe(0)
            ->and($active->loginHistories()->sole())->browser->toBe('Chrome')->platform->toBe('macOS');
    });

    it('records who suspended the suspended person', function () {
        $suspended = Admin::where('email', 'imran@everdigitech.test')->sole();
        $log = ActivityLog::where('action', 'staff.suspended')->where('subject_id', $suspended->id)->sole();

        expect($suspended->isSuspended())->toBeTrue()->and($log->actor->email)->toBe('boss@example.com');
    });

    it('does not duplicate anyone when run again', function () {
        $this->seed(DemoTeamSeeder::class);

        expect(Admin::where('email', 'like', '%@everdigitech.test')->count())->toBe(10);
    });
});
