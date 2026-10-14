<?php

namespace App\Models;

use App\Enums\ClientGroup;
use App\Enums\ClientStatus;
use Database\Factories\ClientAccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The customer. Owns the services, domains and invoices; people reach it through AccountMember.
 * "Client" in the admin panel is one of these.
 *
 * TODO: the tmp_* columns are placeholders for the numbers that will come from the services, domains and
 * invoices tables (see the migration that adds them). Replace them with withCount/withSum when those exist.
 */
class ClientAccount extends Model
{
    /** @use HasFactory<ClientAccountFactory> */
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'client_group' => ClientGroup::class,
            'status' => ClientStatus::class,
            'credit_balance' => 'decimal:2',
            'tmp_services_count' => 'integer',
            'tmp_domains_count' => 'integer',
            'tmp_balance_due' => 'decimal:2',
            'tmp_has_overdue' => 'boolean',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(ClientUser::class, 'owner_user_id')->withTrashed();
    }

    public function members(): HasMany
    {
        return $this->hasMany(AccountMember::class, 'account_id');
    }

    /** Every person with access, owner included. */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(ClientUser::class, 'account_members', 'account_id', 'user_id')
            ->using(AccountMember::class)
            ->withPivot(['id', 'role', 'permissions', 'hosting_scope', 'domain_scope', 'status', 'joined_at'])
            ->withTimestamps();
    }

    public function scopeOfGroup(Builder $query, ?ClientGroup $group): void
    {
        if ($group) {
            $query->where('client_group', $group->value);
        }
    }

    public function scopeOfStatus(Builder $query, ?ClientStatus $status): void
    {
        if ($status) {
            $query->where('status', $status->value);
        }
    }

    /** Matches the name, the company or the owner's email (contains, case-insensitive). */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);
        if ($term === '') {
            return;
        }

        // Escape LIKE wildcards so "100%" or "a_b" are searched literally.
        $like = '%'.addcslashes($term, '%_\\').'%';

        $query->where(fn (Builder $q) => $q
            ->where('name', 'like', $like)
            ->orWhere('company', 'like', $like)
            ->orWhereHas('owner', fn (Builder $owner) => $owner->where('email', 'like', $like)));
    }
}
