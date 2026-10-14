<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A one-time "Log in as client" link. Only the hash of the token is stored; see App\Services\Impersonation. */
class ImpersonationToken extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(ClientUser::class, 'client_user_id')->withTrashed();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class, 'account_id');
    }
}
