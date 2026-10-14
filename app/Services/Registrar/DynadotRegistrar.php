<?php

namespace App\Services\Registrar;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Http;

/**
 * Dynadot API v3. Two rules of its search command shape this class: it takes ONE domain per request ("too many domains
 * entered"), and an account may run only ONE request at a time ("currently processing another request from this account").
 * So the domains are checked one after the other, and a "busy" answer is tried again after a moment.
 *
 * The key stays on the server (DYNADOT_API_KEY) and Dynadot only accepts requests from IP addresses allowed in its API settings.
 */
class DynadotRegistrar implements Registrar
{
    public function search(array $domains): array
    {
        $key = config('services.dynadot.key');
        if (! $key) {
            throw new ApiException(503, 'registrar_not_configured', "Domain search isn't connected yet.");
        }

        $available = [];
        foreach ($domains as $domain) {
            $available[$domain] = $this->check($domain, (string) $key);
        }

        return $available;
    }

    public function extensions(): array
    {
        $key = config('services.dynadot.key');
        if (! $key) {
            throw new ApiException(503, 'registrar_not_configured', "The domain registrar isn't connected yet.");
        }

        try {
            $data = Http::timeout(40)->get(config('services.dynadot.url'), ['key' => $key, 'command' => 'tld_price', 'currency' => 'USD'])->json();
        } catch (\Throwable $e) {
            report($e);
            throw new ApiException(502, 'registrar_unavailable', 'We could not reach the domain registrar. Please try again.');
        }

        $list = $data['TldPriceResponse']['TldPrice'] ?? null;
        if (! is_array($list) || ($data['TldPriceResponse']['ResponseCode'] ?? null) === -1) {
            report(new \RuntimeException('Dynadot tld_price failed: '.($data['TldPriceResponse']['Error'] ?? 'unexpected response')));
            throw new ApiException(502, 'registrar_unavailable', 'The domain registrar did not answer as expected. Please try again.');
        }

        $cents = fn ($value) => is_numeric($value) ? (int) round(((float) $value) * 100) : null;

        return array_values(array_filter(array_map(function (array $tld) use ($cents) {
            $extension = strtolower(trim((string) ($tld['Tld'] ?? '')));
            if ($extension === '' || ! preg_match('/^\.[a-z0-9-]{2,}(\.[a-z0-9-]{2,})?$/', $extension)) {
                return null;
            }
            $restrictions = trim((string) ($tld['Restrictions'] ?? ''));

            return [
                'extension' => $extension,
                'usage' => ($tld['Usage'] ?? null) ?: null,
                'restrictions' => in_array($restrictions, ['', '--'], true) ? null : mb_substr($restrictions, 0, 255),
                'cost_register' => $cents($tld['Price']['Register'] ?? null),
                'cost_renew' => $cents($tld['Price']['Renew'] ?? null),
                'cost_transfer' => $cents($tld['Price']['Transfer'] ?? null),
            ];
        }, $list)));
    }

    private function check(string $domain, string $key, int $attempt = 1): bool
    {
        try {
            $data = Http::timeout(15)->get(config('services.dynadot.url'), ['key' => $key, 'command' => 'search', 'domain0' => $domain])->json();
        } catch (\Throwable $e) {
            report($e);
            throw new ApiException(502, 'registrar_unavailable', 'We could not reach the domain registrar. Please try again.');
        }

        $result = $data['SearchResponse']['SearchResults'][0] ?? null;
        if ($result && ($data['SearchResponse']['ResponseCode'] ?? null) !== '-1') {
            return ($result['Available'] ?? '') === 'yes';
        }

        $error = (string) ($data['SearchResponse']['Error'] ?? $data['Response']['Error'] ?? 'unexpected response');
        if ($attempt < 3 && stripos($error, 'another request') !== false) {
            usleep(400_000 * $attempt);

            return $this->check($domain, $key, $attempt + 1);
        }

        report(new \RuntimeException("Dynadot search failed: {$error}"));
        throw new ApiException(502, 'registrar_unavailable', 'The domain registrar did not answer as expected. Please try again.');
    }
}
