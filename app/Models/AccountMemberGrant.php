<?php

namespace App\Models;

use App\Enums\ResourceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row of account_member_resources: member X may reach hosting service / domain Y. No id column: the
 * primary key is (member_id, resource_type, resource_id), so use create/delete, not update.
 */
class AccountMemberGrant extends Model
{
    protected $table = 'account_member_resources';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['resource_type' => ResourceType::class, 'resource_id' => 'integer'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(AccountMember::class, 'member_id');
    }
}
