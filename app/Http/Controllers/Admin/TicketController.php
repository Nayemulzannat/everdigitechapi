<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TicketStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Client\Account\TicketController as ClientTicketController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StaffReplyRequest;
use App\Http\Requests\Admin\TicketIndexRequest;
use App\Http\Requests\Admin\UpdateTicketRequest;
use App\Http\Resources\AdminTicketResource;
use App\Models\Admin;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketDepartment;
use App\Models\TicketReply;
use App\Services\Tickets;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Support tickets for staff. Reading needs tickets.view, replying and changing a ticket tickets.edit, assigning
 * tickets.assign (see routes/api/admin/tickets.php).
 */
class TicketController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(private readonly Tickets $tickets) {}

    /** GET /api/admin/tickets */
    public function index(TicketIndexRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        // Every filter but the status narrows the tab counts too; the status only narrows the rows.
        $scope = fn (): Builder => Ticket::query()
            ->when(($filters['priority'] ?? 'all') !== 'all', fn ($q) => $q->where('priority', $filters['priority']))
            ->when(! empty($filters['department']), fn ($q) => $q->where('department_id', $filters['department']))
            ->tap(fn ($q) => $this->assigned($q, $filters['assigned'] ?? 'all', $request->user('admin')))
            ->search($filters['search'] ?? null);

        $page = $scope()
            ->when(($filters['status'] ?? 'all') !== 'all', fn ($q) => $q->where('status', $filters['status']))
            ->with(['department:id,name', 'account:id,name', 'opener:id,name,email', 'assignee:id,name'])
            ->orderBy('last_reply_at', ($filters['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc')->orderBy('id', ($filters['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc')
            ->paginate(self::PER_PAGE);

        $counts = $scope()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return AdminTicketResource::collection($page)->additional(['meta' => ['counts' => [
            'all' => (int) $counts->sum(),
            'open' => (int) ($counts['open'] ?? 0),
            'answered' => (int) ($counts['answered'] ?? 0),
            'closed' => (int) ($counts['closed'] ?? 0),
        ]]]);
    }

    /**
     * GET /api/admin/tickets/feed?after=<cursor>: what the admin panel asks every few seconds to show new tickets and
     * client replies without a reload. The cursor is the id of the latest client message the panel has seen. Without
     * `after` it only answers the current cursor (the panel's starting point) and no events.
     */
    public function feed(Request $request): JsonResponse
    {
        $after = $request->query('after');
        if ($after !== null && ! ctype_digit((string) $after)) {
            throw ValidationException::withMessages(['after' => 'Send the cursor you were given.']);
        }

        $latest = (int) TicketReply::where('author_type', 'client')->max('id');
        $events = [];

        if ($after !== null) {
            $replies = TicketReply::with(['ticket' => fn ($q) => $q->with('account:id,name'), 'clientUser:id,name'])
                ->where('author_type', 'client')->where('id', '>', (int) $after)
                ->orderBy('id')->limit(20)->get();

            $firstReplyIds = TicketReply::whereIn('ticket_id', $replies->pluck('ticket_id'))->selectRaw('min(id) as first_id')->groupBy('ticket_id')->pluck('first_id')->all();

            $events = $replies->filter(fn ($reply) => $reply->ticket)->map(fn (TicketReply $reply) => [
                'replyId' => (string) $reply->getKey(),
                'ticketId' => (string) $reply->ticket_id,
                'number' => $reply->ticket->number,
                'subject' => $reply->ticket->subject,
                'kind' => in_array($reply->getKey(), $firstReplyIds, true) ? 'new_ticket' : 'client_reply',
                'priority' => $reply->ticket->priority->value,
                'accountName' => $reply->ticket->account?->name,
                'authorName' => $reply->clientUser?->name,
                'createdAt' => $reply->created_at->toIso8601ZuluString(),
            ])->values()->all();

            // More than 20 waiting: the next poll continues from the last one shown, so none is skipped.
            $latest = $replies->count() === 20 ? (int) $replies->last()->getKey() : max($latest, (int) $after);
        }

        return response()->json(['data' => [
            'cursor' => $latest,
            'waiting' => Ticket::where('status', TicketStatus::Open->value)->count(),
            'events' => $events,
        ]]);
    }

    /** GET /api/admin/tickets/options: the departments and the staff a ticket can be assigned to. */
    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'departments' => TicketDepartment::orderBy('sort_order')->orderBy('id')->get(['id', 'name'])->map(fn ($d) => ['id' => (string) $d->id, 'name' => $d->name])->all(),
            'assignees' => Admin::signedUp()->orderBy('name')->get(['id', 'name'])->map(fn ($a) => ['id' => (string) $a->id, 'name' => $a->name])->all(),
        ]]);
    }

    /** GET /api/admin/tickets/{id}: the whole thread, staff notes included. */
    public function show(int $id): AdminTicketResource
    {
        return $this->thread($this->find($id));
    }

    /** POST /api/admin/tickets/{id}/replies {message, internal?, attachments[]?} (multipart when there are files) */
    public function reply(StaffReplyRequest $request, int $id): AdminTicketResource
    {
        $ticket = $this->find($id);
        $this->tickets->staffReply($ticket, $request->user('admin'), $request->validated('message'), (bool) $request->validated('internal', false), $request->file('attachments', []));

        return $this->thread($ticket);
    }

    /** PUT /api/admin/tickets/{id} {status?, priority?, departmentId?} */
    public function update(UpdateTicketRequest $request, int $id): AdminTicketResource
    {
        $ticket = $this->tickets->update($this->find($id), $request->user('admin'), $request->changes());

        return $this->thread($ticket);
    }

    /** POST /api/admin/tickets/{id}/assign {adminId: id | null} */
    public function assign(Request $request, int $id): AdminTicketResource
    {
        $data = $request->validate(['adminId' => ['present', 'nullable', 'integer']]);
        $assignee = null;

        if ($data['adminId'] !== null) {
            $assignee = Admin::signedUp()->find($data['adminId']);
            if (! $assignee) {
                throw ValidationException::withMessages(['adminId' => 'Choose an active staff member.']);
            }
        }

        return $this->thread($this->tickets->assign($this->find($id), $request->user('admin'), $assignee));
    }

    /** GET /api/admin/tickets/{id}/attachments/{attachment} */
    public function download(int $id, int $attachment): StreamedResponse
    {
        $ticket = $this->find($id);
        $file = TicketAttachment::whereKey($attachment)->whereHas('reply', fn ($q) => $q->where('ticket_id', $ticket->getKey()))->first();

        if (! $file || ! Tickets::disk()->exists($file->path)) {
            throw new ApiException(404, 'not_found', 'That file does not exist.');
        }

        return ClientTicketController::send($file);
    }

    private function assigned(Builder $query, string $assigned, Admin $me): void
    {
        match (true) {
            $assigned === 'me' => $query->where('assigned_to', $me->getKey()),
            $assigned === 'unassigned' => $query->whereNull('assigned_to'),
            ctype_digit($assigned) => $query->where('assigned_to', (int) $assigned),
            default => null,
        };
    }

    private function find(int $id): Ticket
    {
        return Ticket::find($id) ?? throw new ApiException(404, 'not_found', 'That ticket does not exist.');
    }

    private function thread(Ticket $ticket): AdminTicketResource
    {
        return new AdminTicketResource($ticket->fresh()->load([
            'department:id,name', 'account:id,name', 'opener:id,name,email', 'assignee:id,name',
            'replies.admin:id,name', 'replies.clientUser:id,name', 'replies.attachments',
        ]));
    }
}
