<?php

namespace App\Services;

use App\Exceptions\ApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Checks the Google ID token ("credential") that the Google Identity Services button gives the browser.
 * Google verifies the signature and expiry for us (tokeninfo endpoint); we then check that the token was issued
 * for OUR client id, by Google, and that Google has verified the email address.
 */
class GoogleIdTokenVerifier
{
    private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    /** @return array{sub: string, email: string, name: string} */
    public function verify(string $credential): array
    {
        $clientId = config('services.google.client_id');
        if (! $clientId) {
            throw new ApiException(503, 'google_not_configured', 'Sign in with Google is not available right now.');
        }

        try {
            $response = Http::timeout(8)->acceptJson()->get(config('services.google.tokeninfo_url'), ['id_token' => $credential]);
        } catch (ConnectionException $e) {
            report($e);

            throw new ApiException(502, 'google_unreachable', 'We could not reach Google. Please try again.');
        }

        $claims = $response->successful() ? $response->json() : null;

        $valid = is_array($claims)
            && ($claims['aud'] ?? null) === $clientId
            && in_array($claims['iss'] ?? null, self::ISSUERS, true)
            && (int) ($claims['exp'] ?? 0) > now()->getTimestamp()
            && filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)
            && ! empty($claims['sub'])
            && filter_var($claims['email'] ?? '', FILTER_VALIDATE_EMAIL);

        if (! $valid) {
            throw new ApiException(422, 'invalid_google_token', 'Google sign-in failed. Please try again.');
        }

        return [
            'sub' => (string) $claims['sub'],
            'email' => strtolower($claims['email']),
            'name' => trim((string) ($claims['name'] ?? '')) ?: strstr($claims['email'], '@', true),
        ];
    }
}
