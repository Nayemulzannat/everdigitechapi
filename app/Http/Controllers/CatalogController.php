<?php

namespace App\Http\Controllers;

use App\Enums\BillingCycle;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\Pricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/catalog: what the public site and the order form show. No sign-in. Hidden groups and products are left out.
 * Every price is given as { net, tax, total } in paisa, worked out with the tax setting, so a page never does tax sums.
 * Optional ?group=<slug> narrows it to one group.
 */
class CatalogController extends Controller
{
    public function __invoke(Request $request, Pricing $pricing): JsonResponse
    {
        $request->validate(['group' => ['sometimes', 'string', 'max:120']]);
        $order = array_column(BillingCycle::cases(), 'value');

        $groups = ProductGroup::query()->where('is_active', true)
            ->when($request->query('group'), fn ($q, $slug) => $q->where('slug', $slug))
            ->with(['products' => fn ($q) => $q->where('is_active', true)->ordered()->with('prices')])
            ->ordered()->get();

        return response()->json(['data' => [
            'currency' => (string) config('everdigitech.currency'),
            'tax' => $pricing->tax(),
            'groups' => $groups->map(fn (ProductGroup $group) => [
                'id' => (string) $group->getKey(),
                'name' => $group->name,
                'slug' => $group->slug,
                'type' => $group->type->value,
                'description' => $group->description,
                'products' => $group->products->filter(fn (Product $p) => $p->prices->isNotEmpty())->values()->map(fn (Product $product) => [
                    'id' => (string) $product->getKey(),
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'tagline' => $product->tagline,
                    'description' => $product->description,
                    'features' => array_values((array) ($product->features ?? [])),
                    'featureGroups' => $product->featureSections(),
                    'featured' => $product->is_featured,
                    'setupFee' => $pricing->breakdown($product->setup_fee),
                    'prices' => $product->prices->sortBy(fn ($price) => array_search($price->cycle->value, $order, true))->values()->map(fn ($price) => [
                        'cycle' => $price->cycle->value,
                        'label' => $price->cycle->label(),
                        'months' => $price->cycle->months(),
                        ...$pricing->breakdown($price->amount),
                        'compare' => $price->compare_amount ? $pricing->breakdown($price->compare_amount) : null,
                    ])->all(),
                ])->all(),
            ])->filter(fn (array $g) => $g['products'] !== [])->values()->all(),
        ]]);
    }
}
