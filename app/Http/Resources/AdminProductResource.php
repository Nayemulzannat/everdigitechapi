<?php

namespace App\Http\Resources;

use App\Enums\BillingCycle;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A product for the admin panel (camelCase). Money is a whole number of paisa. Load `group` and `prices` first. @property Product $resource */
class AdminProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $product = $this->resource;
        $order = array_column(BillingCycle::cases(), 'value');

        return [
            'id' => (string) $product->getKey(),
            'groupId' => (string) $product->product_group_id,
            'groupName' => $product->group?->name,
            'name' => $product->name,
            'slug' => $product->slug,
            'tagline' => $product->tagline,
            'description' => $product->description,
            'features' => array_values((array) ($product->features ?? [])),
            'featureGroups' => $product->featureSections(),
            'setupFee' => $product->setup_fee,
            'isActive' => $product->is_active,
            'isFeatured' => $product->is_featured,
            'sortOrder' => $product->sort_order,
            // Shortest cycle first, so the first one is the "from" price.
            'prices' => $product->prices->sortBy(fn ($price) => array_search($price->cycle->value, $order, true))->values()->map(fn ($price) => [
                'cycle' => $price->cycle->value,
                'label' => $price->cycle->label(),
                'months' => $price->cycle->months(),
                'amount' => $price->amount,
                'compareAmount' => $price->compare_amount,
            ])->all(),
        ];
    }
}
