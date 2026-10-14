<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientLoginHistory extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'client_user_id', 'email_attempted', 'ip_address', 'user_agent', 'browser', 'platform', 'successful', 'failure_reason',
        'impersonated_by_admin_id',
    ];

    protected function casts(): array
    {
        return ['successful' => 'boolean', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(ClientUser::class, 'client_user_id');
    }

    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'impersonated_by_admin_id');
    }
}
