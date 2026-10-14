<?php

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Role;
use App\Support\PermissionCatalog;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

describe('catalog and seeders', function () {
    it('has the 13 modules and 54 permissions of the admin panel', function () {
        expect(PermissionCatalog::modules())->toHaveCount(13)
            ->and(PermissionCatalog::keys())->toHaveCount(54)
            ->and(Permission::where('guard_name', 'admin')->count())->toBe(54);
    });

    it('makes the permission seeder idempotent and removes permissions no longer in the config', function () {
        Permission::create(['name' => 'legacy.view', 'guard_name' => 'admin']);
        roleNamed('Sales')->givePermissionTo('legacy.view');

        $this->seed(PermissionSeeder::class);
        $this->seed(PermissionSeeder::class);

        expect(Permission::where('guard_name', 'admin')->count())->toBe(54)
            ->and(Permission::where('name', 'legacy.view')->exists())->toBeFalse()
            ->and(roleNamed('Sales')->permissions->pluck('name'))->not->toContain('legacy.view');
    });

    it('creates the default roles with the frontend defaults', function () {
        expect(Role::where('guard_name', 'admin')->orderBy('id')->pluck('name')->all())
            ->toBe(['Super Admin', 'Admin', 'Billing Manager', 'Support Staff', 'Sales']);

        $granted = fn (string $name) => roleNamed($name)->permissions->pluck('name')->all();

        expect($granted('Super Admin'))->toHaveCount(54)
            ->and($granted('Admin'))->toHaveCount(45)->not->toContain('staff.view', 'roles.view', 'settings.edit')->toContain('settings.view')
            ->and($granted('Billing Manager'))->toHaveCount(15)->toContain('invoices.refund', 'payments.approve', 'reports.export')
            ->and($granted('Support Staff'))->toHaveCount(9)->toContain('services.server_login', 'tickets.assign')
            ->and($granted('Sales'))->toHaveCount(7)->toContain('clients.create', 'products.view');

        expect(roleNamed('Super Admin'))->is_locked->toBeTrue()->description->toBe('Full control, including staff and roles.')
            ->and(roleNamed('Sales'))->is_locked->toBeFalse();
    });

    it('keeps edits when the role seeder runs again, but keeps Super Admin complete', function () {
        roleNamed('Sales')->syncPermissions(['clients.view']);
        roleNamed('Super Admin')->syncPermissions(['clients.view']);

        $this->seed(RoleSeeder::class);

        expect(roleNamed('Sales')->permissions->pluck('name')->all())->toBe(['clients.view'])
            ->and(roleNamed('Super Admin')->permissions)->toHaveCount(54)
            ->and(Role::where('guard_name', 'admin')->count())->toBe(5);
    });
});

describe('Super Admin gate', function () {
    it('passes every check for a Super Admin, even unknown abilities', function () {
        $sa = superAdmin();

        expect(Gate::forUser($sa)->allows('roles.delete'))->toBeTrue()
            ->and(Gate::forUser($sa)->allows('anything.at.all'))->toBeTrue();
    });

    it('does not pass for other admins', function () {
        $admin = staffWith(['clients.view']);

        expect(Gate::forUser($admin)->allows('clients.view'))->toBeTrue()
            ->and(Gate::forUser($admin)->allows('clients.delete'))->toBeFalse();
    });
});

describe('GET /permissions', function () {
    it('returns the catalog grouped by module', function () {
        $response = api(staffWith(['roles.view']))->getJson('/api/admin/permissions')->assertOk();

        expect($response->json('data'))->toHaveCount(13)
            ->and($response->json('data.0'))->toBe([
                'key' => 'clients', 'label' => 'Clients', 'description' => 'Profiles, contacts and notes',
                'actions' => ['view', 'create', 'edit', 'delete'], 'special' => ['action' => 'login_as', 'label' => 'Log in as client'],
            ])
            ->and($response->json('data.6.special'))->toBeNull()
            ->and($response->json('data.3.actions'))->toBe(['view', 'create', 'edit']);
    });

    it('needs roles.view', function () {
        api(staffWith(['clients.view']))->getJson('/api/admin/permissions')->assertStatus(403)->assertJsonPath('code', 'forbidden');
    });

    it('needs a login', function () {
        test()->withHeaders(['Origin' => 'http://localhost:3003'])->getJson('/api/admin/permissions')->assertStatus(401);
    });
});

