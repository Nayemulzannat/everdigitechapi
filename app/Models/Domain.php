<?php

namespace App\Models;

use App\Enums\DomainStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A domain name an account owns. */
class Domain extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => DomainStatus::class,
            'registered_at' => 'date',
            'expires_at' => 'date',
            'auto_renew' => 'boolean',
            'locked' => 'boolean',
            'whois_privacy' => 'boolean',
            'nameservers' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class, 'account_id')->withTrashed();
    }

    public function events(): HasMany
    {
        return $this->hasMany(DomainEvent::class)->latest('id');
    }

    /** Days until it expires (negative once it has), or null when there is no date. */
    public function daysLeft(): ?int
    {
        return $this->expires_at ? (int) now()->startOfDay()->diffInDays($this->expires_at->copy()->startOfDay(), false) : null;
    }
}
