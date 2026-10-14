<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Services\ActivityLogger;
use App\Support\PermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RoleController extends Controller
{
    public function __construct(private readonly ActivityLogger $activity) {}

    /** GET /api/admin/roles */
    public function index(): AnonymousResourceCollection
    {
        return RoleResource::collection(
            Role::query()->where('guard_name', 'admin')->with('permissions')->withCount('users')->orderBy('id')->get(),
        );
    }

    /** GET /api/admin/roles/{id} */
    public function show(string $id): RoleResource
    {
        return new RoleResource($this->find($id));
    }

    /** POST /api/admin/roles: a new role starts with no permissions. */
    public function store(RoleRequest $request): JsonResponse
    {
        $role = Role::create([
            'name' => $request->validated('name'),
            'guard_name' => 'admin',
            'description' => trim((string) $request->validated('description')),
            'is_locked' => false,
        ]);
        $this->forgetCache();

        $role = $this->find($role->getKey());
        $this->activity->log('role.created', $role, ['after' => $this->snapshot($role)]);

        return (new RoleResource($role))->response()->setStatusCode(201);
    }

    /** PUT /api/admin/roles/{id} */
    public function update(RoleRequest $request, string $id): RoleResource
    {
        $role = $this->find($id);
        $before = $this->snapshot($role);

        // Any permission of a module needs that module's view; unknown names were already rejected.
        $permissions = PermissionCatalog::normalize($request->validated('permissions'));

        $granting = array_filter(array_diff($permissions, $before['permissions']), PermissionCatalog::isSuperAdminOnly(...));
        if ($granting && ! $request->user('admin')->isSuperAdmin()) {
            throw new ApiException(403, 'super_admin_only', 'Only a Super Admin can grant staff and role permissions.');
        }

        DB::transaction(function () use ($role, $request, $permissions) {
            $role->update([
                'name' => $request->validated('name'),
                'description' => trim((string) $request->validated('description')),
            ]);
            $role->syncPermissions(Permission::query()->where('guard_name', 'admin')->whereIn('name', $permissions)->get());
        });
        $this->forgetCache();

        $role = $this->find($id);
        $this->activity->log('role.updated', $role, ['before' => $before, 'after' => $this->snapshot($role)]);

        return new RoleResource($role);
    }

    /** DELETE /api/admin/roles/{id} */
    public function destroy(string $id): Response
    {
        $role = $this->find($id);

        if ($role->is_locked) {
            throw new ApiException(403, 'role_locked', "This role is locked and can't be deleted.");
        }
        if ($role->users_count > 0) {
            throw new ApiException(422, 'role_in_use', 'This role still has staff. Move them to another role first.');
        }

        $before = $this->snapshot($role);
        $role->delete();
        $this->forgetCache();

        $this->activity->log('role.deleted', $role, ['before' => $before]);

        return response()->noContent();
    }

    private function find(string|int $id): Role
    {
        return Role::query()->where('guard_name', 'admin')->with('permissions')->withCount('users')->findOrFail($id);
    }

    /** @return array{name: string, description: string, permissions: list<string>} */
    private function snapshot(Role $role): array
    {
        return [
            'name' => $role->name,
            'description' => $role->description,
            'permissions' => (new RoleResource($role))->toArray(request())['permissions'],
        ];
    }

    private function forgetCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
