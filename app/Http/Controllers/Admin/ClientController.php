<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ClientStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ClientIndexRequest;
use App\Http\Requests\Admin\ClientUpdateRequest;
use App\Http\Resources\AdminClientDetailResource;
use App\Http\Resources\ClientResource;
use App\Models\ClientAccount;
use App\Services\ActivityLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ClientController extends Controller
{
    private const PER_PAGE = 15;

    /** Sort key → column. The tmp_* columns become real aggregates when services, domains and invoices exist. */
    private const SORT_COLUMNS = [
        'name' => 'name',
        'joined' => 'created_at',
        'balance' => 'tmp_balance_due',
        'services' => 'tmp_services_count',
        'domains' => 'tmp_domains_count',
    ];

    /** GET /api/admin/clients */
    public function index(ClientIndexRequest $request): AnonymousResourceCollection
    {
        // Group and search narrow the tab counts too; the status filter only narrows the rows.
        $scope = fn (): Builder => ClientAccount::query()->ofGroup($request->group())->search($request->validated('search'));

        $rows = $scope()->ofStatus($request->status())
            ->with('owner:id,email') // one query for every owner on the page
            ->tap(fn (Builder $q) => $this->sort($q, $request->validated('sort', '-joined')));

        $page = $this->paginate($rows, (int) $request->validated('page', 1));

        return ClientResource::collection($page)->additional(['meta' => ['counts' => $this->counts($scope())]]);
    }

    /** Asks for a page past the end and gets the last one, like the frontend expects. */
    private function paginate(Builder $query, int $page): LengthAwarePaginator
    {
        $result = $query->paginate(self::PER_PAGE, page: $page);

        return $result->currentPage() > $result->lastPage() && $result->lastPage() > 0
            ? $query->paginate(self::PER_PAGE, page: $result->lastPage())
            : $result;
    }

    private function sort(Builder $query, string $sort): void
    {
        $column = self::SORT_COLUMNS[ltrim($sort, '-')];

        // `id` breaks ties so pages never repeat or skip a row.
        $query->orderBy($column, str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderBy('id', str_starts_with($sort, '-') ? 'desc' : 'asc');
    }

    /** GET /api/admin/clients/{id}: one client with its team, tickets, domains and recent sign-ins. */
    public function show(int $id): AdminClientDetailResource
    {
        return new AdminClientDetailResource($this->find($id));
    }

    /** PUT /api/admin/clients/{id} {name?, company?, group?, status?, notes?}: only the fields sent change. */
    public function update(ClientUpdateRequest $request, int $id): AdminClientDetailResource
    {
        $account = $this->find($id);
        $map = ['name' => 'name', 'company' => 'company', 'group' => 'client_group', 'status' => 'status', 'notes' => 'notes'];

        $changes = [];
        foreach ($map as $input => $column) {
            if ($request->has($input)) {
                $changes[$column] = $request->validated($input);
            }
        }
        $account->fill($changes)->save();

        if ($changes !== []) {
            app(ActivityLogger::class)->log('client.updated', $account, ['name' => $account->name, 'changes' => array_keys($changes)]);
        }

        return new AdminClientDetailResource($account->refresh());
    }

    private function find(int $id): ClientAccount
    {
        return ClientAccount::query()->with(['owner', 'members.user'])->find($id) ?? throw new ApiException(404, 'not_found', 'That client does not exist.');
    }

    /** @return array{all: int, active: int, inactive: int, closed: int} */
    private function counts(Builder $query): array
    {
        $byStatus = $query->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $counts = ['all' => 0];
        foreach (ClientStatus::cases() as $status) {
            $counts[$status->value] = (int) ($byStatus[$status->value] ?? 0);
            $counts['all'] += $counts[$status->value];
        }

        return $counts;
    }
}
