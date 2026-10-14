<?php

namespace App\Services;

use App\Models\DomainExtension;
use App\Services\Registrar\Registrar;
use Illuminate\Support\Facades\Cache;

/**
 * Checks domain names with the registrar and adds our price. Answers are kept for two minutes, so a name searched twice does
 * not use the registrar twice (which also keeps clear of its one-request-at-a-time rule).
 */
class DomainSearch
{
    public const MAX = 10;

    private const CACHE_SECONDS = 120;

    public function __construct(private readonly Registrar $registrar, private readonly Pricing $pricing, private readonly DomainPricing $rule) {}

    /**
     * @param  list<string>  $domains
     * @return list<array{domain: string, available: bool, extension: ?string, price: ?array{net: int, tax: int, total: int}, renewal: ?array{net: int, tax: int, total: int}}>
     */
    public function search(array $domains): array
    {
        $domains = array_slice(array_values(array_unique($domains)), 0, self::MAX);

        $known = [];
        $missing = [];
        foreach ($domains as $domain) {
            $cached = Cache::get($this->key($domain));
            if ($cached === null) {
                $missing[] = $domain;
            } else {
                $known[$domain] = $cached;
            }
        }

        if ($missing !== []) {
            foreach ($this->registrar->search($missing) as $domain => $available) {
                Cache::put($this->key($domain), $available, self::CACHE_SECONDS);
                $known[$domain] = $available;
            }
        }

        $extensions = DomainExtension::query()->get();

        return array_map(function (string $domain) use ($known, $extensions) {
            $extension = $extensions->sortByDesc(fn ($e) => strlen($e->extension))->first(fn ($e) => str_ends_with($domain, $e->extension));

            return [
                'domain' => $domain,
                'available' => (bool) $known[$domain],
                'extension' => $extension?->extension,
                'price' => ($p = $extension?->price('register', $this->rule)) ? $this->pricing->breakdown($p) : null,
                'renewal' => ($p = $extension?->price('renew', $this->rule)) ? $this->pricing->breakdown($p) : null,
            ];
        }, $domains);
    }

    private function key(string $domain): string
    {
        return 'domain-search:'.$domain;
    }
}
