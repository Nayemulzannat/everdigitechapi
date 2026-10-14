<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A signed-in browser of a client (see the client_sessions migration). */
class ClientSession extends Model
{
    protected $guarded = [];

    protected $hidden = ['session_id'];

    protected function casts(): array
    {
        return ['last_active_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(ClientUser::class, 'client_user_id');
    }
}
