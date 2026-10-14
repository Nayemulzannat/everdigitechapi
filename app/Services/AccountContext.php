<?php

namespace App\Services;

use App\Enums\MemberRole;
use App\Enums\MemberStatus;
use App\Models\AccountMember;
use App\Models\ClientUser;
use Illuminate\Database\Eloquent\Collection;

/**
 * Which account a signed-in client is working in. A person can belong to several accounts (their own, plus ones they
 * were invited to), so every request says which one, and it must be an account where their membership is active.
 */
class AccountContext
{
    /** Every account the person can work in: active memberships of accounts that still exist, oldest first. @return Collection<int, AccountMember> */
    public function memberships(ClientUser $user): Collection
    {
        return $this->activeMemberships($user)->with('account')->orderBy('id')->get();
    }

    /**
     * The membership to use: the requested account, or (when none is requested) the one the person last used, or the
     * account they own. Null when there is none, or when the requested account isn't one they may work in.
     */
    public function resolve(ClientUser $user, ?string $requestedAccountId = null): ?AccountMember
    {
        if ($requestedAccountId !== null && $requestedAccountId !== '') {
            return ctype_digit($requestedAccountId) ? $this->membershipIn($user, (int) $requestedAccountId) : null;
        }

        return ($user->last_account_id ? $this->membershipIn($user, (int) $user->last_account_id) : null)
            ?? $this->activeMemberships($user)->where('role', MemberRole::Owner->value)->with('account')->orderBy('id')->first();
    }

    /** The person's active membership of one account, or null. */
    public function membershipIn(ClientUser $user, int $accountId): ?AccountMember
    {
        return $this->activeMemberships($user)->where('account_id', $accountId)->with('account')->first();
    }

    private function activeMemberships(ClientUser $user)
    {
        return AccountMember::query()
            ->where('user_id', $user->getKey())
            ->where('status', MemberStatus::Active->value)
            ->whereHas('account'); // a soft-deleted account is not an account anymore
    }
}
