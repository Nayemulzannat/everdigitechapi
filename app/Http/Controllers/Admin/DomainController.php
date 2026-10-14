<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DomainStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DomainRequest;
use App\Http\Resources\AdminDomainResource;
use App\Models\Domain;
use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Domains of the clients. domains.view reads, domains.create adds one by hand, domains.edit changes, domains.delete removes. */
class DomainController extends Controller
{
    private const PER_PAGE = 15;

    private const COLUMNS = ['accountId' => 'account_id', 'name' => 'name', 'registrar' => 'registrar', 'status' => 'status', 'registeredAt' => 'registered_at',
        'expiresAt' => 'expires_at', 'autoRenew' => 'auto_renew', 'locked' => 'locked', 'whoisPrivacy' => 'whois_privacy', 'nameservers' => 'nameservers', 'notes' => 'notes'];

    public function __construct(private readonly ActivityLogger $activity) {}

    /** GET /api/admin/domains?status=&search=&page= (status: all, active, pending, expired, cancelled, expiring = active and ends within 30 days) */
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['all', 'expiring', ...array_column(DomainStatus::cases(), 'value')])],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $search = trim((string) ($data['search'] ?? ''));
        $scope = fn (): Builder => Domain::query()->when($search !== '', function (Builder $q) use ($search) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $q->where(fn (Builder $w) => $w->where('name', 'like', $like)->orWhereHas('account', fn (Builder $a) => $a->where('name', 'like', $like)));
        });
        $expiring = fn (Builder $q) => $q->where('status', DomainStatus::Active->value)->whereBetween('expires_at', [now()->toDateString(), now()->addDays(30)->toDateString()]);

        $status = $data['status'] ?? 'all';
        $rows = $scope()->with('account:id,name')
            ->when($status === 'expiring', $expiring)
            ->when(! in_array($status, ['all', 'expiring'], true), fn (Builder $q) => $q->where('status', $status))
            ->orderByRaw('expires_at is null')->orderBy('expires_at')->orderBy('id');

        $counts = ['all' => $scope()->count(), 'expiring' => $expiring($scope())->count()];
        foreach (DomainStatus::cases() as $case) {
            $counts[$case->value] = $scope()->where('status', $case->value)->count();
        }

        $page = $rows->paginate(self::PER_PAGE, page: (int) ($data['page'] ?? 1));

        return AdminDomainResource::collection($page)->additional(['meta' => ['counts' => $counts]]);
    }

    /** GET /api/admin/domains/{id}: with its history. */
    public function show(int $id): AdminDomainResource
    {
        return new AdminDomainResource($this->find($id)->load('events.admin'));
    }

    /** POST /api/admin/domains: a domain registered somewhere else (or by hand) that an account owns. */
    public function store(DomainRequest $request): JsonResponse
    {
        $values = $this->values($request) + ['created_by_admin_id' => $request->user('admin')->getKey()];
        $domain = Domain::create($values);
        $domain->events()->create(['type' => 'created', 'admin_id' => $request->user('admin')->getKey(), 'properties' => ['status' => $domain->status->value]]);
        $this->activity->log('domain.created', $domain, ['name' => $domain->name, 'account_id' => $domain->account_id]);

        return (new AdminDomainResource($this->find($domain->getKey())->load('events.admin')))->response()->setStatusCode(201);
    }

    /** PUT /api/admin/domains/{id}: only the fields sent change. */
    public function update(DomainRequest $request, int $id): AdminDomainResource
    {
        $domain = $this->find($id);
        $changes = $this->values($request);
        $domain->fill($changes)->save();

        if ($changes !== []) {
            $domain->events()->create(['type' => 'updated', 'admin_id' => $request->user('admin')->getKey(), 'properties' => ['changed' => array_keys($changes)]]);
            $this->activity->log('domain.updated', $domain, ['name' => $domain->name, 'changes' => array_keys($changes)]);
        }

        return new AdminDomainResource($this->find($id)->load('events.admin'));
    }

    /** DELETE /api/admin/domains/{id}: removes it from the list (it is not cancelled at the registrar). */
    public function destroy(int $id): JsonResponse
    {
        $domain = $this->find($id);
        $domain->delete();
        $this->activity->log('domain.deleted', $domain, ['name' => $domain->name]);

        return response()->json(['message' => 'The domain was removed from the list.']);
    }

    /** @return array<string, mixed> */
    private function values(DomainRequest $request): array
    {
        $values = [];
        foreach (self::COLUMNS as $input => $column) {
            if ($request->has($input)) {
                $values[$column] = $request->validated($input);
            }
        }

        return $values;
    }

    private function find(int $id): Domain
    {
        return Domain::with('account:id,name')->find($id) ?? throw new ApiException(404, 'not_found', 'That domain does not exist.');
    }
}
