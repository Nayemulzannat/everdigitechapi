<?php

namespace App\Services;

use App\Enums\MemberRole;
use App\Enums\MemberStatus;
use App\Exceptions\ApiException;
use App\Models\AccountInvitation;
use App\Models\AccountMember;
use App\Models\ClientAccount;
use App\Models\ClientUser;
use App\Notifications\ClientTeamInviteNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Inviting people to an account. The owner sends an invitation by email (7 days, one use, token stored hashed); the
 * person signs in (or signs up) with that same email address and accepts; then an account_members row is created.
 */
class TeamInvitations
{
    public const DAYS = 7;

    /** Active and suspended members, owner included. */
    public const MAX_MEMBERS = 10;

    public const MAX_PENDING = 10;

    public function __construct(private readonly ActivityLogger $activity) {}

    /** @param  list<string>  $permissions */
    public function invite(ClientAccount $account, ClientUser $inviter, string $email, array $permissions): AccountInvitation
    {
        $email = strtolower(trim($email));

        $alreadyMember = AccountMember::where('account_id', $account->getKey())
            ->whereIn('status', [MemberStatus::Active->value, MemberStatus::Suspended->value])
            ->whereHas('user', fn ($q) => $q->where('email', $email))
            ->exists();
        if ($alreadyMember) {
            throw new ApiException(422, 'already_member', 'This person is already a member of the account.');
        }

        $members = AccountMember::where('account_id', $account->getKey())->whereIn('status', [MemberStatus::Active->value, MemberStatus::Suspended->value])->count();
        $pending = AccountInvitation::where('account_id', $account->getKey())->pending()->where('email', '!=', $email)->count();
        if ($members + $pending >= self::MAX_MEMBERS) {
            throw new ApiException(422, 'team_full', 'The account has reached its limit of '.self::MAX_MEMBERS.' people, counting open invitations.');
        }
        if ($pending >= self::MAX_PENDING) {
            throw new ApiException(422, 'too_many_invitations', 'Too many open invitations. Cancel one first.');
        }

        // A new invitation for the same address replaces the earlier one, so only the latest link works.
        AccountInvitation::where('account_id', $account->getKey())->where('email', $email)->whereNull('accepted_at')->delete();

        $invitation = new AccountInvitation([
            'account_id' => $account->getKey(),
            'email' => $email,
            'permissions' => array_values($permissions),
            'invited_by' => $inviter->getKey(),
        ]);
        $this->send($invitation, $account, $inviter);

        $this->activity->log('team.invitation_sent', $account, ['email' => $email, 'permissions' => $permissions]);

        return $invitation;
    }

    /** A fresh link and a fresh 7 days. The earlier link stops working. */
    public function resend(AccountInvitation $invitation, ClientUser $sender): AccountInvitation
    {
        $this->send($invitation, $invitation->account, $sender);

        return $invitation;
    }

    /** Pending invitation for a token, or null (unknown, used, expired). */
    public function find(string $token): ?AccountInvitation
    {
        $invitation = AccountInvitation::with(['account', 'inviter'])->where('token_hash', hash('sha256', $token))->first();

        return $invitation?->isPending() && $invitation->account ? $invitation : null;
    }

    /** The signed-in person joins the account. Their email address must be the one that was invited, and confirmed. */
    public function accept(ClientUser $user, string $token): AccountMember
    {
        $invitation = $this->find($token);
        if (! $invitation) {
            throw new ApiException(422, 'invalid_invitation', 'This invitation is invalid, already used, or has expired. Ask for a new one.');
        }

        if (strtolower($user->email) !== strtolower($invitation->email)) {
            throw new ApiException(403, 'invitation_email_mismatch', 'This invitation was sent to '.self::mask($invitation->email).'. Sign in with that email address.');
        }

        if (! $user->hasVerifiedEmail()) {
            throw new ApiException(403, 'email_not_verified', 'Confirm your email address first, then accept the invitation.');
        }

        return DB::transaction(function () use ($user, $invitation) {
            // Only one request can win this update, so the link works once even under concurrent use.
            $claimed = AccountInvitation::whereKey($invitation->getKey())->whereNull('accepted_at')->update(['accepted_at' => now()]);
            if ($claimed !== 1) {
                throw new ApiException(422, 'invalid_invitation', 'This invitation is invalid, already used, or has expired. Ask for a new one.');
            }

            $member = AccountMember::firstOrNew(['account_id' => $invitation->account_id, 'user_id' => $user->getKey()]);
            if ($member->exists && $member->role === MemberRole::Owner) {
                return $member;
            }

            $member->forceFill([
                'role' => MemberRole::Member,
                'permissions' => $invitation->permissions,
                'status' => MemberStatus::Active,
                'invited_by' => $invitation->invited_by,
                'joined_at' => $member->joined_at ?? now(),
            ])->save();

            $this->activity->log('team.invitation_accepted', $invitation->account, ['email' => $user->email, 'member_id' => $member->getKey()], $user);

            return $member;
        });
    }

    /** "ra***@gmail.com": enough to recognise the address without handing it to anyone who found the link. */
    public static function mask(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2) + [1 => ''];

        return Str::substr($local, 0, 2).'***@'.$domain;
    }

    private function send(AccountInvitation $invitation, ClientAccount $account, ClientUser $sender): void
    {
        $token = Str::random(64);
        $invitation->forceFill(['token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(self::DAYS)])->save();

        Notification::route('mail', $invitation->email)->notify(new ClientTeamInviteNotification(
            config('everdigitech.client_url').'/accept-invite?token='.$token,
            $account->name,
            $sender->name,
        ));
    }
}
