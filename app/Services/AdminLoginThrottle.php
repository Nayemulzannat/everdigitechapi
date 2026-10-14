<?php

namespace App\Services;

use App\Models\Admin;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Counts failed sign-ins per email + IP. The 5th failure within 15 minutes locks that pair for 15 minutes,
 * and, when the email belongs to an admin, also stores `locked_until` on the account.
 * Unknown emails are counted and locked the same way, so the response never reveals whether an account exists.
 */
class AdminLoginThrottle
{
    public const MAX_ATTEMPTS = 5;

    public const LOCK_MINUTES = 15;

    /** When the email + IP pair, or the account itself, is locked until; null when it is not locked. */
    public function lockedUntil(string $email, string $ip, ?Admin $admin): ?CarbonInterface
    {
        $stamp = Cache::get($this->key($email, $ip, 'lock'));
        $pair = $stamp ? Carbon::createFromTimestamp($stamp) : null;
        $account = $admin?->isLocked() ? $admin->locked_until : null;

        $until = collect([$pair, $account])->filter(fn (?CarbonInterface $d) => $d?->isFuture())->sortByDesc(fn ($d) => $d->getTimestamp())->first();

        return $until;
    }

    /** Records a failed attempt. Returns the lock expiry when this attempt triggered the lock. */
    public function hit(string $email, string $ip, ?Admin $admin): ?CarbonInterface
    {
        $admin?->increment('failed_login_attempts');

        $key = $this->key($email, $ip, 'attempts');
        Cache::add($key, 0, now()->addMinutes(self::LOCK_MINUTES));
        $attempts = Cache::increment($key);

        if ($attempts < self::MAX_ATTEMPTS) {
            return null;
        }

        $until = now()->addMinutes(self::LOCK_MINUTES);
        Cache::put($this->key($email, $ip, 'lock'), $until->getTimestamp(), $until);
        Cache::forget($key);
        $admin?->forceFill(['locked_until' => $until])->save();

        return $until;
    }

    public function clear(string $email, string $ip): void
    {
        Cache::forget($this->key($email, $ip, 'attempts'));
        Cache::forget($this->key($email, $ip, 'lock'));
    }

    public static function minutesLeft(CarbonInterface $until): int
    {
        return max(1, (int) ceil(now()->diffInSeconds($until, true) / 60));
    }

    private function key(string $email, string $ip, string $part): string
    {
        return 'admin-login:'.$part.':'.sha1(strtolower($email).'|'.$ip);
    }
}
