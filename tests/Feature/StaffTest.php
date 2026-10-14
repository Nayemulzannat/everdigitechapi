<?php

use App\Enums\AdminStatus;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Role;
use App\Notifications\AdminInviteNotification;
use App\Services\StaffService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function manager(array $permissions = ['staff.view', 'staff.create', 'staff.edit', 'staff.delete']): Admin
{
    return staffWith($permissions, 'Staff Manager');
}

function member(string $role = 'Sales', array $attributes = []): Admin
{
    return tap(Admin::factory()->create($attributes))->assignRole($role);
}

/** Puts a fake session row in the table, the way Laravel's database session driver stores it. */
function sessionFor(?Admin $admin): string
{
    $id = str()->random(40);
    DB::table('sessions')->insert(['id' => $id, 'user_id' => $admin?->id, 'payload' => '', 'last_activity' => time()]);

    return $id;
}

function sessionExists(string $id): bool
{
    return DB::table('sessions')->where('id', $id)->exists();
}

function updateBody(Admin $admin, array $over = []): array
{
    $role = $admin->roles->first();

    return ['name' => $admin->name, 'phone' => $admin->phone, 'role' => $role->id, 'status' => $admin->status->value, ...$over];
}

function putStaff(Admin $actor, Admin $target, array $over = [])
{
    return api($actor)->putJson('/api/admin/staff/'.$target->id, updateBody($target->load('roles'), $over));
}

function newStaff(array $over = []): array
{
    return ['name' => 'New Person', 'email' => 'New.Person@Example.com', 'phone' => '+8801711223344', 'role' => roleNamed('Support Staff')->id, ...$over];
}

function spaCall()
{
    return test()->withHeaders(['Origin' => 'http://localhost:3003']);
}

describe('GET /staff', function () {
    it('lists staff in the frontend shape', function () {
        $sales = roleNamed('Sales');
        $person = member('Sales', [
            'name' => 'Farhana Akter', 'phone' => '+8801711000000', 'last_login_at' => '2026-10-01 08:30:00',
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now(),
        ]);

        $row = collect(api(manager())->getJson('/api/admin/staff')->assertOk()->json('data'))->firstWhere('email', $person->email);

        expect($row)->toBe([
            'id' => (string) $person->id, 'name' => 'Farhana Akter', 'email' => $person->email, 'phone' => '+8801711000000',
            'avatarUrl' => null, 'status' => 'active',
            'role' => ['id' => (string) $sales->id, 'name' => 'Sales', 'isSuperAdmin' => false],
            'invitedAt' => null, 'lastLoginAt' => '2026-10-01T08:30:00Z', 'lastLoginIp' => null, 'lastLoginDevice' => null,
            'suspendedBy' => null, 'twoFactorEnabled' => true,
            'createdAt' => $person->created_at->utc()->toIso8601ZuluString(),
        ]);
    });

    it('is paginated, 15 per page', function () {
        Admin::factory()->count(20)->create()->each->assignRole('Sales');

        api(manager())->getJson('/api/admin/staff')->assertOk()
            ->assertJsonCount(15, 'data')->assertJsonPath('meta.total', 21)->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.last_page', 2)->assertJsonPath('meta.current_page', 1);

        api(manager())->getJson('/api/admin/staff?page=2')->assertJsonPath('meta.from', 16);
    });

    it('filters by role and status and searches name and email', function () {
        $a = member('Sales', ['name' => 'Alice Rahman', 'email' => 'alice@example.com']);
        $b = member('Sales', ['name' => 'Bob Karim', 'email' => 'bob@corp.test', 'status' => 'suspended']);
        $c = member('Support Staff', ['name' => 'Carol Das', 'email' => 'carol@example.com']);
        $viewer = manager(['staff.view']);
        $ids = fn ($q) => collect(api($viewer)->getJson('/api/admin/staff?'.$q)->assertOk()->json('data'))->pluck('id')->all();
        $id = fn (Admin ...$admins) => array_map(fn ($x) => (string) $x->id, $admins);

        expect($ids('role='.roleNamed('Sales')->id))->toEqualCanonicalizing($id($a, $b))
            ->and($ids('status=suspended'))->toBe($id($b))
            ->and($ids('role='.roleNamed('Sales')->id.'&status=active'))->toBe($id($a))
            ->and($ids('search=carol'))->toBe($id($c))
            ->and($ids('search=@corp.test'))->toBe($id($b))
            ->and($ids('search=Rahman'))->toBe($id($a))
            ->and($ids('search=nobody-like-this'))->toBe([]);
    });

    it('searches wildcard characters literally', function () {
        member('Sales', ['name' => '100% Sales']);
        member('Sales', ['name' => 'Other Person']);

        $names = collect(api(manager())->getJson('/api/admin/staff?search='.urlencode('%'))->json('data'))->pluck('name')->all();

        expect($names)->toBe(['100% Sales']);
    });

    it('sorts by name and leaves out deleted staff', function () {
        $viewer = manager(['staff.view']);
        member('Sales', ['name' => 'Zed']);
        member('Sales', ['name' => 'Aaron']);
        tap(member('Sales', ['name' => 'Gone']))->delete();

        $names = collect(api($viewer)->getJson('/api/admin/staff')->json('data'))->pluck('name')->all();

        expect($names)->not->toContain('Gone')->and(array_search('Aaron', $names))->toBeLessThan(array_search('Zed', $names));
    });

    it('validates the filters', function () {
        $viewer = manager(['staff.view']);

        api($viewer)->getJson('/api/admin/staff?status=banned')->assertStatus(422)->assertJsonValidationErrors('status');
        api($viewer)->getJson('/api/admin/staff?role=99999')->assertStatus(422)->assertJsonValidationErrors('role');
    });

    it('needs staff.view', function () {
        api(manager(['staff.create', 'staff.edit']))->getJson('/api/admin/staff')->assertStatus(403)->assertJsonPath('code', 'forbidden');
    });

    it('needs a login', function () {
        spaCall()->getJson('/api/admin/staff')->assertStatus(401);
    });
});

