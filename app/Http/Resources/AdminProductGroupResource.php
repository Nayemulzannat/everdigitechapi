<?php

namespace App\Http\Resources;

use App\Models\ProductGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A catalog group for the admin panel (camelCase). Load `products_count` with withCount('products'). @property ProductGroup $resource */
class AdminProductGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $group = $this->resource;

        return [
            'id' => (string) $group->getKey(),
            'name' => $group->name,
            'slug' => $group->slug,
            'type' => $group->type->value,
            'typeLabel' => $group->type->label(),
            'description' => $group->description,
            'sortOrder' => $group->sort_order,
            'isActive' => $group->is_active,
            'productsCount' => (int) ($group->products_count ?? $group->products()->count()),
        ];
    }
}
