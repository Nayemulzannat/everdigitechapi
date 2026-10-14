<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TicketDepartmentRequest;
use App\Http\Resources\AdminTicketDepartmentResource;
use App\Models\TicketDepartment;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * The departments a client can pick when opening a ticket. Reading needs tickets.view; adding, editing, switching on
 * and off and ordering need tickets.edit; deleting needs tickets.delete (see routes/api/admin/tickets.php).
 */
class TicketDepartmentController extends Controller
{
    public function __construct(private readonly ActivityLogger $activity) {}

    /** GET /api/admin/ticket-departments: all of them, switched off ones too, in the order clients see them. */
    public function index(): AnonymousResourceCollection
    {
        return AdminTicketDepartmentResource::collection(
            TicketDepartment::withCount('tickets')->orderBy('sort_order')->orderBy('id')->get(),
        );
    }

    /** POST /api/admin/ticket-departments {name, description?, isActive?}: goes to the end of the list. */
    public function store(TicketDepartmentRequest $request): JsonResponse
    {
        $department = TicketDepartment::create([
            'name' => $request->validated('name'),
            'description' => $request->validated('description'),
            'is_active' => $request->validated('isActive', true),
            'sort_order' => (int) TicketDepartment::max('sort_order') + 1,
        ]);
        $this->activity->log('ticket_department.created', $department, ['name' => $department->name]);

        return (new AdminTicketDepartmentResource($department->loadCount('tickets')))->response()->setStatusCode(201);
    }

    /** PUT /api/admin/ticket-departments/{id} {name?, description?, isActive?} */
    public function update(TicketDepartmentRequest $request, int $id): AdminTicketDepartmentResource
    {
        $department = $this->find($id);
        $changes = [];

        foreach (['name' => 'name', 'description' => 'description', 'isActive' => 'is_active'] as $input => $column) {
            if ($request->has($input)) {
                $changes[$column] = $request->validated($input);
            }
        }

        if (($changes['is_active'] ?? true) === false && $department->is_active) {
            $this->ensureAnotherActive($department);
        }

        $department->fill($changes)->save();
        $this->activity->log('ticket_department.updated', $department, ['name' => $department->name, 'changes' => $changes]);

        return new AdminTicketDepartmentResource($department->loadCount('tickets'));
    }

    /** DELETE /api/admin/ticket-departments/{id}: only a department no ticket uses. Otherwise switch it off. */
    public function destroy(int $id): JsonResponse
    {
        $department = $this->find($id);

        $tickets = $department->tickets()->count();
        if ($tickets > 0) {
            throw new ApiException(422, 'department_in_use', "{$tickets} ".($tickets === 1 ? 'ticket uses' : 'tickets use').' this department. Switch it off instead, so clients cannot choose it.');
        }
        if ($department->is_active) {
            $this->ensureAnotherActive($department);
        }

        $department->delete();
        $this->activity->log('ticket_department.deleted', $department, ['name' => $department->name]);

        return response()->json(['message' => 'The department was deleted.']);
    }

    /** POST /api/admin/ticket-departments/reorder {ids: [every department id, in the new order]} */
    public function reorder(Request $request): AnonymousResourceCollection
    {
        $existing = TicketDepartment::pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $data = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer', 'distinct']]);
        $ids = array_map('intval', $data['ids']);

        if (collect($ids)->sort()->values()->all() !== $existing) {
            throw new ApiException(422, 'validation_failed', 'Send every department exactly once.', [], []);
        }

        DB::transaction(function () use ($ids) {
            foreach ($ids as $position => $id) {
                TicketDepartment::whereKey($id)->update(['sort_order' => $position + 1]);
            }
        });
        $this->activity->log('ticket_department.reordered', null, ['ids' => $ids]);

        return $this->index();
    }

    /** Clients must always have a department to choose. */
    private function ensureAnotherActive(TicketDepartment $department): void
    {
        if (! TicketDepartment::where('is_active', true)->whereKeyNot($department->getKey())->exists()) {
            throw new ApiException(422, 'last_active_department', 'Keep at least one department switched on, or clients cannot open tickets.');
        }
    }

    private function find(int $id): TicketDepartment
    {
        return TicketDepartment::withCount('tickets')->find($id) ?? throw new ApiException(404, 'not_found', 'That department does not exist.');
    }
}