describe('GET /staff/{id}', function () {
    it('shows one staff member', function () {
        $person = member('Support Staff');

        api(manager(['staff.view']))->getJson('/api/admin/staff/'.$person->id)
            ->assertOk()->assertJsonPath('data.email', $person->email)->assertJsonPath('data.role.name', 'Support Staff');
    });

    it('returns 404 for unknown and deleted staff', function () {
        $gone = tap(member())->delete();
        $viewer = manager(['staff.view']);

        api($viewer)->getJson('/api/admin/staff/99999')->assertStatus(404)->assertJsonPath('code', 'not_found');
        api($viewer)->getJson('/api/admin/staff/'.$gone->id)->assertStatus(404);
    });

    it('needs staff.view', function () {
        api(manager(['staff.edit']))->getJson('/api/admin/staff/'.member()->id)->assertStatus(403);
    });
});

describe('POST /staff', function () {
    it('creates an active account with the role, without any password from the admin', function () {
        Notification::fake();

        $response = api(manager())->postJson('/api/admin/staff', newStaff())->assertCreated();

        $response->assertJsonPath('data.name', 'New Person')
            ->assertJsonPath('data.email', 'new.person@example.com')
            ->assertJsonPath('data.status', 'invited') // active account, password not set yet
            ->assertJsonPath('data.role.name', 'Support Staff')
            ->assertJsonPath('data.lastLoginAt', null)
            ->assertJsonPath('data.twoFactorEnabled', false);

        $admin = Admin::where('email', 'new.person@example.com')->sole();
        expect($admin->hasRole('Support Staff'))->toBeTrue()->and($admin->phone)->toBe('+8801711223344');

        // The password is random: a guess can't sign in.
        spaCall()->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'password'])->assertStatus(422);
    });

    it('emails a set-your-password link to the new person', function () {
        Notification::fake();

        api(manager())->postJson('/api/admin/staff', newStaff())->assertCreated();

        $admin = Admin::where('email', 'new.person@example.com')->sole();
        Notification::assertSentTo($admin, AdminInviteNotification::class);
        $mail = Notification::sent($admin, AdminInviteNotification::class)->first()->toMail($admin);
        expect($mail->actionUrl)->toStartWith('http://localhost:3003/reset-password?token=')->toContain('invite=1')
            ->and($mail->outroLines[0])->toContain('48 hours');
    });

    it('lets the invited person set a password within 48 hours, and not after', function (int $hours, bool $works) {
        Notification::fake();
        api(manager())->postJson('/api/admin/staff', newStaff())->assertCreated();
        $admin = Admin::where('email', 'new.person@example.com')->sole();
        $token = Notification::sent($admin, AdminInviteNotification::class)->first()->token;

        $this->travel($hours)->hours();
        $response = spaCall()->postJson('/api/admin/reset-password', [
            'token' => $token, 'email' => $admin->email, 'password' => 'Brand-New-Pass-77', 'password_confirmation' => 'Brand-New-Pass-77',
        ]);

        if ($works) {
            $response->assertOk();
            spaCall()->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'Brand-New-Pass-77'])
                ->assertOk()->assertJsonPath('data.role.name', 'Support Staff');
        } else {
            $response->assertStatus(422)->assertJsonPath('code', 'invalid_reset_token');
        }
    })->with([[47, true], [49, false]]);

    it('rejects a duplicate email, including a deleted account', function () {
        $existing = member('Sales', ['email' => 'taken@example.com']);
        $admin = manager();

        api($admin)->postJson('/api/admin/staff', newStaff(['email' => 'TAKEN@example.com']))->assertStatus(422)->assertJsonValidationErrors('email');

        $existing->delete();
        api($admin)->postJson('/api/admin/staff', newStaff(['email' => 'taken@example.com']))->assertStatus(422)->assertJsonValidationErrors('email');
    });

    it('validates the body', function () {
        Notification::fake();
        $admin = manager();

        api($admin)->postJson('/api/admin/staff', [])->assertStatus(422)->assertJsonValidationErrors(['name', 'email', 'role']);
        api($admin)->postJson('/api/admin/staff', newStaff(['role' => 99999]))->assertStatus(422)->assertJsonValidationErrors('role');
        api($admin)->postJson('/api/admin/staff', newStaff(['phone' => 'call me']))->assertStatus(422)->assertJsonValidationErrors('phone');
        api($admin)->postJson('/api/admin/staff', newStaff(['phone' => null]))->assertCreated()->assertJsonPath('data.phone', null);
    });

    it('does not take a role of another guard', function () {
        $other = Role::create(['name' => 'Reseller', 'guard_name' => 'client']);

        api(manager())->postJson('/api/admin/staff', newStaff(['role' => $other->id]))->assertStatus(422)->assertJsonValidationErrors('role');
    });

    it('needs staff.create', function () {
        Notification::fake();

        api(manager(['staff.view', 'staff.edit', 'staff.delete']))->postJson('/api/admin/staff', newStaff())->assertStatus(403);

        expect(Admin::where('email', 'new.person@example.com')->exists())->toBeFalse();
        Notification::assertNothingSent();
    });

    it('lets only a Super Admin assign the Super Admin role', function () {
        Notification::fake();
        $body = newStaff(['role' => roleNamed('Super Admin')->id]);

        api(manager())->postJson('/api/admin/staff', $body)->assertStatus(403)->assertJsonPath('code', 'super_admin_only');
        expect(Admin::where('email', 'new.person@example.com')->exists())->toBeFalse();

        api(superAdmin())->postJson('/api/admin/staff', $body)->assertCreated()->assertJsonPath('data.role.isSuperAdmin', true);
    });

    it('writes to the activity log', function () {
        Notification::fake();
        $actor = manager();

        $id = api($actor)->postJson('/api/admin/staff', newStaff())->json('data.id');

        $log = ActivityLog::where('action', 'staff.created')->sole();
        expect($log->actor->is($actor))->toBeTrue()->and($log->subject_id)->toBe((int) $id)
            ->and($log->properties['after'])->toEqual(['name' => 'New Person', 'phone' => '+8801711223344', 'role' => 'Support Staff', 'status' => 'active']);
    });
});

