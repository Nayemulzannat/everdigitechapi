<?php

namespace App\Models;

use App\Enums\MemberRole;
use App\Enums\MemberStatus;
use App\Enums\ResourceScope;
use Database\Factories\AccountMemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * One person's access to one account. Used as the pivot of ClientUser::accounts() and ClientAccount::users().
 * `permissions` is a list of keys; owners have everything. hosting_scope / domain_scope say whether the member
 * sees all of the account's services and domains or only the ones listed in AccountMemberGrant.
 */
class AccountMember extends Pivot
{
    /** @use HasFactory<AccountMemberFactory> */
    use HasFactory;

    protected $table = 'account_members';

    public $incrementing = true;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'role' => MemberRole::class,
            'permissions' => 'array',
            'hosting_scope' => ResourceScope::class,
            'domain_scope' => ResourceScope::class,
            'status' => MemberStatus::class,
            'joined_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class, 'account_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(ClientUser::class, 'user_id')->withTrashed();
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(ClientUser::class, 'invited_by')->withTrashed();
    }

    /** The specific hosting services / domains this member can reach when a scope is "selected". */
    public function grants(): HasMany
    {
        return $this->hasMany(AccountMemberGrant::class, 'member_id');
    }

    public function isOwner(): bool
    {
        return $this->role === MemberRole::Owner;
    }
}
