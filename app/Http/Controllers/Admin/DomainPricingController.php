<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\DomainPricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The rule that prices extensions nobody priced by hand: registrar cost x taka per dollar x (1 + margin), rounded up to a
 * multiple of `roundTo` taka. domains.view reads, domains.edit changes.
 */
class DomainPricingController extends Controller
{
    /** GET /api/admin/domain-pricing */
    public function show(DomainPricing $pricing): JsonResponse
    {
        return response()->json(['data' => $pricing->rule()]);
    }

    /** PUT /api/admin/domain-pricing {usdRate, marginPercent, roundTo}. Prices typed by hand are not touched. */
    public function update(Request $request, ActivityLogger $activity): JsonResponse
    {
        $data = $request->validate([
            'usdRate' => ['required', 'numeric', 'gt:0', 'max:100000', 'decimal:0,4'],
            'marginPercent' => ['required', 'numeric', 'min:0', 'max:1000', 'decimal:0,2'],
            'roundTo' => ['required', 'integer', 'min:1', 'max:1000'],
        ], [
            'usdRate.gt' => 'Enter how many taka one US dollar is, like 125.',
            'usdRate.decimal' => 'Use at most four decimals.',
            'marginPercent.decimal' => 'Use at most two decimals.',
        ]);

        $before = app(DomainPricing::class)->rule();
        Setting::write('domain_pricing', ['usdRate' => (float) $data['usdRate'], 'marginPercent' => (float) $data['marginPercent'], 'roundTo' => (int) $data['roundTo']]);
        $activity->log('settings.domain_pricing_updated', null, ['before' => $before, 'after' => $data]);

        return response()->json(['data' => app()->make(DomainPricing::class)->rule()]);
    }
}