describe('PUT /staff/{id}', function () {
    it('updates name, phone, role and status, but never the email', function () {
        $person = member('Sales', ['name' => 'Old Name', 'phone' => null]);

        putStaff(manager(), $person, [
            'name' => 'New Name', 'phone' => '+8801999888777', 'role' => roleNamed('Support Staff')->id, 'status' => 'suspended', 'email' => 'hacker@example.com',
        ])->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.phone', '+8801999888777')
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.role.name', 'Support Staff')
            ->assertJsonPath('data.email', $person->email);

        expect($person->fresh()->email)->toBe($person->email)->and($person->fresh()->hasRole('Support Staff'))->toBeTrue()
            ->and($person->fresh()->hasRole('Sales'))->toBeFalse();
    });

    it('validates the body', function () {
        $person = member();

        api(manager())->putJson('/api/admin/staff/'.$person->id, [])->assertStatus(422)->assertJsonValidationErrors(['name', 'role', 'status']);
        putStaff(manager(), $person, ['status' => 'banned'])->assertStatus(422)->assertJsonValidationErrors('status');
        putStaff(manager(), $person, ['role' => 99999])->assertStatus(422)->assertJsonValidationErrors('role');
    });

    it('returns 403 without staff.edit and 404 for unknown staff', function () {
        $person = member();

        putStaff(manager(['staff.view', 'staff.create', 'staff.delete']), $person, ['name' => 'Hacked'])->assertStatus(403);
        expect($person->fresh()->name)->not->toBe('Hacked');

        api(manager())->putJson('/api/admin/staff/99999', updateBody($person->load('roles')))->assertStatus(404);
    });

    describe('self protection', function () {
        it('does not let anyone suspend themselves', function () {
            $me = manager();
            $meSA = superAdmin();
            superAdmin(); // another Super Admin exists, so this is not the last-Super-Admin rule

            putStaff($me, $me, ['status' => 'suspended'])->assertStatus(422)->assertJsonPath('code', 'cannot_suspend_self');
            putStaff($meSA, $meSA, ['status' => 'suspended'])->assertStatus(422)->assertJsonPath('code', 'cannot_suspend_self');

            expect($me->fresh()->status)->toBe(AdminStatus::Active)->and($meSA->fresh()->status)->toBe(AdminStatus::Active);
        });

        it('still lets someone edit their own name and phone', function () {
            $me = manager();

            putStaff($me, $me, ['name' => 'Renamed Me', 'phone' => '+8801000000000'])->assertOk()->assertJsonPath('data.name', 'Renamed Me');
        });
    });

    describe('last active Super Admin', function () {
        it('can not be moved to another role', function () {
            $only = superAdmin();

            putStaff($only, $only, ['role' => roleNamed('Admin')->id])->assertStatus(422)->assertJsonPath('code', 'last_super_admin');

            expect($only->fresh()->isSuperAdmin())->toBeTrue();
        });

        it('can not be suspended', function () {
            $only = superAdmin();

            putStaff($only, $only, ['status' => 'suspended'])->assertStatus(422)->assertJsonPath('code', 'last_super_admin');

            expect($only->fresh()->status)->toBe(AdminStatus::Active);
        });

        it('may still change name and phone', function () {
            $only = superAdmin();

            putStaff($only, $only, ['name' => 'Boss'])->assertOk();
        });

        it('can be moved once another active Super Admin exists', function () {
            $first = superAdmin();
            $second = superAdmin();

            putStaff($second, $first, ['role' => roleNamed('Admin')->id])->assertOk()->assertJsonPath('data.role.name', 'Admin');

            // Now $second is the last one.
            putStaff($second, $second, ['role' => roleNamed('Admin')->id])->assertStatus(422)->assertJsonPath('code', 'last_super_admin');
        });

        it('does not count suspended or deleted Super Admins', function () {
            $service = app(StaffService::class);
            $only = superAdmin();
            $suspended = tap(superAdmin())->update(['status' => 'suspended']);
            tap(superAdmin())->delete();

            expect($service->isLastActiveSuperAdmin($only))->toBeTrue()
                ->and($service->isLastActiveSuperAdmin($suspended))->toBeFalse()
                ->and($service->isLastActiveSuperAdmin(member()))->toBeFalse();

            superAdmin();
            expect($service->isLastActiveSuperAdmin($only))->toBeFalse();
        });
    });

    describe('Super Admin role', function () {
        it('can only be assigned by a Super Admin', function () {
            $person = member('Sales');
            $superRole = roleNamed('Super Admin')->id;

            putStaff(manager(), $person, ['role' => $superRole])->assertStatus(403)->assertJsonPath('code', 'super_admin_only');
            expect($person->fresh()->isSuperAdmin())->toBeFalse();

            putStaff(superAdmin(), $person, ['role' => $superRole])->assertOk()->assertJsonPath('data.role.isSuperAdmin', true);
        });

        it('is protected from edits by non-Super Admins', function () {
            $boss = superAdmin();
            superAdmin();

            putStaff(manager(), $boss, ['name' => 'Renamed', 'status' => 'suspended'])->assertStatus(403)->assertJsonPath('code', 'super_admin_only');
            putStaff(manager(), $boss, ['role' => roleNamed('Sales')->id])->assertStatus(403);

            expect($boss->fresh()->name)->not->toBe('Renamed')->and($boss->fresh()->isSuperAdmin())->toBeTrue();
        });
    });

    describe('ending sessions', function () {
        it('deletes every session of an admin who is suspended', function () {
            $person = member();
            $other = member();
            [$a, $b, $theirs, $anon] = [sessionFor($person), sessionFor($person), sessionFor($other), sessionFor(null)];
            $oldRemember = $person->remember_token;

            putStaff(manager(), $person, ['status' => 'suspended'])->assertOk();

            expect(sessionExists($a))->toBeFalse()->and(sessionExists($b))->toBeFalse()
                ->and(sessionExists($theirs))->toBeTrue()->and(sessionExists($anon))->toBeTrue()
                ->and($person->fresh()->remember_token)->not->toBe($oldRemember);
        });

        it('deletes every session of an admin whose role changed', function () {
            $person = member('Sales');
            $session = sessionFor($person);

            putStaff(manager(), $person, ['role' => roleNamed('Support Staff')->id])->assertOk();

            expect(sessionExists($session))->toBeFalse();
        });

        it('keeps sessions when only the name or phone changes', function () {
            $person = member('Sales');
            $session = sessionFor($person);

            putStaff(manager(), $person, ['name' => 'Different Name'])->assertOk();

            expect(sessionExists($session))->toBeTrue();
        });

        it('keeps sessions when an admin is reactivated', function () {
            $person = member('Sales', ['status' => 'suspended']);
            $session = sessionFor($person);

            putStaff(manager(), $person, ['status' => 'active'])->assertOk();

            expect(sessionExists($session))->toBeTrue();
        });

        it('makes a role change apply on the very next request', function () {
            $person = member('Billing Manager');
            expect($person->can('invoices.refund'))->toBeTrue();

            putStaff(manager(), $person, ['role' => roleNamed('Sales')->id])->assertOk();

            expect(Admin::find($person->id)->can('invoices.refund'))->toBeFalse();
        });
    });

    describe('activity log', function () {
        it('records an update with before and after', function () {
            $actor = manager();
            $person = member('Sales', ['name' => 'Before Name', 'phone' => null]);

            putStaff($actor, $person, ['name' => 'After Name', 'role' => roleNamed('Support Staff')->id])->assertOk();

            $log = ActivityLog::where('action', 'staff.updated')->sole();
            expect($log->actor->is($actor))->toBeTrue()->and($log->subject_id)->toBe($person->id)
                ->and($log->properties['before'])->toEqual(['name' => 'Before Name', 'phone' => null, 'role' => 'Sales'])
                ->and($log->properties['after'])->toEqual(['name' => 'After Name', 'phone' => null, 'role' => 'Support Staff'])
                ->and(ActivityLog::where('action', 'staff.suspended')->exists())->toBeFalse();
        });

        it('records a suspension and a reactivation', function () {
            $person = member();

            putStaff(manager(), $person, ['status' => 'suspended'])->assertOk();
            putStaff(manager(), $person->fresh(), ['status' => 'active'])->assertOk();

            expect(ActivityLog::orderBy('id')->pluck('action')->all())->toBe(['staff.suspended', 'staff.activated'])
                ->and(ActivityLog::where('action', 'staff.suspended')->sole()->properties)
                ->toEqual(['before' => ['status' => 'active'], 'after' => ['status' => 'suspended']]);
        });

        it('logs nothing when nothing changed, or when the request is refused', function () {
            $person = member();

            putStaff(manager(), $person)->assertOk();
            putStaff(manager(['staff.view']), $person, ['name' => 'X'])->assertStatus(403);

            expect(ActivityLog::count())->toBe(0);
        });
    });
});

