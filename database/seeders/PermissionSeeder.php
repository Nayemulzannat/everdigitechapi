<?php

namespace Database\Seeders;

use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/** Makes the `admin` permissions match config/permissions.php: adds missing ones, removes ones no longer listed. */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $keys = PermissionCatalog::keys();

        foreach ($keys as $name) {
            Permission::findOrCreate($name, 'admin');
        }

        // Deleting a permission also detaches it from every role.
        Permission::query()->where('guard_name', 'admin')->whereNotIn('name', $keys)->get()->each->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
