<?php

namespace App\Http\Resources;

use App\Models\Role;
use App\Support\PermissionCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The frontend's Role. Load `permissions` and `users_count` first (withCount('users')) to avoid extra queries.
 *
 * @property Role $resource
 */
class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $granted = array_flip($this->resource->permissions->pluck('name')->all());

        return [
            'id' => (string) $this->resource->getKey(),
            'name' => $this->resource->name,
            'description' => $this->resource->description,
            'locked' => $this->resource->is_locked,
            'staffCount' => (int) $this->resource->users_count,
            // Granted keys in catalog order.
            'permissions' => array_values(array_filter(PermissionCatalog::keys(), fn (string $key) => isset($granted[$key]))),
        ];
    }
}
