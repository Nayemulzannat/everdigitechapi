<?php

use App\Models\AccountMember;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\ClientAccount;
use App\Models\ClientLoginHistory;
use App\Models\ClientUser;
use App\Models\Domain;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use Database\Seeders\RoleSeeder;

beforeEach(fn () => $this->seed(RoleSeeder::class));

function detailStaff(array $extra = []): Admin
{
    return staffWith(['clients.view', 'clients.edit', ...$extra]);
}

function makeTicket(ClientAccount $account, ClientUser $user, array $over = []): Ticket
{
    $department = TicketDepartment::first() ?? TicketDepartment::create(['name' => 'Support', 'is_active' => true, 'sort_order' => 1]);

    return Ticket::create(['account_id' => $account->id, 'opened_by' => $user->id, 'department_id' => $department->id, 'subject' => 'Site is down', 'status' => 'open', 'priority' => 'high', 'last_reply_by' => 'client', 'last_reply_at' => now(), ...$over]);
}

describe('GET /api/admin/clients/{id}', function () {
    it('shows the client with its owner, the team and what it has', function () {
        $owner = clUser(['name' => 'Rahim Uddin', 'email' => 'rahim@uddintraders.com', 'phone' => '+8801711223344']);
        $account = clAccountOf($owner);
        $account->update(['company' => 'Uddin Traders', 'notes' => 'Pays late', 'tmp_services_count' => 3, 'tmp_balance_due' => 1450, 'tmp_has_overdue' => true]);
        $member = ClientUser::factory()->create(['name' => 'Karim']);
        AccountMember::create(['account_id' => $account->id, 'user_id' => $member->id, 'role' => 'member', 'permissions' => [], 'status' => 'active', 'joined_at' => now()]);
        makeTicket($account, $owner);
        makeTicket($account, $owner, ['status' => 'closed', 'subject' => 'Old']);
        Domain::create(['account_id' => $account->id, 'name' => 'soon.com', 'status' => 'active', 'expires_at' => now()->addDays(10)->toDateString()]);
        Domain::create(['account_id' => $account->id, 'name' => 'later.com', 'status' => 'active', 'expires_at' => now()->addDays(300)->toDateString()]);

        $data = api(detailStaff())->getJson("/api/admin/clients/{$account->id}")->assertOk()->json('data');

        expect($data)->toMatchArray(['id' => (string) $account->id, 'name' => $account->name, 'company' => 'Uddin Traders', 'group' => 'regular', 'status' => 'active', 'notes' => 'Pays late'])
            ->and($data['owner'])->toMatchArray(['name' => 'Rahim Uddin', 'email' => 'rahim@uddintraders.com', 'phone' => '+8801711223344', 'twoFactorOn' => false, 'suspended' => false])
            ->and(array_column($data['members'], 'role'))->toBe(['owner', 'member'])->and($data['members'][1]['name'])->toBe('Karim')
            ->and($data['stats'])->toEqual(['services' => 3, 'balanceDue' => 1450.0, 'overdue' => true, 'domains' => 2, 'domainsEndingSoon' => 1, 'tickets' => 2, 'ticketsOpen' => 1])
            ->and($data['recentTickets'])->toHaveCount(2)->and($data['recentTickets'][0]['number'])->toStartWith('TKT-')
            ->and(array_column($data['recentDomains'], 'name'))->toBe(['soon.com', 'later.com']);
    });

    it('lists the last sign-ins of everyone on the team, flagging the ones by staff', function () {
        $owner = clUser();
        $account = clAccountOf($owner);
        $admin = Admin::factory()->create();
        foreach (range(1, 12) as $i) {
            ClientLoginHistory::create(['client_user_id' => $owner->id, 'email_attempted' => $owner->email, 'ip_address' => "10.0.0.{$i}", 'successful' => true, 'impersonated_by_admin_id' => $i === 12 ? $admin->id : null]);
        }
        ClientLoginHistory::create(['client_user_id' => clUser()->id, 'email_attempted' => 'other@x.com', 'ip_address' => '9.9.9.9', 'successful' => true]);

        $logins = api(detailStaff())->getJson("/api/admin/clients/{$account->id}")->json('data.recentLogins');

        expect($logins)->toHaveCount(10)->and($logins[0]['ip'])->toBe('10.0.0.12')->and($logins[0]['impersonated'])->toBeTrue()->and($logins[1]['impersonated'])->toBeFalse()
            ->and(array_column($logins, 'ip'))->not->toContain('9.9.9.9');
    });

    it('does not show another client\'s tickets or domains', function () {
        $account = clAccountOf(clUser());
        $other = clAccountOf(clUser());
        makeTicket($other, $other->owner);
        Domain::create(['account_id' => $other->id, 'name' => 'other.com', 'status' => 'active']);

        $data = api(detailStaff())->getJson("/api/admin/clients/{$account->id}")->json('data');

        expect($data['stats']['tickets'])->toBe(0)->and($data['stats']['domains'])->toBe(0)->and($data['recentTickets'])->toBe([])->and($data['recentDomains'])->toBe([]);
    });

    it('answers 404 for a client that does not exist', function () {
        api(detailStaff())->getJson('/api/admin/clients/999999')->assertStatus(404)->assertJsonPath('code', 'not_found');
    });

    it('needs clients.view', function () {
        $account = clAccountOf(clUser());

        api(staffWith(['tickets.view']))->getJson("/api/admin/clients/{$account->id}")->assertStatus(403);
    });

    it('is closed to visitors', function () {
        test()->getJson('/api/admin/clients/1')->assertStatus(401);
    });
});

