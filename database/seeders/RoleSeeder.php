<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\Role;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Default roles, with the same default grants as the admin panel's mock (admin/lib/mock/roles.ts).
 * Safe to re-run: a role that exists keeps the permissions someone gave it. Super Admin is the exception: it is
 * locked and always holds every permission.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PermissionSeeder::class);

        $all = PermissionCatalog::keys();

        $roles = [
            [Admin::SUPER_ADMIN_ROLE, 'Full control, including staff and roles.', true, $all],
            ['Admin', 'Runs the business day to day, without staff or settings.', false, array_filter(
                $all, fn (string $k) => ! preg_match('/^(staff|roles)\./', $k) && $k !== 'settings.edit',
            )],
            ['Billing Manager', 'Invoices, payments, refunds and orders.', false, [
                'clients.view', 'orders.view', 'orders.edit', 'orders.approve', 'invoices.view', 'invoices.create', 'invoices.edit',
                'invoices.delete', 'invoices.refund', 'payments.view', 'payments.create', 'payments.edit', 'payments.approve',
                'reports.view', 'reports.export',
            ]],
            ['Support Staff', 'Answers tickets and helps with hosting and domains.', false, [
                'clients.view', 'services.view', 'services.server_login', 'domains.view', 'domains.dns',
                'tickets.view', 'tickets.create', 'tickets.edit', 'tickets.assign',
            ]],
            ['Sales', 'Creates clients and orders, sees products.', false, [
                'clients.view', 'clients.create', 'clients.edit', 'orders.view', 'orders.create', 'products.view', 'tickets.view',
            ]],
        ];

        foreach ($roles as [$name, $description, $locked, $permissions]) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'admin'], ['description' => $description, 'is_locked' => $locked]);

            if ($role->wasRecentlyCreated || $locked) {
                $role->forceFill(['is_locked' => $locked])->save();
                $role->syncPermissions(PermissionCatalog::normalize($permissions));
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
