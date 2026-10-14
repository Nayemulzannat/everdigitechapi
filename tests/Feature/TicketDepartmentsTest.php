<?php

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\ClientAccount;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TicketDepartmentSeeder;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(TicketDepartmentSeeder::class);
});

function deptManager(): Admin
{
    return staffWith(['tickets.view', 'tickets.edit', 'tickets.delete']);
}

function deptNames(): array
{
    return TicketDepartment::orderBy('sort_order')->pluck('name')->all();
}

/** A ticket in the department, so the department is "in use". */
function deptTicket(TicketDepartment $department): Ticket
{
    $account = ClientAccount::factory()->create();

    return Ticket::create(['account_id' => $account->id, 'opened_by' => $account->owner_user_id, 'department_id' => $department->id, 'subject' => 'Help', 'last_reply_at' => now()]);
}

describe('the seeder', function () {
    it('creates the four departments, in order, and can run again', function () {
        expect(deptNames())->toBe(['Technical Support', 'Billing', 'Sales', 'Domains']);

        $this->seed(TicketDepartmentSeeder::class);

        expect(TicketDepartment::count())->toBe(4);
    });
});

describe('GET /api/admin/ticket-departments', function () {
    it('lists every department, switched off ones too, with the number of tickets', function () {
        $billing = TicketDepartment::where('name', 'Billing')->sole();
        $billing->update(['is_active' => false]);
        deptTicket($billing);
        deptTicket($billing);

        $data = api(deptManager())->getJson('/api/admin/ticket-departments')->assertOk()->json('data');

        expect(collect($data)->pluck('name')->all())->toBe(['Technical Support', 'Billing', 'Sales', 'Domains'])
            ->and(array_keys($data[0]))->toBe(['id', 'name', 'description', 'isActive', 'sortOrder', 'ticketsCount'])
            ->and($data[1])->toMatchArray(['name' => 'Billing', 'isActive' => false, 'ticketsCount' => 2])
            ->and($data[0])->toMatchArray(['isActive' => true, 'ticketsCount' => 0, 'sortOrder' => 1]);
    });

    it('needs tickets.view', function () {
        api(staffWith(['clients.view']))->getJson('/api/admin/ticket-departments')->assertStatus(403);
        api(staffWith(['tickets.view']))->getJson('/api/admin/ticket-departments')->assertOk();
    });
});

describe('POST /api/admin/ticket-departments', function () {
    it('adds a department at the end of the list', function () {
        api(deptManager())->postJson('/api/admin/ticket-departments', ['name' => '  Abuse reports ', 'description' => 'Spam and abuse.'])
            ->assertCreated()->assertJsonPath('data.name', 'Abuse reports')->assertJsonPath('data.isActive', true)->assertJsonPath('data.sortOrder', 5)->assertJsonPath('data.ticketsCount', 0);

        expect(deptNames())->toBe(['Technical Support', 'Billing', 'Sales', 'Domains', 'Abuse reports']);
        expect(ActivityLog::where('action', 'ticket_department.created')->count())->toBe(1);
    });

    it('can be added switched off, and the description is optional', function () {
        api(deptManager())->postJson('/api/admin/ticket-departments', ['name' => 'Later', 'isActive' => false, 'description' => '   '])
            ->assertCreated()->assertJsonPath('data.isActive', false)->assertJsonPath('data.description', null);
    });

    it('refuses bad input', function (array $body, string $field) {
        api(deptManager())->postJson('/api/admin/ticket-departments', $body)->assertStatus(422)->assertJsonValidationErrors($field);

        expect(TicketDepartment::count())->toBe(4);
    })->with([
        'no name' => [[], 'name'],
        'empty name' => [['name' => '   '], 'name'],
        'name too long' => [['name' => str_repeat('a', 101)], 'name'],
        'a name that exists' => [['name' => 'Billing'], 'name'],
        'the same name in other letters' => [['name' => 'BILLING'], 'name'],
        'description too long' => [['name' => 'X', 'description' => str_repeat('a', 256)], 'description'],
        'isActive not a boolean' => [['name' => 'X', 'isActive' => 'maybe'], 'isActive'],
    ]);

    it('needs tickets.edit', function () {
        api(staffWith(['tickets.view']))->postJson('/api/admin/ticket-departments', ['name' => 'X'])->assertStatus(403);
    });
});

