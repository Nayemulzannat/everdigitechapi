<?php

namespace App\Services;

use App\Models\PendingEmailChange;

/**
 * The link sent to the NEW address when a client asks to change their email:
 *   {CLIENT_APP_URL}/confirm-email-change?id=..&hash=..&expires=..&signature=..
 * `id` is the pending request, `hash` is sha1 of the new address, `signature` is an HMAC over a label, id, hash and expiry.
 * (The label keeps this signature useless as an email-verification signature, and the other way round.)
 */
class EmailChangeLink
{
    public const MINUTES = 60;

    public const VALID = 'valid';

    public const EXPIRED = 'expired';

    public const INVALID = 'invalid';

    public function make(PendingEmailChange $change): string
    {
        $expires = $change->expires_at->getTimestamp();
        $hash = sha1($change->new_email);

        return config('everdigitech.client_url').'/confirm-email-change?'.http_build_query([
            'id' => $change->getKey(),
            'hash' => $hash,
            'expires' => $expires,
            'signature' => $this->sign($change->getKey(), $hash, $expires),
        ]);
    }

    public function check(PendingEmailChange $change, string $hash, int|string $expires, string $signature): string
    {
        $expires = (int) $expires;
        $genuine = hash_equals($this->sign($change->getKey(), $hash, $expires), $signature)
            && hash_equals(sha1($change->new_email), $hash)
            && $expires === $change->expires_at->getTimestamp();

        return match (true) {
            ! $genuine => self::INVALID,
            $expires < now()->getTimestamp() => self::EXPIRED,
            default => self::VALID,
        };
    }

    private function sign(int|string $id, string $hash, int $expires): string
    {
        return hash_hmac('sha256', "email-change|{$id}|{$hash}|{$expires}", (string) config('app.key'));
    }
}