describe('DELETE /staff/{id}', function () {
    it('soft deletes the account', function () {
        $person = member();

        api(manager())->deleteJson('/api/admin/staff/'.$person->id)->assertNoContent();

        expect(Admin::find($person->id))->toBeNull()->and(Admin::withTrashed()->find($person->id))->not->toBeNull();
        api(manager())->getJson('/api/admin/staff/'.$person->id)->assertStatus(404);
    });

    it('cannot sign in after being deleted', function () {
        $person = member('Sales', ['password' => 'Correct-Horse-9-Battery']);

        api(manager())->deleteJson('/api/admin/staff/'.$person->id)->assertNoContent();

        spaCall()->postJson('/api/admin/login', ['email' => $person->email, 'password' => 'Correct-Horse-9-Battery'])->assertStatus(422);
    });

    it('ends the sessions of the deleted admin only', function () {
        $person = member();
        $other = member();
        [$mine, $theirs] = [sessionFor($person), sessionFor($other)];

        api(manager())->deleteJson('/api/admin/staff/'.$person->id)->assertNoContent();

        expect(sessionExists($mine))->toBeFalse()->and(sessionExists($theirs))->toBeTrue();
    });

    it('does not let anyone delete themselves', function () {
        $me = manager();
        superAdmin(); // keep this about the self rule, not the last-Super-Admin rule

        api($me)->deleteJson('/api/admin/staff/'.$me->id)->assertStatus(422)->assertJsonPath('code', 'cannot_delete_self');

        expect(Admin::find($me->id))->not->toBeNull();
    });

    it('never deletes the last active Super Admin', function () {
        $only = superAdmin();

        api($only)->deleteJson('/api/admin/staff/'.$only->id)->assertStatus(422)->assertJsonPath('code', 'last_super_admin');

        expect(Admin::find($only->id))->not->toBeNull();
    });

    it('lets a Super Admin delete another Super Admin', function () {
        $first = superAdmin();
        $second = superAdmin();

        api($first)->deleteJson('/api/admin/staff/'.$second->id)->assertNoContent();

        // And now $first is the last one.
        api($first)->deleteJson('/api/admin/staff/'.$first->id)->assertStatus(422)->assertJsonPath('code', 'last_super_admin');
    });

    it('is protected from non-Super Admins when the target is a Super Admin', function () {
        $boss = superAdmin();
        superAdmin();

        api(manager())->deleteJson('/api/admin/staff/'.$boss->id)->assertStatus(403)->assertJsonPath('code', 'super_admin_only');

        expect(Admin::find($boss->id))->not->toBeNull();
    });

    it('needs staff.delete', function () {
        $person = member();

        api(manager(['staff.view', 'staff.create', 'staff.edit']))->deleteJson('/api/admin/staff/'.$person->id)->assertStatus(403);

        expect(Admin::find($person->id))->not->toBeNull();
    });

    it('returns 404 for unknown staff', function () {
        api(manager())->deleteJson('/api/admin/staff/99999')->assertStatus(404);
    });

    it('writes to the activity log', function () {
        $actor = manager();
        $person = member('Sales', ['name' => 'Leaving Soon']);

        api($actor)->deleteJson('/api/admin/staff/'.$person->id)->assertNoContent();

        $log = ActivityLog::where('action', 'staff.deleted')->sole();
        expect($log->actor->is($actor))->toBeTrue()->and($log->subject_id)->toBe($person->id)
            ->and($log->properties['before'])->toEqual(['name' => 'Leaving Soon', 'phone' => $person->phone, 'role' => 'Sales', 'status' => 'active']);
    });
});

