<?php

namespace App\Services;

use App\Enums\AdminStatus;
use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\Role;
use App\Notifications\AdminInviteNotification;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Throwable;

/** Staff rules in one place: who may change whom, the last-Super-Admin guard, session cut-off and the audit trail. */
class StaffService
{
    public function __construct(private readonly ActivityLogger $activity) {}

    /** @param  array{name: string, email: string, phone: ?string, role: int|string}  $data */
    public function create(Admin $actor, array $data): Admin
    {
        $role = $this->role($data['role']);
        $this->assertCanAssign($actor, $role);

        $admin = DB::transaction(function () use ($data, $role) {
            // No password is chosen by an admin: it is random and unknown until the invite link is used.
            $admin = Admin::create([
                'name' => $data['name'],
                'email' => strtolower($data['email']),
                'phone' => $data['phone'] ?? null,
                'password' => Hash::make(Str::random(64)),
                'status' => AdminStatus::Active,
            ]);
            $admin->syncRoles([$role]);

            // Inside the transaction: if the email can't be sent, the account is not created either, so the admin can
            // simply try again instead of finding an account nobody was told about.
            $this->sendInvite($admin);

            return $admin;
        });

        $this->activity->log('staff.created', $admin, ['after' => $this->snapshot($admin->refresh())], $actor);

        return $admin;
    }

    /** @param  array{name: string, phone: ?string, role: int|string, status: string}  $data */
    public function update(Admin $actor, Admin $admin, array $data): Admin
    {
        $this->assertCanManage($actor, $admin);

        $before = $this->snapshot($admin);
        $role = $this->role($data['role']);
        $status = AdminStatus::from($data['status']);
        $roleChanged = $admin->roles->first()?->getKey() !== $role->getKey();
        $suspending = $status === AdminStatus::Suspended && $admin->status !== AdminStatus::Suspended;

        if ($roleChanged) {
            $this->assertCanAssign($actor, $role);
        }
        if (($suspending || $roleChanged) && $this->isLastActiveSuperAdmin($admin)) {
            throw new ApiException(422, 'last_super_admin', 'This is the last active Super Admin. Make another Super Admin first.');
        }
        if ($suspending && $actor->is($admin)) {
            throw new ApiException(422, 'cannot_suspend_self', 'You can\'t suspend your own account.');
        }

        DB::transaction(function () use ($admin, $data, $role, $status, $roleChanged) {
            $admin->fill(['name' => $data['name'], 'phone' => $data['phone'] ?? null, 'status' => $status])->save();
            if ($roleChanged) {
                $admin->syncRoles([$role]);
            }
        });

        $admin->refresh();
        $after = $this->snapshot($admin);

        $statusChanged = $before['status'] !== $after['status'];
        if ($statusChanged) {
            $this->activity->log($suspending ? 'staff.suspended' : 'staff.activated', $admin, ['before' => ['status' => $before['status']], 'after' => ['status' => $after['status']]], $actor);
        }
        if (Arr::except($before, 'status') !== Arr::except($after, 'status')) {
            $this->activity->log('staff.updated', $admin, ['before' => Arr::except($before, 'status'), 'after' => Arr::except($after, 'status')], $actor);
        }

        // The change must apply now, not when the session next expires.
        if ($suspending || $roleChanged) {
            $this->endSessions($admin);
        }

        return $admin;
    }

    public function delete(Admin $actor, Admin $admin): void
    {
        $this->assertCanManage($actor, $admin);

        if ($this->isLastActiveSuperAdmin($admin)) {
            throw new ApiException(422, 'last_super_admin', 'This is the last active Super Admin. Make another Super Admin first.');
        }
        if ($actor->is($admin)) {
            throw new ApiException(422, 'cannot_delete_self', 'You can\'t delete your own account.');
        }

        $before = $this->snapshot($admin);
        $admin->delete();
        $this->endSessions($admin);

        $this->activity->log('staff.deleted', $admin, ['before' => $before], $actor);
    }

    public function resendInvite(Admin $actor, Admin $admin): void
    {
        $this->assertCanManage($actor, $admin);

        // An invite is only for someone who has not signed in yet.
        if ($admin->last_login_at !== null) {
            throw new ApiException(422, 'already_active', 'This person has already signed in. Use "Forgot password" instead.');
        }

        $this->sendInvite($admin);
        $this->activity->log('staff.invite_resent', $admin, [], $actor);
    }

    /** Deletes every session row of this admin and rotates the remember-me token, so nothing signs them back in. */
    public function endSessions(Admin $admin): void
    {
        DB::table(config('session.table', 'sessions'))->where('user_id', $admin->getKey())->delete();
        $admin->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
    }

    /** Whether this account is the only active, undeleted Super Admin. */
    public function isLastActiveSuperAdmin(Admin $admin): bool
    {
        if ($admin->status !== AdminStatus::Active || ! $admin->isSuperAdmin()) {
            return false;
        }

        return ! $this->superAdminRole()->users()->where('status', AdminStatus::Active->value)->whereKeyNot($admin->getKey())->exists();
    }

    /** Only a Super Admin can change a Super Admin's account. */
    private function assertCanManage(Admin $actor, Admin $target): void
    {
        if ($target->isSuperAdmin() && ! $actor->isSuperAdmin()) {
            throw new ApiException(403, 'super_admin_only', 'Only a Super Admin can change a Super Admin account.');
        }
    }

    private function assertCanAssign(Admin $actor, Role $role): void
    {
        if ($role->name === Admin::SUPER_ADMIN_ROLE && ! $actor->isSuperAdmin()) {
            throw new ApiException(403, 'super_admin_only', 'Only a Super Admin can assign the Super Admin role.');
        }
    }

    /** Emails the "set your password" link. A mail failure (SMTP down, wrong password) becomes a 502 `mail_failed`. */
    private function sendInvite(Admin $admin): void
    {
        $token = Password::broker('admin_invites')->createToken($admin);

        try {
            $admin->notify(new AdminInviteNotification($token));
        } catch (Throwable $e) {
            report($e);

            // The server answered "no such mailbox / domain" for this very address: almost always a typo (.comm).
            // Say so on the email field instead of blaming the mail settings.
            if ($e instanceof UnexpectedResponseException && in_array($e->getCode(), [550, 551, 553], true) && stripos($e->getMessage(), $admin->email) !== false) {
                throw ValidationException::withMessages(['email' => ["The mail server won't accept {$admin->email}. Check the address for typos."]]);
            }

            throw new ApiException(502, 'mail_failed', "We couldn't send the invite email. Check that the mail settings are correct, then try again.");
        }

        $admin->forceFill(['invited_at' => now()])->save();
    }

    private function role(int|string $id): Role
    {
        return Role::query()->where('guard_name', 'admin')->findOrFail($id);
    }

    private function superAdminRole(): Role
    {
        return Role::query()->where(['guard_name' => 'admin', 'name' => Admin::SUPER_ADMIN_ROLE])->firstOrFail();
    }

    /** @return array{name: string, phone: ?string, role: ?string, status: string} */
    private function snapshot(Admin $admin): array
    {
        return [
            'name' => $admin->name,
            'phone' => $admin->phone,
            'role' => $admin->roles()->first()?->name,
            'status' => $admin->status->value,
        ];
    }
}
