<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProductGroupRequest;
use App\Http\Resources\AdminProductGroupResource;
use App\Models\ProductGroup;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

/** Catalog groups (Web Hosting, VPS...). products.view reads, products.create adds, products.edit changes, products.delete removes. */
class ProductGroupController extends Controller
{
    public function __construct(private readonly ActivityLogger $activity) {}

    /** GET /api/admin/product-groups: all of them, hidden ones too, in the order the catalog shows them. */
    public function index(): AnonymousResourceCollection
    {
        return AdminProductGroupResource::collection(ProductGroup::withCount('products')->ordered()->get());
    }

    /** POST /api/admin/product-groups */
    public function store(ProductGroupRequest $request): JsonResponse
    {
        $group = ProductGroup::create([
            'name' => $request->validated('name'),
            'slug' => $this->slug($request->validated('name')),
            'type' => $request->validated('type'),
            'description' => $request->validated('description'),
            'sort_order' => $request->validated('sortOrder', (int) ProductGroup::max('sort_order') + 1),
            'is_active' => $request->validated('isActive', true),
        ]);
        $this->activity->log('product_group.created', $group, ['name' => $group->name]);

        return (new AdminProductGroupResource($group->loadCount('products')))->response()->setStatusCode(201);
    }

    /** PUT /api/admin/product-groups/{id}. The slug (the address in the catalog) is not changed by a rename. */
    public function update(ProductGroupRequest $request, int $id): AdminProductGroupResource
    {
        $group = $this->find($id);

        $changes = [];
        foreach (['name' => 'name', 'type' => 'type', 'description' => 'description', 'sortOrder' => 'sort_order', 'isActive' => 'is_active'] as $input => $column) {
            if ($request->has($input)) {
                $changes[$column] = $request->validated($input);
            }
        }

        $group->fill($changes)->save();
        $this->activity->log('product_group.updated', $group, ['name' => $group->name, 'changes' => $changes]);

        return new AdminProductGroupResource($group->loadCount('products'));
    }

    /** DELETE /api/admin/product-groups/{id}: only an empty group. */
    public function destroy(int $id): JsonResponse
    {
        $group = $this->find($id);

        // Deleted products count too: old orders still point at them.
        $count = $group->products()->withTrashed()->count();
        if ($count > 0) {
            throw new ApiException(422, 'group_in_use', "This group has {$count} ".($count === 1 ? 'product' : 'products').'. Move or delete them first, or hide the group instead.');
        }

        $group->delete();
        $this->activity->log('product_group.deleted', $group, ['name' => $group->name]);

        return response()->json(['message' => 'The group was deleted.']);
    }

    private function find(int $id): ProductGroup
    {
        return ProductGroup::find($id) ?? throw new ApiException(404, 'not_found', 'That group does not exist.');
    }

    /** "Web Hosting" -> "web-hosting", with -2, -3... when taken. */
    private function slug(string $name): string
    {
        $base = Str::slug($name) ?: 'group';
        $slug = $base;

        for ($i = 2; ProductGroup::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
