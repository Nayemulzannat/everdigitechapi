<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TaxSettingsRequest;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\Pricing;
use Illuminate\Http\JsonResponse;

/** The tax (VAT) shown on the catalog and added to what clients pay. settings.view reads, settings.edit changes. */
class TaxSettingsController extends Controller
{
    public function __construct(private readonly ActivityLogger $activity) {}

    /** GET /api/admin/settings/tax */
    public function show(Pricing $pricing): JsonResponse
    {
        return response()->json(['data' => $pricing->tax()]);
    }

    /** PUT /api/admin/settings/tax. Prices already in the catalog are not changed: only what the tax is worked out with. */
    public function update(TaxSettingsRequest $request): JsonResponse
    {
        $before = app(Pricing::class)->tax();
        $after = ['label' => $request->validated('label'), 'rate' => (float) $request->validated('rate'), 'inclusive' => $request->boolean('inclusive')];

        Setting::write('tax', $after);
        $this->activity->log('settings.tax_updated', null, ['before' => $before, 'after' => $after]);

        return response()->json(['data' => app(Pricing::class)->tax()]);
    }
}