describe('POST /staff/{id}/resend-invite', function () {
    it('sends a fresh invite and voids the old link', function () {
        Notification::fake();
        $actor = manager();
        api($actor)->postJson('/api/admin/staff', newStaff())->assertCreated();
        $person = Admin::where('email', 'new.person@example.com')->sole();
        $oldToken = Notification::sent($person, AdminInviteNotification::class)->first()->token;

        api($actor)->postJson("/api/admin/staff/{$person->id}/resend-invite")->assertOk()->assertJsonStructure(['message']);

        Notification::assertSentToTimes($person, AdminInviteNotification::class, 2);
        $newToken = Notification::sent($person, AdminInviteNotification::class)->last()->token;
        expect($newToken)->not->toBe($oldToken);

        $set = fn (string $token) => spaCall()->postJson('/api/admin/reset-password', [
            'token' => $token, 'email' => $person->email, 'password' => 'Brand-New-Pass-77', 'password_confirmation' => 'Brand-New-Pass-77',
        ]);
        $set($oldToken)->assertStatus(422);
        $set($newToken)->assertOk();
    });

    it('refuses someone who has already signed in', function () {
        Notification::fake();
        $person = member('Sales', ['last_login_at' => now()->subDay()]);

        api(manager())->postJson("/api/admin/staff/{$person->id}/resend-invite")->assertStatus(422)->assertJsonPath('code', 'already_active');

        Notification::assertNothingSent();
    });

    it('needs staff.create, not just staff.edit', function () {
        Notification::fake();
        $person = member('Sales', ['last_login_at' => null]);

        api(manager(['staff.view', 'staff.edit', 'staff.delete']))->postJson("/api/admin/staff/{$person->id}/resend-invite")->assertStatus(403);

        Notification::assertNothingSent();
    });

    it('returns 404 for unknown staff, and protects Super Admins from non-Super Admins', function () {
        Notification::fake();
        $boss = superAdmin();

        api(manager())->postJson('/api/admin/staff/99999/resend-invite')->assertStatus(404);
        api(manager())->postJson("/api/admin/staff/{$boss->id}/resend-invite")->assertStatus(403)->assertJsonPath('code', 'super_admin_only');
    });

    it('writes to the activity log', function () {
        Notification::fake();
        $person = member('Sales');

        api(manager())->postJson("/api/admin/staff/{$person->id}/resend-invite")->assertOk();

        expect(ActivityLog::where('action', 'staff.invite_resent')->sole()->subject_id)->toBe($person->id);
    });
});

