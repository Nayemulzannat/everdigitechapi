<?php

namespace App\Http\Resources;

use App\Models\Admin;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A role someone can be given on the staff form: id, name, description. Needs only staff.view, unlike /roles
 * (roles.view), so a staff manager can fill the role picker.
 *
 * @property Role $resource
 */
class StaffRoleOptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->resource->getKey(),
            'name' => $this->resource->name,
            'description' => $this->resource->description,
            'isSuperAdmin' => $this->resource->name === Admin::SUPER_ADMIN_ROLE,
        ];
    }
}