describe('PUT /api/admin/ticket-departments/{id}', function () {
    it('renames and describes a department', function () {
        $sales = TicketDepartment::where('name', 'Sales')->sole();

        api(deptManager())->putJson("/api/admin/ticket-departments/{$sales->id}", ['name' => 'Sales and plans', 'description' => 'New: plans.'])
            ->assertOk()->assertJsonPath('data.name', 'Sales and plans')->assertJsonPath('data.description', 'New: plans.');

        expect($sales->fresh()->name)->toBe('Sales and plans');
        expect(ActivityLog::where('action', 'ticket_department.updated')->count())->toBe(1);
    });

    it('can keep its own name, but not take the name of another department', function () {
        $sales = TicketDepartment::where('name', 'Sales')->sole();

        api(deptManager())->putJson("/api/admin/ticket-departments/{$sales->id}", ['name' => 'Sales', 'description' => 'Same name.'])->assertOk();
        api(deptManager())->putJson("/api/admin/ticket-departments/{$sales->id}", ['name' => 'billing'])->assertStatus(422)->assertJsonValidationErrors('name');
    });

    it('switches a department off and on, and keeps its tickets', function () {
        $domains = TicketDepartment::where('name', 'Domains')->sole();
        $ticket = deptTicket($domains);

        api(deptManager())->putJson("/api/admin/ticket-departments/{$domains->id}", ['isActive' => false])->assertOk()->assertJsonPath('data.isActive', false)->assertJsonPath('data.ticketsCount', 1);
        expect(TicketDepartment::active()->pluck('name')->all())->not->toContain('Domains')->and($ticket->fresh()->department_id)->toBe($domains->id);

        api(deptManager())->putJson("/api/admin/ticket-departments/{$domains->id}", ['isActive' => true])->assertOk()->assertJsonPath('data.isActive', true);
    });

    it('never switches off the last department that is on', function () {
        TicketDepartment::where('name', '!=', 'Billing')->update(['is_active' => false]);
        $billing = TicketDepartment::where('name', 'Billing')->sole();

        api(deptManager())->putJson("/api/admin/ticket-departments/{$billing->id}", ['isActive' => false])->assertStatus(422)->assertJsonPath('code', 'last_active_department');

        expect($billing->fresh()->is_active)->toBeTrue();
        api(deptManager())->putJson("/api/admin/ticket-departments/{$billing->id}", ['name' => 'Billing team'])->assertOk(); // other changes are fine
    });

    it('answers 404 for a department that does not exist, and needs tickets.edit', function () {
        api(deptManager())->putJson('/api/admin/ticket-departments/99999', ['name' => 'X'])->assertStatus(404);
        api(staffWith(['tickets.view']))->putJson('/api/admin/ticket-departments/'.TicketDepartment::first()->id, ['name' => 'X'])->assertStatus(403);
    });

    it('a department that is switched off cannot be chosen when opening a ticket', function () {
        $client = clUser();
        $sales = TicketDepartment::where('name', 'Sales')->sole();
        api(deptManager())->putJson("/api/admin/ticket-departments/{$sales->id}", ['isActive' => false])->assertOk();

        clSpa()->actingAs($client, 'client')->postJson('/api/client/tickets', ['subject' => 'x', 'department_id' => $sales->id, 'message' => 'y'])->assertStatus(422)->assertJsonValidationErrors('department_id');
        $names = collect(clSpa()->actingAs($client, 'client')->getJson('/api/client/ticket-departments')->json('data'))->pluck('name')->all();
        expect($names)->toBe(['Technical Support', 'Billing', 'Domains']);
    });
});