describe('staff page data', function () {
    it('shows an account as invited until the person sets a password', function () {
        Notification::fake();
        $actor = manager();
        api($actor)->postJson('/api/admin/staff', newStaff())->assertCreated()->assertJsonPath('data.status', 'invited');
        $person = Admin::where('email', 'new.person@example.com')->sole();
        expect($person->invited_at)->not->toBeNull();

        $token = Notification::sent($person, AdminInviteNotification::class)->first()->token;
        spaCall()->postJson('/api/admin/reset-password', [
            'token' => $token, 'email' => $person->email, 'password' => 'Brand-New-Pass-77', 'password_confirmation' => 'Brand-New-Pass-77',
        ])->assertOk();

        expect($person->fresh()->invited_at)->toBeNull();
        api($actor)->getJson('/api/admin/staff/'.$person->id)->assertJsonPath('data.status', 'active')->assertJsonPath('data.invitedAt', null);
    });

    it('stamps invitedAt again when the invite is re-sent', function () {
        Notification::fake();
        $person = member('Sales', ['last_login_at' => null]);
        $person->forceFill(['invited_at' => now()->subDays(2)])->save();

        api(manager())->postJson("/api/admin/staff/{$person->id}/resend-invite")->assertOk();

        expect($person->fresh()->invited_at->isToday())->toBeTrue();
    });

    it('shows suspended before invited', function () {
        $person = member('Sales', ['status' => 'suspended']);
        $person->forceFill(['invited_at' => now()])->save();

        api(manager())->getJson('/api/admin/staff/'.$person->id)->assertJsonPath('data.status', 'suspended');
    });

    it('filters by the invited status without mixing it into active', function () {
        member('Sales', ['name' => 'Done Setup']);
        tap(member('Sales', ['name' => 'Waiting Invite']))->forceFill(['invited_at' => now()])->save();
        member('Sales', ['name' => 'Gone Quiet', 'status' => 'suspended']);
        $viewer = manager(['staff.view']);
        $names = fn (string $q) => collect(api($viewer)->getJson('/api/admin/staff?'.$q)->assertOk()->json('data'))->pluck('name')->sort()->values()->all();

        expect($names('status=invited'))->toBe(['Waiting Invite'])
            ->and($names('status=suspended'))->toBe(['Gone Quiet'])
            ->and($names('status=active'))->toContain('Done Setup')->not->toContain('Waiting Invite', 'Gone Quiet');
    });

    it('sends tab counts that follow the role filter and the search, not the status tab', function () {
        $sales = roleNamed('Sales');
        $support = roleNamed('Support Staff');
        member('Sales', ['name' => 'Anna Sales', 'email' => 'p1@host.test']);
        tap(member('Sales', ['name' => 'Anders Sales', 'email' => 'p2@host.test']))->forceFill(['invited_at' => now()])->save();
        member('Sales', ['name' => 'Bob Sales', 'status' => 'suspended', 'email' => 'p3@host.test']);
        member('Support Staff', ['name' => 'Anna Support', 'email' => 'p4@host.test']);
        $viewer = manager(['staff.view']);
        $counts = fn (string $q = '') => api($viewer)->getJson('/api/admin/staff'.($q ? '?'.$q : ''))->assertOk()->json('meta.counts');

        expect($counts('role='.$sales->id))->toBe(['all' => 3, 'active' => 1, 'invited' => 1, 'suspended' => 1])
            ->and($counts('role='.$sales->id.'&status=suspended'))->toBe(['all' => 3, 'active' => 1, 'invited' => 1, 'suspended' => 1])
            ->and($counts('role='.$support->id))->toBe(['all' => 1, 'active' => 1, 'invited' => 0, 'suspended' => 0])
            ->and($counts('search=an&role='.$sales->id))->toBe(['all' => 2, 'active' => 1, 'invited' => 1, 'suspended' => 0])
            ->and($counts('search=nobody'))->toBe(['all' => 0, 'active' => 0, 'invited' => 0, 'suspended' => 0]);
    });

    it('sends the card stats for everyone, whatever the filters', function () {
        $viewer = manager(['staff.view']); // active, signed up, no 2FA
        $twoFactor = ['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()];
        member('Sales', $twoFactor);
        member('Sales', $twoFactor);
        member('Support Staff');
        tap(member('Sales', $twoFactor))->forceFill(['invited_at' => now()])->save();
        member('Sales', ['status' => 'suspended', ...$twoFactor]);
        superAdmin();
        tap(superAdmin())->update(['status' => 'suspended']);

        $stats = api($viewer)->getJson('/api/admin/staff?status=suspended&search=zzz')->assertOk()->json('meta.stats');

        expect($stats)->toBe([
            'total' => 8, 'active' => 5, 'invited' => 1, 'suspended' => 2,
            // Of the 5 signed-up active people, 2 have two-factor; invited and suspended ones are not counted.
            'twoFactorOn' => 2, 'twoFactorOf' => 5,
            'roles' => 6, 'activeSuperAdmins' => 1,
        ]);
    });

    it('shows the browser and platform of the latest successful sign-in only', function () {
        $person = member('Sales');
        $person->loginHistories()->create(['email_attempted' => $person->email, 'successful' => true, 'browser' => 'Firefox', 'platform' => 'Linux', 'created_at' => now()->subDays(3)]);
        $person->loginHistories()->create(['email_attempted' => $person->email, 'successful' => true, 'browser' => 'Chrome', 'platform' => 'Windows', 'created_at' => now()->subDay()]);
        $person->loginHistories()->create(['email_attempted' => $person->email, 'successful' => false, 'browser' => 'Safari', 'platform' => 'iOS', 'failure_reason' => 'invalid_credentials']);

        $row = collect(api(manager())->getJson('/api/admin/staff')->json('data'))->firstWhere('id', (string) $person->id);

        expect($row['lastLoginDevice'])->toBe('Chrome on Windows');
    });

    it('names the person who suspended someone, from the activity log', function () {
        $actor = manager();
        $person = member('Sales', ['name' => 'Imran Kabir']);

        putStaff($actor, $person, ['status' => 'suspended'])->assertOk()->assertJsonPath('data.suspendedBy', $actor->name);

        $row = collect(api($actor)->getJson('/api/admin/staff?status=suspended')->json('data'))->firstWhere('id', (string) $person->id);
        expect($row['suspendedBy'])->toBe($actor->name);
        // Reactivated: no longer shown.
        putStaff($actor, $person->fresh(), ['status' => 'active'])->assertOk()->assertJsonPath('data.suspendedBy', null);
    });

    it('uses a fixed number of queries however many people are listed', function () {
        $viewer = manager(['staff.view']);
        $count = function () use ($viewer) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            api($viewer)->getJson('/api/admin/staff')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $count(); // warm the permission cache

        Admin::factory()->count(2)->create()->each->assignRole('Sales');
        $few = $count();
        Admin::factory()->count(12)->create()->each(fn ($a) => $a->assignRole('Sales'));

        expect($count())->toBe($few);
    });
});

describe('GET /staff/roles', function () {
    it('lists every role with its description for the role picker', function () {
        $roles = api(manager(['staff.view']))->getJson('/api/admin/staff/roles')->assertOk()->json('data');

        expect(collect($roles)->pluck('name')->all())->toContain('Super Admin', 'Admin', 'Billing Manager', 'Support Staff', 'Sales')
            ->and(collect($roles)->firstWhere('name', 'Sales'))->toBe([
                'id' => (string) roleNamed('Sales')->id, 'name' => 'Sales', 'description' => 'Creates clients and orders, sees products.', 'isSuperAdmin' => false,
            ])
            ->and(collect($roles)->firstWhere('name', 'Super Admin')['isSuperAdmin'])->toBeTrue();
    });

    it('needs only staff.view, not roles.view', function () {
        api(staffWith(['staff.view'], 'Viewer'))->getJson('/api/admin/staff/roles')->assertOk();
        api(staffWith(['roles.view', 'staff.create'], 'Other'))->getJson('/api/admin/staff/roles')->assertStatus(403);
    });
});
