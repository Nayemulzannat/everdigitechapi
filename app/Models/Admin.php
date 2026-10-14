<?php

namespace App\Models;

use App\Enums\AdminStatus;
use App\Notifications\AdminResetPasswordNotification;
use Database\Factories\AdminFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/** Staff member who signs in to the admin panel (guard `admin`). */
class Admin extends Authenticatable
{
    /** @use HasFactory<AdminFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    public const SUPER_ADMIN_ROLE = 'Super Admin';

    protected string $guard_name = 'admin';

    protected $fillable = ['name', 'email', 'phone', 'avatar_path', 'password', 'status'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'status' => AdminStatus::class,
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'invited_at' => 'datetime',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
            'failed_login_attempts' => 'integer',
        ];
    }

    public function loginHistories(): HasMany
    {
        return $this->hasMany(AdminLoginHistory::class);
    }

    /** The most recent successful sign-in (browser, platform), for the staff list. */
    public function latestLogin(): HasOne
    {
        return $this->hasOne(AdminLoginHistory::class)->ofMany(['id' => 'max'], fn (Builder $q) => $q->where('successful', true));
    }

    /** Active and past the invite: the person has set a password. */
    public function scopeSignedUp(Builder $query): void
    {
        $query->where('status', AdminStatus::Active->value)->whereNull('invited_at');
    }

    /** Active but still waiting for the person to set a password. */
    public function scopeInvited(Builder $query): void
    {
        $query->where('status', AdminStatus::Active->value)->whereNotNull('invited_at');
    }

    /** What the staff list shows: suspended wins, then invited, otherwise active. */
    public function displayStatus(): string
    {
        return match (true) {
            $this->status === AdminStatus::Suspended => 'suspended',
            $this->invited_at !== null => 'invited',
            default => 'active',
        };
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(self::SUPER_ADMIN_ROLE);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new AdminResetPasswordNotification($token));
    }

    public function isSuspended(): bool
    {
        return $this->status === AdminStatus::Suspended;
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }
}
