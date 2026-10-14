<?php

namespace App\Http\Resources;

use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Models\Permission;

/**
 * The frontend's `Me`: { user: {id, name, email}, role: {id, name, isSuperAdmin}, permissions: ["module.action"] }.
 *
 * @property Admin $resource
 */
class AdminMeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $admin = $this->resource;
        $role = $admin->roles()->orderBy('id')->first();

        // A Super Admin gets every permission name, whatever is attached to the role.
        $permissions = $admin->isSuperAdmin()
            ? Permission::query()->where('guard_name', 'admin')->orderBy('id')->pluck('name')
            : $admin->getAllPermissions()->sortBy('id')->pluck('name');

        return [
            'user' => [
                'id' => (string) $admin->getKey(),
                'name' => $admin->name,
                'email' => $admin->email,
            ],
            'role' => $role ? [
                'id' => (string) $role->getKey(),
                'name' => $role->name,
                'isSuperAdmin' => $role->name === Admin::SUPER_ADMIN_ROLE,
            ] : null,
            'permissions' => $permissions->values()->all(),
        ];
    }
}
