<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * One test admin per default role, to check what each role sees. Only runs when APP_ENV=local.
 * Every account's password is "Demo-Password-123". Safe to re-run: existing accounts are left as they are.
 */
class DemoStaffSeeder extends Seeder
{
    public const PASSWORD = 'Demo-Password-123';

    /** role => [name, email] */
    public const ACCOUNTS = [
        'Admin' => ['Arif Hasan', 'staff.admin@everdigitech.test'],
        'Billing Manager' => ['Nusrat Jahan', 'staff.billing@everdigitech.test'],
        'Support Staff' => ['Sabbir Hossain', 'staff.support@everdigitech.test'],
        'Sales' => ['Farhana Akter', 'staff.sales@everdigitech.test'],
    ];

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('DemoStaffSeeder only runs when APP_ENV=local. Nothing created.');

            return;
        }

        $this->call(RoleSeeder::class);

        foreach (self::ACCOUNTS as $roleName => [$name, $email]) {
            $role = Role::where(['name' => $roleName, 'guard_name' => 'admin'])->firstOrFail();

            $admin = Admin::withTrashed()->firstOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => self::PASSWORD, 'last_login_at' => now()],
            );
            if ($admin->trashed()) {
                $admin->restore();
            }

            $admin->syncRoles([$role]);
        }
    }
}
