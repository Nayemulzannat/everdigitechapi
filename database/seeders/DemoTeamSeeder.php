<?php

namespace Database\Seeders;

use App\Enums\AdminStatus;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * A realistic team for the Staff page (people in every state: signed in, two-factor on and off, suspended, still
 * invited). Only runs when APP_ENV=local. Passwords are "Demo-Password-123". Safe to re-run: existing people are
 * left as they are.
 */
class DemoTeamSeeder extends Seeder
{
    /** [name, email, role, state, two-factor, browser, platform, last sign-in, ip] */
    private const TEAM = [
        ['Rakibul Islam', 'rakib@everdigitech.test', 'Admin', 'active', true, 'Chrome', 'macOS', '-3 hours', '103.112.1.14'],
        ['Tasnim Ferdous', 'tasnim@everdigitech.test', 'Admin', 'active', true, 'Edge', 'Windows', '-1 day', '103.112.4.80'],
        ['Shafiqul Alam', 'shafiq@everdigitech.test', 'Billing Manager', 'active', true, 'Chrome', 'Windows', '-5 hours', '103.112.1.22'],
        ['Mitu Akter', 'mitu@everdigitech.test', 'Billing Manager', 'active', false, 'Safari', 'iOS', '-6 hours', '103.112.7.5'],
        ['Jahid Hasan', 'jahid@everdigitech.test', 'Support Staff', 'active', true, 'Chrome', 'Windows', '-2 hours', '103.112.1.9'],
        ['Sumaiya Rahman', 'sumaiya@everdigitech.test', 'Support Staff', 'active', false, 'Chrome', 'Android', '-4 hours', '45.64.200.3'],
        ['Nadia Chowdhury', 'nadia@everdigitech.test', 'Support Staff', 'active', true, 'Firefox', 'Windows', '-6 days', '103.112.1.31'],
        ['Arman Hossain', 'arman@everdigitech.test', 'Support Staff', 'active', false, 'Chrome', 'Windows', '-7 days', '27.147.9.60'],
        ['Imran Kabir', 'imran@everdigitech.test', 'Support Staff', 'suspended', false, 'Chrome', 'Windows', '-21 days', '103.112.1.77'],
        ['Rezaul Karim', 'rezaul@everdigitech.test', 'Sales', 'invited', false, null, null, null, null],
    ];

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('DemoTeamSeeder only runs when APP_ENV=local. Nothing created.');

            return;
        }

        $this->call(RoleSeeder::class);
        $boss = Admin::whereHas('roles', fn ($q) => $q->where('name', Admin::SUPER_ADMIN_ROLE))->orderBy('id')->first();

        foreach (self::TEAM as [$name, $email, $roleName, $state, $twoFactor, $browser, $platform, $lastSignIn, $ip]) {
            if (Admin::withTrashed()->where('email', $email)->exists()) {
                continue;
            }

            $signedInAt = $lastSignIn ? now()->modify($lastSignIn) : null;
            $admin = Admin::create([
                'name' => $name,
                'email' => $email,
                'password' => DemoStaffSeeder::PASSWORD,
                'status' => $state === 'suspended' ? AdminStatus::Suspended : AdminStatus::Active,
            ]);
            $admin->forceFill([
                'invited_at' => $state === 'invited' ? now()->subDays(2) : null,
                'last_login_at' => $signedInAt,
                'last_login_ip' => $ip,
                'two_factor_secret' => $twoFactor ? 'JBSWY3DPEHPK3PXP' : null,
                'two_factor_confirmed_at' => $twoFactor ? now()->subDays(30) : null,
                'created_at' => now()->subDays(60),
            ])->save();
            $admin->syncRoles([Role::where(['name' => $roleName, 'guard_name' => 'admin'])->firstOrFail()]);

            if ($signedInAt) {
                $admin->loginHistories()->create([
                    'email_attempted' => $email, 'ip_address' => $ip, 'browser' => $browser, 'platform' => $platform,
                    'successful' => true, 'created_at' => $signedInAt,
                ]);
            }

            if ($state === 'suspended' && $boss) {
                ActivityLog::create([
                    'actor_type' => $boss->getMorphClass(), 'actor_id' => $boss->getKey(),
                    'action' => 'staff.suspended', 'subject_type' => $admin->getMorphClass(), 'subject_id' => $admin->getKey(),
                    'properties' => ['before' => ['status' => 'active'], 'after' => ['status' => 'suspended']],
                    'created_at' => now()->subDays(7),
                ]);
            }
        }
    }
}