describe('GET /roles', function () {
    it('lists every role in the frontend shape', function () {
        $sales = roleNamed('Sales');
        Admin::factory()->count(2)->create()->each->assignRole($sales);
        Admin::factory()->create()->assignRole($sales)->delete(); // a deleted admin doesn't count
        $viewer = staffWith(['roles.view']);

        $response = api($viewer)->getJson('/api/admin/roles')->assertOk();

        $roles = collect($response->json('data'));
        expect($roles)->toHaveCount(6)
            ->and($roles->first())->toBe([
                'id' => (string) roleNamed('Super Admin')->id, 'name' => 'Super Admin', 'description' => 'Full control, including staff and roles.',
                'locked' => true, 'staffCount' => 0, 'permissions' => PermissionCatalog::keys(),
            ]);

        $salesRow = $roles->firstWhere('name', 'Sales');
        expect($salesRow['staffCount'])->toBe(2)
            ->and($salesRow['locked'])->toBeFalse()
            ->and($salesRow['permissions'])->toBe(['clients.view', 'clients.create', 'clients.edit', 'orders.view', 'orders.create', 'products.view', 'tickets.view']);
    });

    it('needs roles.view', function () {
        api(staffWith(['roles.edit']))->getJson('/api/admin/roles')->assertStatus(403);
    });

    it('shows one role, and 404s for an unknown id', function () {
        $viewer = staffWith(['roles.view']);

        api($viewer)->getJson('/api/admin/roles/'.roleNamed('Support Staff')->id)
            ->assertOk()->assertJsonPath('data.name', 'Support Staff')->assertJsonCount(9, 'data.permissions');
        api($viewer)->getJson('/api/admin/roles/99999')->assertStatus(404)->assertJsonPath('code', 'not_found');
        api($viewer)->getJson('/api/admin/roles/super')->assertStatus(404);
    });

    it('ignores roles of other guards', function () {
        $clientRole = Role::create(['name' => 'Reseller', 'guard_name' => 'client']);

        api(staffWith(['roles.view']))->getJson('/api/admin/roles/'.$clientRole->id)->assertStatus(404);
    });
});

describe('POST /roles', function () {
    it('creates a role with no permissions', function () {
        $response = api(staffWith(['roles.view', 'roles.create']))
            ->postJson('/api/admin/roles', ['name' => '  Content Editor ', 'description' => 'Writes copy'])
            ->assertCreated();

        $response->assertJsonPath('data.name', 'Content Editor')
            ->assertJsonPath('data.description', 'Writes copy')
            ->assertJsonPath('data.locked', false)
            ->assertJsonPath('data.staffCount', 0)
            ->assertJsonPath('data.permissions', []);

        expect(roleNamed('Content Editor')->guard_name)->toBe('admin');
    });

    it('needs roles.create', function () {
        api(staffWith(['roles.view', 'roles.edit']))->postJson('/api/admin/roles', ['name' => 'X', 'description' => ''])->assertStatus(403);
        expect(Role::where('name', 'X')->exists())->toBeFalse();
    });

    it('rejects a duplicate name, ignoring case and spaces', function () {
        $admin = staffWith(['roles.create']);

        api($admin)->postJson('/api/admin/roles', ['name' => 'sales', 'description' => ''])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('name');
        api($admin)->postJson('/api/admin/roles', ['name' => ' Super Admin ', 'description' => ''])->assertStatus(422);
    });

    it('allows the same name as a role of another guard', function () {
        Role::create(['name' => 'Reseller', 'guard_name' => 'client']);

        api(staffWith(['roles.create']))->postJson('/api/admin/roles', ['name' => 'Reseller', 'description' => ''])->assertCreated();
    });

    it('validates the body', function () {
        api(staffWith(['roles.create']))->postJson('/api/admin/roles', ['name' => ''])
            ->assertStatus(422)->assertJsonValidationErrors(['name', 'description']);
    });
});

