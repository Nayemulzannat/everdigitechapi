<?php

namespace App\Models;

use App\Enums\ClientUserStatus;
use App\Notifications\ClientResetPasswordNotification;
use App\Notifications\ClientVerifyEmailNotification;
use Database\Factories\ClientUserFactory;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/** A person who signs in to the client panel (guard `client`). Reaches accounts through AccountMember. */
class ClientUser extends Authenticatable implements MustVerifyEmailContract
{
    /** @use HasFactory<ClientUserFactory> */
    use HasFactory, MustVerifyEmail, Notifiable, SoftDeletes;

    protected $fillable = ['name', 'email', 'phone', 'password', 'email_verified_at', 'status'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'status' => ClientUserStatus::class,
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'failed_login_attempts' => 'integer',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /** The accounts this person belongs to (as owner or member). */
    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(ClientAccount::class, 'account_members', 'user_id', 'account_id')
            ->using(AccountMember::class)
            ->withPivot(['id', 'role', 'permissions', 'hosting_scope', 'domain_scope', 'status', 'joined_at'])
            ->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(AccountMember::class, 'user_id');
    }

    /** The account the panel opens on. */
    public function lastAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class, 'last_account_id');
    }

    public function loginHistories(): HasMany
    {
        return $this->hasMany(ClientLoginHistory::class);
    }

    public function isSuspended(): bool
    {
        return $this->status === ClientUserStatus::Suspended;
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function hasVerifiedPhone(): bool
    {
        return $this->phone_verified_at !== null;
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ClientSession::class);
    }

    public function pendingEmailChange(): HasOne
    {
        return $this->hasOne(PendingEmailChange::class);
    }

    /** The reset link points to the client panel ({CLIENT_APP_URL}/reset-password?token=..&email=..). */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ClientResetPasswordNotification($token));
    }

    /** The link in this email points to the client panel (see App\Services\EmailVerificationLink), not to the API. */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new ClientVerifyEmailNotification);
    }
}
