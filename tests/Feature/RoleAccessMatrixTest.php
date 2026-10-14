<?php

use App\Models\Admin;
use App\Models\ClientAccount;
use App\Models\Role;
use Database\Seeders\DemoStaffSeeder;
use Illuminate\Support\Facades\Notification;

/*
 * Every default role against every endpoint that exists: the API must answer 200/201/204 only when the role holds
 * the permission, and 403 otherwise, whatever the frontend shows or hides.
 */

beforeEach(function () {
    // The seeder only runs in "local". Switch back to "testing" afterwards, because Laravel skips the CSRF check
    // only while the environment is "testing".
    app()->detectEnvironment(fn () => 'local');
    $this->seed(DemoStaffSeeder::class);
    app()->detectEnvironment(fn () => 'testing');
    Notification::fake();
});

/** [method, uri, body-or-null, permission needed] — ids are filled in per test. */
function endpointsNeedingPermissions(): array
{
    $sales = Role::where('name', 'Sales')->where('guard_name', 'admin')->firstOrFail();
    $support = Role::where('name', 'Support Staff')->where('guard_name', 'admin')->firstOrFail();
    $victim = Admin::factory()->create();
    $victim->assignRole($support);
    $spare = Role::create(['name' => 'Spare', 'guard_name' => 'admin']);
    $edit = ['name' => 'Sales', 'description' => 'x', 'permissions' => ['clients.view']];

    return [
        ['GET', '/api/admin/clients', null, 'clients.view'],
        ['GET', '/api/admin/permissions', null, 'roles.view'],
        ['GET', '/api/admin/roles', null, 'roles.view'],
        ['GET', "/api/admin/roles/{$sales->id}", null, 'roles.view'],
        ['POST', '/api/admin/roles', ['name' => 'Matrix '.str()->random(4), 'description' => ''], 'roles.create'],
        ['PUT', "/api/admin/roles/{$sales->id}", $edit, 'roles.edit'],
        ['DELETE', "/api/admin/roles/{$spare->id}", null, 'roles.delete'],
        ['GET', '/api/admin/staff', null, 'staff.view'],
        ['GET', "/api/admin/staff/{$victim->id}", null, 'staff.view'],
        ['POST', '/api/admin/staff', ['name' => 'Matrix Person', 'email' => 'matrix@example.com', 'phone' => null, 'role' => $support->id], 'staff.create'],
        ['PUT', "/api/admin/staff/{$victim->id}", ['name' => 'Renamed', 'phone' => null, 'role' => $support->id, 'status' => 'active'], 'staff.edit'],
        ['POST', "/api/admin/staff/{$victim->id}/resend-invite", null, 'staff.create'],
        ['DELETE', "/api/admin/staff/{$victim->id}", null, 'staff.delete'],
    ];
}

it('creates one test admin per default role, all able to sign in', function () {
    expect(Admin::whereIn('email', array_column(DemoStaffSeeder::ACCOUNTS, 1))->count())->toBe(4);

    foreach (DemoStaffSeeder::ACCOUNTS as $roleName => [$name, $email]) {
        $admin = Admin::where('email', $email)->sole();
        expect($admin->hasRole($roleName))->toBeTrue()->and($admin->name)->toBe($name);

        $response = test()->withHeaders(['Origin' => 'http://localhost:3003'])
            ->postJson('/api/admin/login', ['email' => $email, 'password' => DemoStaffSeeder::PASSWORD, 'remember' => false]);
        $response->assertOk()->assertJsonPath('data.role.name', $roleName)->assertJsonPath('data.user.email', $email);
        auth('admin')->logout();
    }
});

it('does not create them outside local, and does not duplicate them', function () {
    Admin::query()->forceDelete();

    $this->seed(DemoStaffSeeder::class); // the environment is "testing"
    expect(Admin::count())->toBe(0);

    app()->detectEnvironment(fn () => 'local');
    $this->seed(DemoStaffSeeder::class);
    $this->seed(DemoStaffSeeder::class);
    expect(Admin::count())->toBe(4);
});

it('answers every endpoint exactly as each role\'s permissions allow', function (string $roleName) {
    $admin = Admin::where('email', DemoStaffSeeder::ACCOUNTS[$roleName][1])->sole();
    $granted = $admin->getAllPermissions()->pluck('name')->all();
    ClientAccount::factory()->count(2)->create();

    $checked = 0;
    foreach (endpointsNeedingPermissions() as [$method, $uri, $body, $permission]) {
        $response = api($admin)->json($method, $uri, $body ?? []);
        $allowed = in_array($permission, $granted, true);

        if ($allowed) {
            // Allowed by permission: it may still be refused by a business rule (422/403 with a specific code), never by "forbidden".
            expect($response->json('code'))->not->toBe('forbidden', "{$roleName} should be allowed {$method} {$uri}");
        } else {
            expect($response->status())->toBe(403, "{$roleName} must get 403 for {$method} {$uri}")
                ->and($response->json('code'))->toBe('forbidden');
        }
        $checked++;
    }

    expect($checked)->toBe(13);
})->with(['Admin', 'Billing Manager', 'Support Staff', 'Sales']);

it('gives each role the menu permissions the sidebar relies on', function () {
    $has = fn (string $role) => Admin::where('email', DemoStaffSeeder::ACCOUNTS[$role][1])->sole()->getAllPermissions()->pluck('name')->all();

    // Admin: no staff, no roles, settings view only.
    expect($has('Admin'))->toContain('clients.view', 'settings.view')->not->toContain('staff.view', 'roles.view', 'settings.edit');
    // Billing Manager: money, no clients create, no tickets/servers.
    expect($has('Billing Manager'))->toContain('invoices.view', 'invoices.refund', 'clients.view', 'reports.view')
        ->not->toContain('clients.create', 'tickets.view', 'servers.view', 'staff.view', 'roles.view');
    // Support Staff: tickets, services, domains; no invoices.
    expect($has('Support Staff'))->toContain('tickets.view', 'services.view', 'domains.view', 'clients.view')
        ->not->toContain('invoices.view', 'orders.view', 'reports.view', 'clients.create');
    // Sales: clients and orders, products; no invoices.
    expect($has('Sales'))->toContain('clients.view', 'clients.create', 'orders.view', 'products.view')
        ->not->toContain('invoices.view', 'services.view', 'staff.view', 'roles.view');
});
