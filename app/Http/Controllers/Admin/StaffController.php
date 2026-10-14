<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StaffIndexRequest;
use App\Http\Requests\Admin\StaffRequest;
use App\Http\Resources\MessageResource;
use App\Http\Resources\StaffResource;
use App\Http\Resources\StaffRoleOptionResource;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Role;
use App\Services\StaffService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class StaffController extends Controller
{
    private const PER_PAGE = 15;

    public function __construct(private readonly StaffService $staff) {}

    /** GET /api/admin/staff */
    public function index(StaffIndexRequest $request): AnonymousResourceCollection
    {
        $search = trim((string) $request->validated('search'));
        // Escape LIKE wildcards so "100%" or "a_b" are searched literally.
        $like = '%'.addcslashes($search, '%_\\').'%';

        // The role filter and the search narrow the tab counts too; the status tab only narrows the rows.
        $scope = fn (): Builder => Admin::query()
            ->when($request->validated('role'), fn ($q, $role) => $q->whereHas('roles', fn ($r) => $r->whereKey($role)))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like)));

        $admins = $scope()
            ->tap(fn (Builder $q) => match ($request->validated('status')) {
                'active' => $q->signedUp(),
                'invited' => $q->invited(),
                'suspended' => $q->where('status', AdminStatus::Suspended->value),
                default => null,
            })
            ->with(['roles', 'latestLogin'])
            ->orderBy('name')->orderBy('id')
            ->paginate(self::PER_PAGE);

        $this->withSuspendedBy($admins->getCollection());

        return StaffResource::collection($admins)->additional(['meta' => [
            'counts' => $this->counts($scope()),
            'stats' => $this->stats(),
        ]]);
    }

    /** GET /api/admin/staff/roles: the roles someone can be given (id, name, description). */
    public function roles(): AnonymousResourceCollection
    {
        return StaffRoleOptionResource::collection(Role::query()->where('guard_name', 'admin')->orderBy('id')->get());
    }

    /** GET /api/admin/staff/{id} */
    public function show(string $id): StaffResource
    {
        return new StaffResource($this->forResponse($this->find($id)));
    }

    /** POST /api/admin/staff: creates the account and emails the "set your password" link. */
    public function store(StaffRequest $request): JsonResponse
    {
        $admin = $this->staff->create($request->user('admin'), $request->validated());

        return (new StaffResource($this->forResponse($this->find($admin->getKey()))))->response()->setStatusCode(201);
    }

    /** PUT /api/admin/staff/{id} */
    public function update(StaffRequest $request, string $id): StaffResource
    {
        $admin = $this->staff->update($request->user('admin'), $this->find($id), $request->validated());

        return new StaffResource($this->forResponse($this->find($admin->getKey())));
    }

    /** DELETE /api/admin/staff/{id}: soft delete. */
    public function destroy(Request $request, string $id): Response
    {
        $this->staff->delete($request->user('admin'), $this->find($id));

        return response()->noContent();
    }

    /** POST /api/admin/staff/{id}/resend-invite */
    public function resendInvite(Request $request, string $id): MessageResource
    {
        $this->staff->resendInvite($request->user('admin'), $this->find($id));

        return new MessageResource('The invite has been sent again.');
    }

    private function find(string|int $id): Admin
    {
        return Admin::query()->with(['roles', 'latestLogin'])->findOrFail($id);
    }

    /** Adds the display-only `suspended_by`. Only for the model that goes into a resource: it is not a column, so never save it. */
    private function forResponse(Admin $admin): Admin
    {
        $this->withSuspendedBy(new Collection([$admin]));

        return $admin;
    }

    /** @return array{all: int, active: int, invited: int, suspended: int} */
    private function counts(Builder $query): array
    {
        $row = $query->toBase()->selectRaw(
            "coalesce(sum(status = 'suspended'), 0) as suspended, "
            ."coalesce(sum(status = 'active' and invited_at is not null), 0) as invited, "
            ."coalesce(sum(status = 'active' and invited_at is null), 0) as active",
        )->first();

        return [
            'all' => (int) ($row->suspended + $row->invited + $row->active),
            'active' => (int) $row->active,
            'invited' => (int) $row->invited,
            'suspended' => (int) $row->suspended,
        ];
    }

    /**
     * Numbers for the stat cards: everyone, whatever the filters. "Two-factor" counts signed-up active staff only
     * (invited people haven't had the chance to set it up, suspended ones can't sign in).
     *
     * @return array<string, int>
     */
    private function stats(): array
    {
        $row = Admin::query()->toBase()->selectRaw(
            'count(*) as total, '
            ."coalesce(sum(status = 'suspended'), 0) as suspended, "
            ."coalesce(sum(status = 'active' and invited_at is not null), 0) as invited, "
            ."coalesce(sum(status = 'active' and invited_at is null), 0) as active, "
            ."coalesce(sum(status = 'active' and invited_at is null and two_factor_secret is not null and two_factor_confirmed_at is not null), 0) as two_factor",
        )->first();

        $superAdminRole = Role::query()->where(['guard_name' => 'admin', 'name' => Admin::SUPER_ADMIN_ROLE])->first();

        return [
            'total' => (int) $row->total,
            'active' => (int) $row->active,
            'invited' => (int) $row->invited,
            'suspended' => (int) $row->suspended,
            'twoFactorOn' => (int) $row->two_factor,
            'twoFactorOf' => (int) $row->active,
            'roles' => Role::query()->where('guard_name', 'admin')->count(),
            // Lets the page disable "Suspend / Remove / Change role" on the last one.
            'activeSuperAdmins' => $superAdminRole?->users()->where('status', AdminStatus::Active->value)->count() ?? 0,
        ];
    }

    /** Sets `suspended_by` (the actor's name from the activity log) on the suspended people in the collection. One query. */
    private function withSuspendedBy(Collection $admins): void
    {
        $suspendedIds = $admins->filter(fn (Admin $a) => $a->status === AdminStatus::Suspended)->modelKeys();
        if ($suspendedIds === []) {
            return;
        }

        $latest = ActivityLog::query()
            ->with('actor')
            ->where('action', 'staff.suspended')
            ->where('subject_type', (new Admin)->getMorphClass())
            ->whereIn('subject_id', $suspendedIds)
            ->orderByDesc('id')
            ->get()
            ->unique('subject_id')
            ->keyBy('subject_id');

        foreach ($admins as $admin) {
            $admin->setAttribute('suspended_by', $latest->get($admin->getKey())?->actor?->name);
        }
    }
}
