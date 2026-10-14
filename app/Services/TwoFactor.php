<?php

namespace App\Services;

use App\Models\ClientUser;

/**
 * Two-factor authentication with an authenticator app (TOTP, RFC 6238: SHA-1, 6 digits, 30 seconds) and one-time recovery
 * codes. A code is accepted from one step before to one step after now (clocks differ), and never twice.
 */
class TwoFactor
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const PERIOD = 30;

    public const RECOVERY_CODES = 8;

    public function newSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    /** The link an authenticator app reads (shown as a QR code). */
    public function uri(string $accountName, string $secret): string
    {
        $issuer = config('app.name', 'EverDigitech');

        return 'otpauth://totp/'.rawurlencode($issuer.':'.$accountName).'?'.http_build_query([
            'secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => 6, 'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** The 6-digit code for a moment (used by tests and by verify). */
    public function code(string $secret, ?int $time = null): string
    {
        return $this->codeForStep($secret, intdiv($time ?? time(), self::PERIOD));
    }

    /** True when the code is right and was not used before. Remembers it so it cannot be used again. */
    public function verify(ClientUser $user, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        $secret = $user->two_factor_secret;
        if (! $secret || ! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $now = intdiv(time(), self::PERIOD);
        foreach ([0, -1, 1] as $offset) {
            $step = $now + $offset;
            if (hash_equals($this->codeForStep($secret, $step), $code) && $step > (int) $user->two_factor_last_step) {
                $user->forceFill(['two_factor_last_step' => $step])->saveQuietly();

                return true;
            }
        }

        return false;
    }

    /** @return list<string> the new codes, shown to the person once. Only their hashes are kept. */
    public function newRecoveryCodes(ClientUser $user): array
    {
        $codes = array_map(fn () => implode('-', str_split(bin2hex(random_bytes(5)), 5)), range(1, self::RECOVERY_CODES));
        $user->forceFill(['two_factor_recovery_codes' => array_map(fn (string $c) => hash('sha256', $c), $codes)])->save();

        return $codes;
    }

    /** Uses up a recovery code. */
    public function useRecoveryCode(ClientUser $user, string $code): bool
    {
        $hash = hash('sha256', strtolower(preg_replace('/\s+/', '', $code)));
        $codes = (array) ($user->two_factor_recovery_codes ?? []);
        $index = array_search($hash, $codes, true);
        if ($index === false) {
            return false;
        }

        unset($codes[$index]);
        $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

        return true;
    }

    public function recoveryCodesLeft(ClientUser $user): int
    {
        return count((array) ($user->two_factor_recovery_codes ?? []));
    }

    private function codeForStep(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('N2', 0, $step), $this->base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        return implode('', array_map(fn (string $chunk) => self::ALPHABET[bindec(str_pad($chunk, 5, '0'))], str_split($bits, 5)));
    }

    private function base32Decode(string $text): string
    {
        $bits = '';
        foreach (str_split(strtoupper($text)) as $char) {
            $position = strpos(self::ALPHABET, $char);
            if ($position !== false) {
                $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
            }
        }

        return implode('', array_map(fn (string $byte) => chr(bindec($byte)), str_split(substr($bits, 0, intdiv(strlen($bits), 8) * 8), 8)));
    }
}
