<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\Role;
use Illuminate\Database\Seeder;
use RuntimeException;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $name = trim((string) config('everdigitech.super_admin.name'));
        $email = strtolower(trim((string) config('everdigitech.super_admin.email')));
        $password = (string) config('everdigitech.super_admin.password');

        if ($name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Set SUPER_ADMIN_NAME and a valid SUPER_ADMIN_EMAIL in .env before seeding.');
        }
        if (mb_strlen($password) < 12) {
            throw new RuntimeException('SUPER_ADMIN_PASSWORD is missing or shorter than 12 characters. Nothing was created.');
        }

        // Creates the locked Super Admin role holding every permission (and the other default roles).
        $this->call(RoleSeeder::class);
        $role = Role::where(['name' => Admin::SUPER_ADMIN_ROLE, 'guard_name' => 'admin'])->firstOrFail();

        // An existing account keeps its password: re-running the seeder never resets it.
        $admin = Admin::withTrashed()->firstOrCreate(['email' => $email], ['name' => $name, 'password' => $password]);
        if ($admin->trashed()) {
            $admin->restore();
        }

        $admin->syncRoles([$role]);
    }
}
