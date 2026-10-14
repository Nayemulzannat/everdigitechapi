<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A pending (or used, or expired) invitation to join an account as a member. See App\Services\TeamInvitations. */
class AccountInvitation extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['permissions' => 'array', 'expires_at' => 'datetime', 'accepted_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class, 'account_id');
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(ClientUser::class, 'invited_by')->withTrashed();
    }

    /** Not used and not expired. */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }
}