describe('PUT /roles/{id}', function () {
    function update(Admin $admin, Role $role, array $body)
    {
        return api($admin)->putJson('/api/admin/roles/'.$role->id, $body);
    }

    it('updates name, description and permissions', function () {
        $role = roleNamed('Sales');

        update(staffWith(['roles.view', 'roles.edit']), $role, [
            'name' => 'Sales Team', 'description' => 'New text', 'permissions' => ['orders.create', 'orders.view', 'clients.view'],
        ])->assertOk()
            ->assertJsonPath('data.name', 'Sales Team')
            ->assertJsonPath('data.description', 'New text')
            ->assertJsonPath('data.permissions', ['clients.view', 'orders.view', 'orders.create']); // catalog order

        expect($role->fresh()->permissions->pluck('name')->sort()->values()->all())->toBe(['clients.view', 'orders.create', 'orders.view']);
    });

    it('returns 403 without roles.edit, and changes nothing', function () {
        $role = roleNamed('Sales');

        update(staffWith(['roles.view', 'roles.create', 'roles.delete']), $role, ['name' => 'Hacked', 'description' => '', 'permissions' => []])
            ->assertStatus(403)->assertJsonPath('code', 'forbidden');

        expect($role->fresh()->name)->toBe('Sales')->and($role->permissions)->toHaveCount(7);
    });

    it('refuses to edit a locked role, even for a Super Admin', function () {
        $locked = roleNamed('Super Admin');

        update(superAdmin(), $locked, ['name' => 'Boss', 'description' => '', 'permissions' => []])
            ->assertStatus(403)->assertJsonPath('code', 'role_locked');
        // The lock is checked before the body, so a bad body is still a 403.
        update(superAdmin(), $locked, [])->assertStatus(403)->assertJsonPath('code', 'role_locked');

        expect($locked->fresh()->name)->toBe('Super Admin')->and($locked->permissions)->toHaveCount(54);
    });

    it('keeps role names unique', function () {
        update(staffWith(['roles.edit']), roleNamed('Sales'), ['name' => 'support staff', 'description' => '', 'permissions' => []])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    });

    it('lets a role keep its own name', function () {
        update(staffWith(['roles.edit']), roleNamed('Sales'), ['name' => 'Sales', 'description' => 'Same name', 'permissions' => ['clients.view']])->assertOk();
    });

    it('rejects permission names that are not in the config', function () {
        update(staffWith(['roles.edit']), roleNamed('Sales'), ['name' => 'Sales', 'description' => '', 'permissions' => ['clients.view', 'clients.fly', 'nope']])
            ->assertStatus(422)->assertJsonValidationErrors(['permissions.1', 'permissions.2']);

        expect(roleNamed('Sales')->permissions)->toHaveCount(7);
    });

    it('requires the permissions list', function () {
        update(staffWith(['roles.edit']), roleNamed('Sales'), ['name' => 'Sales', 'description' => ''])->assertStatus(422)->assertJsonValidationErrors('permissions');
    });

    it('adds a module\'s view permission when it is missing', function () {
        $response = update(staffWith(['roles.edit']), roleNamed('Sales'), [
            'name' => 'Sales', 'description' => '', 'permissions' => ['invoices.refund', 'payments.approve', 'reports.export', 'settings.edit'],
        ])->assertOk();

        expect($response->json('data.permissions'))->toBe([
            'invoices.view', 'invoices.refund', 'payments.view', 'payments.approve', 'reports.view', 'reports.export', 'settings.view', 'settings.edit',
        ]);
    });

    it('only lets a Super Admin give staff.* or roles.* permissions', function (string $permission) {
        $role = roleNamed('Sales');
        $body = ['name' => 'Sales', 'description' => '', 'permissions' => ['clients.view', $permission]];

        update(staffWith(['roles.edit']), $role, $body)->assertStatus(403)->assertJsonPath('code', 'super_admin_only');
        expect($role->fresh()->permissions->pluck('name'))->not->toContain($permission);

        update(superAdmin(), $role, $body)->assertOk()->assertJsonFragment([$permission]);
        expect($role->fresh()->permissions->pluck('name'))->toContain($permission);
    })->with(['staff.view', 'staff.delete', 'roles.view', 'roles.edit']);

    it('counts the view added by the dependency rule as a grant', function () {
        update(staffWith(['roles.edit']), roleNamed('Sales'), ['name' => 'Sales', 'description' => '', 'permissions' => ['staff.edit']])
            ->assertStatus(403)->assertJsonPath('code', 'super_admin_only');
    });

    it('lets a non-Super Admin keep staff and role permissions a role already has', function () {
        $role = Role::create(['name' => 'Staff Manager', 'guard_name' => 'admin']);
        $role->syncPermissions(['staff.view', 'staff.edit']);

        update(staffWith(['roles.edit']), $role, ['name' => 'Staff Manager', 'description' => 'Renamed', 'permissions' => ['staff.view', 'staff.edit', 'clients.view']])
            ->assertOk()->assertJsonPath('data.permissions', ['clients.view', 'staff.view', 'staff.edit']);
    });

    it('clears the permission cache so the change applies at once', function () {
        $role = roleNamed('Sales');
        $salesperson = tap(Admin::factory()->create())->assignRole($role);
        expect($salesperson->can('invoices.view'))->toBeFalse()->and($salesperson->can('clients.view'))->toBeTrue();

        update(staffWith(['roles.edit']), $role, ['name' => 'Sales', 'description' => '', 'permissions' => ['invoices.view']])->assertOk();

        $salesperson = Admin::find($salesperson->id);
        expect($salesperson->can('invoices.view'))->toBeTrue()->and($salesperson->can('clients.view'))->toBeFalse();
    });

    it('returns 404 for an unknown role', function () {
        api(staffWith(['roles.edit']))->putJson('/api/admin/roles/99999', ['name' => 'X', 'description' => '', 'permissions' => []])->assertStatus(404);
    });
});

