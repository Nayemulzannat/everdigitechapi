<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([PermissionSeeder::class, RoleSeeder::class, SuperAdminSeeder::class, TicketDepartmentSeeder::class, ProductGroupSeeder::class, DomainExtensionSeeder::class]);

        // Fake data for development only (each seeder also refuses to run outside APP_ENV=local).
        if (app()->environment('local')) {
            $this->call([DemoStaffSeeder::class, DemoTeamSeeder::class, DemoDataSeeder::class]);
        }
    }
}
