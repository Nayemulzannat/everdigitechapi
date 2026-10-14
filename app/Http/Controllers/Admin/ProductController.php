<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BillingCycle;
use App\Enums\ProductType;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProductRequest;
use App\Http\Resources\AdminProductGroupResource;
use App\Http\Resources\AdminProductResource;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\ActivityLogger;
use App\Services\Pricing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Products and their prices. products.view reads, products.create adds, products.edit changes, products.delete removes. */
class ProductController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(private readonly ActivityLogger $activity, private readonly Pricing $pricing) {}

    /** GET /api/admin/products?group=<id>&status=all|active|inactive&search=&page= */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'group' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', Rule::in(['all', 'active', 'inactive'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        // The group and the search narrow the tab counts too; the status only narrows the rows.
        $scope = fn (): Builder => Product::query()
            ->when(! empty($filters['group']), fn ($q) => $q->where('product_group_id', $filters['group']))
            ->search($filters['search'] ?? null);

        $page = $scope()
            ->when(($filters['status'] ?? 'all') !== 'all', fn ($q) => $q->where('is_active', $filters['status'] === 'active'))
            ->with(['group:id,name', 'prices'])
            ->orderBy('product_group_id')->ordered()
            ->paginate(self::PER_PAGE);

        $counts = $scope()->selectRaw('is_active, count(*) as total')->groupBy('is_active')->pluck('total', 'is_active');

        return AdminProductResource::collection($page)->additional(['meta' => ['counts' => [
            'all' => (int) $counts->sum(),
            'active' => (int) ($counts[1] ?? 0),
            'inactive' => (int) ($counts[0] ?? 0),
        ]]]);
    }

    /** GET /api/admin/products/options: what the product form needs (groups, billing cycles, tax). */
    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'groups' => AdminProductGroupResource::collection(ProductGroup::withCount('products')->ordered()->get())->resolve(),
            'cycles' => array_map(fn (BillingCycle $c) => ['value' => $c->value, 'label' => $c->label(), 'months' => $c->months()], BillingCycle::cases()),
            'types' => array_map(fn (ProductType $t) => ['value' => $t->value, 'label' => $t->label()], ProductType::cases()),
            'currency' => (string) config('everdigitech.currency'),
            'tax' => $this->pricing->tax(),
        ]]);
    }

    /** GET /api/admin/products/{id} */
    public function show(int $id): AdminProductResource
    {
        return new AdminProductResource($this->find($id));
    }

    /** POST /api/admin/products */
    public function store(ProductRequest $request): JsonResponse
    {
        $product = DB::transaction(function () use ($request) {
            $product = Product::create([
                'product_group_id' => $request->validated('groupId'),
                'name' => $request->validated('name'),
                'slug' => $this->slug($request->validated('name')),
                'tagline' => $request->validated('tagline'),
                'description' => $request->validated('description'),
                'features' => $request->validated('features', []),
                'feature_groups' => $request->validated('featureGroups', []),
                'setup_fee' => $request->validated('setupFee', 0),
                'is_active' => $request->validated('isActive', true),
                'is_featured' => $request->validated('isFeatured', false),
                'sort_order' => $request->validated('sortOrder', (int) Product::withTrashed()->max('sort_order') + 1),
            ]);
            $this->syncPrices($product, $request->validated('prices', []));

            return $product;
        });
        $this->activity->log('product.created', $product, ['name' => $product->name]);

        return (new AdminProductResource($this->find($product->getKey())))->response()->setStatusCode(201);
    }

    /** PUT /api/admin/products/{id}. `prices`, when sent, replaces the prices. */
    public function update(ProductRequest $request, int $id): AdminProductResource
    {
        $product = $this->find($id);

        $changes = [];
        foreach (['groupId' => 'product_group_id', 'name' => 'name', 'tagline' => 'tagline', 'description' => 'description', 'features' => 'features', 'featureGroups' => 'feature_groups',
            'setupFee' => 'setup_fee', 'isActive' => 'is_active', 'isFeatured' => 'is_featured', 'sortOrder' => 'sort_order'] as $input => $column) {
            if ($request->has($input)) {
                $changes[$column] = $request->validated($input);
            }
        }

        DB::transaction(function () use ($product, $changes, $request) {
            $product->fill($changes)->save();
            if ($request->has('prices')) {
                $this->syncPrices($product, $request->validated('prices'));
            }
        });
        $this->activity->log('product.updated', $product, ['name' => $product->name, 'changes' => array_merge(array_keys($changes), $request->has('prices') ? ['prices'] : [])]);

        return new AdminProductResource($this->find($id));
    }

    /** DELETE /api/admin/products/{id}: kept in the database (old orders point at it), gone from the catalog. */
    public function destroy(int $id): JsonResponse
    {
        $product = $this->find($id);
        $product->delete();
        $this->activity->log('product.deleted', $product, ['name' => $product->name]);

        return response()->json(['message' => 'The product was deleted.']);
    }

    /** Makes the product's prices exactly the ones given. @param  list<array{cycle: string, amount: int}>  $prices */
    private function syncPrices(Product $product, array $prices): void
    {
        $product->prices()->whereNotIn('cycle', array_column($prices, 'cycle'))->delete();

        foreach ($prices as $price) {
            $product->prices()->updateOrCreate(['cycle' => $price['cycle']], ['amount' => $price['amount'], 'compare_amount' => $price['compareAmount'] ?? null]);
        }
    }

    private function find(int $id): Product
    {
        return Product::with(['group:id,name', 'prices'])->find($id) ?? throw new ApiException(404, 'not_found', 'That product does not exist.');
    }

    /** "Turbo Hosting Business" -> "turbo-hosting-business", with -2, -3... when taken (deleted products keep theirs). */
    private function slug(string $name): string
    {
        $base = Str::slug($name) ?: 'product';
        $slug = $base;

        for ($i = 2; Product::withTrashed()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
