<?php

namespace App\Services;

use App\Models\ClientUser;

/**
 * The link in the "verify your email" message. It points to the client panel, which then POSTs the four values to the API:
 *   {CLIENT_APP_URL}/verify-email?id=..&hash=..&expires=..&signature=..
 * `hash` is sha1 of the email address, so changing the address kills older links; `signature` is an HMAC (with the app
 * key) over id, hash and expiry, so none of the three can be edited.
 */
class EmailVerificationLink
{
    public const VALID = 'valid';

    public const EXPIRED = 'expired';

    public const INVALID = 'invalid';

    public function make(ClientUser $user): string
    {
        $expires = now()->addMinutes((int) config('everdigitech.email_verification_minutes', 60))->getTimestamp();
        $hash = $this->hash($user);

        return config('everdigitech.client_url').'/verify-email?'.http_build_query([
            'id' => $user->getKey(),
            'hash' => $hash,
            'expires' => $expires,
            'signature' => $this->sign($user->getKey(), $hash, $expires),
        ]);
    }

    /** Whether the four values from a link are genuine, and still fresh, for this user. */
    public function check(ClientUser $user, string $hash, int|string $expires, string $signature): string
    {
        $expires = (int) $expires;
        $genuine = hash_equals($this->sign($user->getKey(), $hash, $expires), $signature) && hash_equals($this->hash($user), $hash);

        return match (true) {
            ! $genuine => self::INVALID,
            $expires < now()->getTimestamp() => self::EXPIRED,
            default => self::VALID,
        };
    }

    private function hash(ClientUser $user): string
    {
        return sha1($user->getEmailForVerification());
    }

    private function sign(int|string $id, string $hash, int $expires): string
    {
        return hash_hmac('sha256', "{$id}|{$hash}|{$expires}", (string) config('app.key'));
    }
}