describe('DELETE /roles/{id}', function () {
    it('deletes a role without staff', function () {
        $role = Role::create(['name' => 'Temp', 'guard_name' => 'admin']);

        api(staffWith(['roles.delete']))->deleteJson('/api/admin/roles/'.$role->id)->assertNoContent();

        expect(Role::find($role->id))->toBeNull();
    });

    it('returns 403 without roles.delete', function () {
        $role = Role::create(['name' => 'Temp', 'guard_name' => 'admin']);

        api(staffWith(['roles.view', 'roles.edit']))->deleteJson('/api/admin/roles/'.$role->id)->assertStatus(403);
        expect(Role::find($role->id))->not->toBeNull();
    });

    it('refuses to delete a locked role', function () {
        api(superAdmin())->deleteJson('/api/admin/roles/'.roleNamed('Super Admin')->id)
            ->assertStatus(403)->assertJsonPath('code', 'role_locked');

        expect(Role::where('name', 'Super Admin')->exists())->toBeTrue();
    });

    it('refuses to delete a role that still has staff', function () {
        $role = roleNamed('Sales');
        Admin::factory()->create()->assignRole($role);

        api(staffWith(['roles.delete']))->deleteJson('/api/admin/roles/'.$role->id)
            ->assertStatus(422)->assertJsonPath('code', 'role_in_use');

        expect(Role::find($role->id))->not->toBeNull();
    });

    it('allows deleting a role whose only staff were deleted', function () {
        $role = Role::create(['name' => 'Temp', 'guard_name' => 'admin']);
        Admin::factory()->create()->assignRole($role)->delete();

        api(staffWith(['roles.delete']))->deleteJson('/api/admin/roles/'.$role->id)->assertNoContent();
    });

    it('returns 404 for an unknown role', function () {
        api(staffWith(['roles.delete']))->deleteJson('/api/admin/roles/99999')->assertStatus(404);
    });
});

describe('activity log', function () {
    it('records create, update and delete with before and after', function () {
        $actor = staffWith(['roles.create', 'roles.edit', 'roles.delete']);

        $id = api($actor)->postJson('/api/admin/roles', ['name' => 'Auditor', 'description' => 'Reads'])->assertCreated()->json('data.id');
        api($actor)->putJson("/api/admin/roles/{$id}", ['name' => 'Auditor', 'description' => 'Reads more', 'permissions' => ['reports.export']])->assertOk();
        api($actor)->deleteJson("/api/admin/roles/{$id}")->assertNoContent();

        $logs = ActivityLog::orderBy('id')->get();
        expect($logs->pluck('action')->all())->toBe(['role.created', 'role.updated', 'role.deleted']);

        [$created, $updated, $deleted] = $logs;
        expect($created->properties['after'])->toBe(['name' => 'Auditor', 'description' => 'Reads', 'permissions' => []])
            ->and($updated->properties['before']['permissions'])->toBe([])
            ->and($updated->properties['after'])->toBe(['name' => 'Auditor', 'description' => 'Reads more', 'permissions' => ['reports.view', 'reports.export']])
            ->and($deleted->properties['before']['permissions'])->toBe(['reports.view', 'reports.export']);

        foreach ($logs as $log) {
            expect($log->actor->is($actor))->toBeTrue()->and($log->subject_id)->toBe((int) $id)->and($log->ip_address)->not->toBeNull();
        }
    });

    it('logs nothing when the request is refused', function () {
        api(staffWith(['roles.view']))->putJson('/api/admin/roles/'.roleNamed('Sales')->id, ['name' => 'X', 'description' => '', 'permissions' => []])->assertStatus(403);
        api(superAdmin())->putJson('/api/admin/roles/'.roleNamed('Super Admin')->id, ['name' => 'X', 'description' => '', 'permissions' => []])->assertStatus(403);

        expect(ActivityLog::count())->toBe(0);
    });
});

it('accepts an empty permission list and revokes everything', function () {
    $role = roleNamed('Sales');

    api(staffWith(['roles.edit']))->putJson('/api/admin/roles/'.$role->id, ['name' => 'Sales', 'description' => '', 'permissions' => []])
        ->assertOk()->assertJsonPath('data.permissions', []);

    expect($role->fresh()->permissions)->toHaveCount(0);
});
