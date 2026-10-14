<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Checks a Cloudflare Turnstile token. With no TURNSTILE_SECRET (local development) there is nothing to check and every
 * token passes. With a secret, a missing token, a rejected token or an unreachable Cloudflare all fail: a spam check
 * that lets everything through when it can't reach its server is not a check.
 */
class CaptchaVerifier
{
    public function isEnabled(): bool
    {
        return filled(config('services.turnstile.secret'));
    }

    public function verify(?string $token, ?string $ip = null): bool
    {
        if (! $this->isEnabled()) {
            return true;
        }
        if (blank($token)) {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(5)->post(config('services.turnstile.verify_url'), array_filter([
                'secret' => config('services.turnstile.secret'),
                'response' => $token,
                'remoteip' => $ip,
            ]));

            return $response->successful() && $response->json('success') === true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
