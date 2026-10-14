<?php

namespace App\Http\Controllers;

use App\Models\DomainExtension;
use App\Services\DomainPricing;
use App\Services\DomainSearch;
use App\Services\Pricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** The public domain search of the website. No sign-in. */
class DomainSearchController extends Controller
{
    private const DOMAIN = '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/';

    /** GET /api/domains/extensions: what we sell and what it costs (tax shown as net, tax and total). */
    public function extensions(Pricing $pricing, DomainPricing $rule): JsonResponse
    {
        return response()->json(['data' => [
            'tax' => $pricing->tax(),
            'extensions' => DomainExtension::query()->where('is_active', true)->ordered()->get()->map(fn (DomainExtension $e) => [
                'extension' => $e->extension,
                'tag' => $e->tag,
                'featured' => $e->is_featured,
                'register' => ($p = $e->price('register', $rule)) ? $pricing->breakdown($p) : null,
                'renew' => ($p = $e->price('renew', $rule)) ? $pricing->breakdown($p) : null,
                'transfer' => ($p = $e->price('transfer', $rule)) ? $pricing->breakdown($p) : null,
            ])->all(),
        ]]);
    }

    /** GET /api/domains/search?domains=a.com,a.net (up to 10). Only names with an extension we sell are checked. */
    public function search(Request $request, DomainSearch $search): JsonResponse
    {
        $names = collect(explode(',', (string) $request->query('domains')))
            ->map(fn (string $d) => strtolower(trim($d)))
            ->filter(fn (string $d) => $d !== '')
            ->unique()->values();

        if ($names->isEmpty() || $names->contains(fn (string $d) => ! preg_match(self::DOMAIN, $d))) {
            throw ValidationException::withMessages(['domains' => 'Enter a valid domain name, like yourbrand.com.']);
        }

        $active = DomainExtension::query()->where('is_active', true)->pluck('extension');
        $sold = $names->filter(fn (string $d) => $active->contains(fn (string $e) => str_ends_with($d, $e)))->take(DomainSearch::MAX)->values()->all();

        if ($sold === []) {
            throw ValidationException::withMessages(['domains' => "We don't sell this extension yet."]);
        }

        return response()->json(['data' => ['results' => $search->search($sold)]]);
    }
}
