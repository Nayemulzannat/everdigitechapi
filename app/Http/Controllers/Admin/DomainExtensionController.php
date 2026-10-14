<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DomainExtensionRequest;
use App\Http\Resources\AdminDomainExtensionResource;
use App\Models\DomainExtension;
use App\Services\ActivityLogger;
use App\Services\DomainPricing;
use App\Services\Registrar\Registrar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** The extensions we sell and their prices. domains.view reads, domains.edit adds and changes, domains.delete removes. */
class DomainExtensionController extends Controller
{
    private const COLUMNS = ['extension' => 'extension', 'tag' => 'tag', 'registerPrice' => 'register_price', 'renewPrice' => 'renew_price',
        'transferPrice' => 'transfer_price', 'isFeatured' => 'is_featured', 'isActive' => 'is_active', 'sortOrder' => 'sort_order'];

    public function __construct(private readonly ActivityLogger $activity) {}

    private const PER_PAGE = 50;

    /**
     * GET /api/admin/domain-extensions?search=&status=&page=: switched off ones too, 50 a page.
     * status: all, on, off, no_price (on or off, with no price typed and none worked out), popular (shown in the results).
     */
    public function index(Request $request, DomainPricing $rule): AnonymousResourceCollection
    {
        $data = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:50'],
            'status' => ['sometimes', Rule::in(['all', 'on', 'off', 'no_price', 'popular'])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $search = ltrim(strtolower(trim((string) ($data['search'] ?? ''))), '.');
        $scope = fn () => DomainExtension::query()->when($search !== '', fn ($q) => $q->where('extension', 'like', '%'.addcslashes($search, '%_\\').'%'));
        // "No price" = nothing typed, and the rule cannot work one out either.
        $noPrice = fn ($q) => $q->whereNull('register_price')->when($rule->rule()['usdRate'] !== null, fn ($w) => $w->whereNull('cost_register'));

        $status = $data['status'] ?? 'all';
        $rows = $scope()
            ->when($status === 'on', fn ($q) => $q->where('is_active', true))
            ->when($status === 'off', fn ($q) => $q->where('is_active', false))
            ->when($status === 'popular', fn ($q) => $q->where('is_featured', true))
            ->when($status === 'no_price', $noPrice)
            ->orderByDesc('is_featured')->orderByDesc('is_active')->ordered();

        $counts = [
            'all' => $scope()->count(),
            'on' => $scope()->where('is_active', true)->count(),
            'off' => $scope()->where('is_active', false)->count(),
            'no_price' => $noPrice($scope())->count(),
            'popular' => $scope()->where('is_featured', true)->count(),
        ];

        return AdminDomainExtensionResource::collection($rows->paginate(self::PER_PAGE, page: (int) ($data['page'] ?? 1)))->additional(['meta' => ['counts' => $counts]]);
    }

    /**
     * POST /api/admin/domain-extensions/sync: fills the list from the registrar's price list (Dynadot has about 800
     * extensions). New ones come in switched OFF with no price of their own; for the ones already here only the registrar's
     * cost and notes are updated, never your prices, labels or on/off.
     */
    public function sync(Registrar $registrar): JsonResponse
    {
        $list = $registrar->extensions();

        $existing = DomainExtension::query()->pluck('id', 'extension');
        $order = (int) DomainExtension::max('sort_order');
        $added = 0;
        $updated = 0;

        foreach ($list as $row) {
            $costs = ['cost_register' => $row['cost_register'], 'cost_renew' => $row['cost_renew'], 'cost_transfer' => $row['cost_transfer'], 'usage' => $row['usage'], 'restrictions' => $row['restrictions']];

            if ($existing->has($row['extension'])) {
                DomainExtension::whereKey($existing[$row['extension']])->update($costs);
                $updated++;
            } else {
                DomainExtension::create($costs + ['extension' => $row['extension'], 'is_active' => false, 'sort_order' => ++$order]);
                $added++;
            }
        }
        $this->activity->log('domain_extension.synced', null, ['added' => $added, 'updated' => $updated]);

        return response()->json(['data' => ['added' => $added, 'updated' => $updated, 'total' => DomainExtension::count()]]);
    }

    /**
     * POST /api/admin/domain-extensions/activate-priced: switches ON every extension that has a price (typed or worked out) and
     * no registration restriction (some extensions only accept residents or companies of one country). Answers how many.
     */
    public function activatePriced(DomainPricing $rule): JsonResponse
    {
        $count = DomainExtension::query()->where('is_active', false)->whereNull('restrictions')
            ->where(fn ($q) => $q->whereNotNull('register_price')->orWhere(fn ($w) => $w->whereNotNull('cost_register')->when($rule->rule()['usdRate'] === null, fn ($x) => $x->whereRaw('1 = 0'))))
            ->update(['is_active' => true]);
        $this->activity->log('domain_extension.activated', null, ['count' => $count]);

        return response()->json(['data' => ['activated' => $count]]);
    }

    /** POST /api/admin/domain-extensions: goes to the end of the list unless sortOrder is sent. */
    public function store(DomainExtensionRequest $request): JsonResponse
    {
        $values = $this->values($request);
        $values['sort_order'] ??= (int) DomainExtension::max('sort_order') + 1;
        $extension = DomainExtension::create($values);
        $this->activity->log('domain_extension.created', $extension, ['extension' => $extension->extension]);

        return (new AdminDomainExtensionResource($extension))->response()->setStatusCode(201);
    }

    /** PUT /api/admin/domain-extensions/{id}: only the fields sent change (a price sent as null is cleared). */
    public function update(DomainExtensionRequest $request, int $id): AdminDomainExtensionResource
    {
        $extension = $this->find($id);
        $extension->fill($this->values($request))->save();
        $this->activity->log('domain_extension.updated', $extension, ['extension' => $extension->extension, 'changes' => array_keys($request->validated())]);

        return new AdminDomainExtensionResource($extension);
    }

    /** DELETE /api/admin/domain-extensions/{id} */
    public function destroy(int $id): JsonResponse
    {
        $extension = $this->find($id);
        $extension->delete();
        $this->activity->log('domain_extension.deleted', $extension, ['extension' => $extension->extension]);

        return response()->json(['message' => 'The extension was deleted.']);
    }

    /** @return array<string, mixed> */
    private function values(DomainExtensionRequest $request): array
    {
        $values = [];
        foreach (self::COLUMNS as $input => $column) {
            if ($request->has($input)) {
                $values[$column] = $request->validated($input);
            }
        }

        return $values;
    }

    private function find(int $id): DomainExtension
    {
        return DomainExtension::find($id) ?? throw new ApiException(404, 'not_found', 'That extension does not exist.');
    }
}