describe('DELETE /api/admin/ticket-departments/{id}', function () {
    it('deletes a department nobody has used', function () {
        $sales = TicketDepartment::where('name', 'Sales')->sole();

        api(deptManager())->deleteJson("/api/admin/ticket-departments/{$sales->id}")->assertOk();

        expect(deptNames())->toBe(['Technical Support', 'Billing', 'Domains']);
        expect(ActivityLog::where('action', 'ticket_department.deleted')->count())->toBe(1);
    });

    it('refuses a department that has tickets, and says to switch it off', function () {
        $billing = TicketDepartment::where('name', 'Billing')->sole();
        deptTicket($billing);

        api(deptManager())->deleteJson("/api/admin/ticket-departments/{$billing->id}")->assertStatus(422)->assertJsonPath('code', 'department_in_use')
            ->assertJsonPath('message', fn ($m) => str_contains($m, '1 ticket uses') && str_contains($m, 'Switch it off'));

        expect(TicketDepartment::count())->toBe(4);
    });

    it('never deletes the last department that is on', function () {
        TicketDepartment::where('name', '!=', 'Sales')->update(['is_active' => false]);
        $sales = TicketDepartment::where('name', 'Sales')->sole();

        api(deptManager())->deleteJson("/api/admin/ticket-departments/{$sales->id}")->assertStatus(422)->assertJsonPath('code', 'last_active_department');
    });

    it('needs tickets.delete, not just tickets.edit', function () {
        $sales = TicketDepartment::where('name', 'Sales')->sole();

        api(staffWith(['tickets.view', 'tickets.edit']))->deleteJson("/api/admin/ticket-departments/{$sales->id}")->assertStatus(403);
        api(deptManager())->deleteJson('/api/admin/ticket-departments/99999')->assertStatus(404);
        expect(TicketDepartment::count())->toBe(4);
    });
});

describe('POST /api/admin/ticket-departments/reorder', function () {
    it('puts the departments in the order that was sent, and clients see that order', function () {
        $ids = TicketDepartment::orderBy('id')->pluck('id', 'name');

        $data = api(deptManager())->postJson('/api/admin/ticket-departments/reorder', ['ids' => [$ids['Domains'], $ids['Sales'], $ids['Technical Support'], $ids['Billing']]])->assertOk()->json('data');

        expect(collect($data)->pluck('name')->all())->toBe(['Domains', 'Sales', 'Technical Support', 'Billing'])->and(collect($data)->pluck('sortOrder')->all())->toBe([1, 2, 3, 4]);
        $clientSees = collect(clSpa()->actingAs(clUser(), 'client')->getJson('/api/client/ticket-departments')->json('data'))->pluck('name')->all();
        expect($clientSees)->toBe(['Domains', 'Sales', 'Technical Support', 'Billing']);
    });

    it('refuses a list that leaves one out, repeats one, or has one that does not exist', function (Closure $ids) {
        $all = TicketDepartment::orderBy('id')->pluck('id')->all();

        api(deptManager())->postJson('/api/admin/ticket-departments/reorder', ['ids' => $ids($all)])->assertStatus(422);

        expect(deptNames())->toBe(['Technical Support', 'Billing', 'Sales', 'Domains']);
    })->with([
        'one left out' => fn () => fn (array $all) => array_slice($all, 1),
        'one twice' => fn () => fn (array $all) => [$all[0], $all[0], $all[1], $all[2]],
        'an unknown id' => fn () => fn (array $all) => [...array_slice($all, 1), 99999],
        'empty' => fn () => fn (array $all) => [],
    ]);

    it('needs tickets.edit', function () {
        api(staffWith(['tickets.view']))->postJson('/api/admin/ticket-departments/reorder', ['ids' => TicketDepartment::pluck('id')->all()])->assertStatus(403);
    });
});

describe('visitors who are not signed in', function () {
    it('cannot reach any department route', function (string $method, string $path) {
        test()->json($method, $path)->assertStatus(401);
    })->with([
        ['GET', '/api/admin/ticket-departments'], ['POST', '/api/admin/ticket-departments'], ['POST', '/api/admin/ticket-departments/reorder'],
        ['PUT', '/api/admin/ticket-departments/1'], ['DELETE', '/api/admin/ticket-departments/1'],
    ]);
});

it('is allowed for Super Admin and Admin, not for Sales or Billing: they cannot change departments', function () {
    $as = fn (string $role) => tap(Admin::factory()->create())->assignRole($role);

    foreach (['Super Admin', 'Admin'] as $role) {
        api($as($role))->postJson('/api/admin/ticket-departments', ['name' => "From {$role}"])->assertCreated();
    }
    foreach (['Billing Manager', 'Sales'] as $role) {
        api($as($role))->postJson('/api/admin/ticket-departments', ['name' => "From {$role}"])->assertStatus(403);
    }
});
