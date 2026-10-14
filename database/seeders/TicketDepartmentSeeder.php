<?php

namespace Database\Seeders;

use App\Models\TicketDepartment;
use Illuminate\Database\Seeder;

/** The departments a client can choose when opening a ticket. Safe to re-run. */
class TicketDepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            ['Technical Support', 'Hosting, servers, email, websites and anything that is not working.'],
            ['Billing', 'Invoices, payments, refunds and renewals.'],
            ['Sales', 'Questions before you buy, upgrades and custom plans.'],
            ['Domains', 'Registering, transferring and managing domain names and DNS.'],
        ];

        foreach ($departments as $order => [$name, $description]) {
            TicketDepartment::firstOrCreate(['name' => $name], ['description' => $description, 'sort_order' => $order + 1]);
        }
    }
}