describe('PUT /api/admin/clients/{id}', function () {
    it('changes the name, company, group, status and notes, and writes the log', function () {
        $account = clAccountOf(clUser());

        api(detailStaff())->putJson("/api/admin/clients/{$account->id}", ['name' => ' New Name ', 'company' => '', 'group' => 'vip', 'status' => 'inactive', 'notes' => 'Call first'])->assertOk()
            ->assertJsonPath('data.name', 'New Name')->assertJsonPath('data.company', null)->assertJsonPath('data.group', 'vip')->assertJsonPath('data.status', 'inactive')->assertJsonPath('data.notes', 'Call first');

        $log = ActivityLog::where('action', 'client.updated')->sole();
        expect($log->properties['changes'])->toBe(['name', 'company', 'client_group', 'status', 'notes']);
    });

    it('changes only what is sent', function () {
        $account = clAccountOf(clUser());
        $account->update(['company' => 'Keep Ltd']);

        api(detailStaff())->putJson("/api/admin/clients/{$account->id}", ['notes' => 'x'])->assertOk()->assertJsonPath('data.company', 'Keep Ltd')->assertJsonPath('data.status', 'active');
    });

    it('refuses bad values', function (array $body, string $field) {
        $account = clAccountOf(clUser());

        api(detailStaff())->putJson("/api/admin/clients/{$account->id}", $body)->assertStatus(422)->assertJsonValidationErrors($field);
    })->with([
        [['name' => ''], 'name'],
        [['group' => 'gold'], 'group'],
        [['status' => 'gone'], 'status'],
        [['notes' => str_repeat('x', 5001)], 'notes'],
    ]);

    it('a closed account can no longer be signed in as', function () {
        $account = clAccountOf(clUser());

        api(detailStaff(['clients.login_as']))->putJson("/api/admin/clients/{$account->id}", ['status' => 'closed'])->assertOk();
        api(detailStaff(['clients.login_as']))->postJson("/api/admin/clients/{$account->id}/impersonate")->assertStatus(422)->assertJsonPath('code', 'account_closed');
    });

    it('needs clients.edit', function () {
        $account = clAccountOf(clUser());

        api(staffWith(['clients.view']))->putJson("/api/admin/clients/{$account->id}", ['notes' => 'x'])->assertStatus(403);
        api(detailStaff())->putJson('/api/admin/clients/999999', ['notes' => 'x'])->assertStatus(404);
    });
});
