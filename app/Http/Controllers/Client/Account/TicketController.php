<?php

namespace App\Http\Controllers\Client\Account;

use App\Enums\TicketStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\ReplyTicketRequest;
use App\Http\Requests\Client\StoreTicketRequest;
use App\Http\Resources\ClientTicketResource;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketDepartment;
use App\Models\TicketReply;
use App\Services\Impersonation;
use App\Services\Tickets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Support tickets of the current account (X-Account-Id). Seeing them needs `tickets.view`; opening, replying and
 * closing need `tickets.create`, and are refused while staff are signed in as the client (see routes/api/client/support.php).
 */
class TicketController extends Controller
{
    private const PER_PAGE = 15;

    public function __construct(private readonly Tickets $tickets) {}

    /** GET /api/client/tickets?status=all|open|answered|closed&page= */
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['all', ...array_column(TicketStatus::cases(), 'value')])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $account = $request->currentAccount();
        $base = fn () => Ticket::query()->where('account_id', $account->getKey());

        $userId = (int) $request->user('client')->getKey();

        $page = $base()
            ->withUnreadFor($userId)
            ->when(($data['status'] ?? 'all') !== 'all', fn ($q) => $q->where('status', $data['status']))
            ->with(['department:id,name', 'opener:id,name'])
            ->orderByDesc('last_reply_at')->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        $counts = $base()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return ClientTicketResource::collection($page)->additional(['meta' => ['counts' => [
            'all' => (int) $counts->sum(),
            'open' => (int) ($counts['open'] ?? 0),
            'answered' => (int) ($counts['answered'] ?? 0),
            'closed' => (int) ($counts['closed'] ?? 0),
            'unread' => $base()->unreadFor($userId)->count(),
        ]]]);
    }

    /** GET /api/client/ticket-departments: what to choose from when opening a ticket. */
    public function departments(): JsonResponse
    {
        return response()->json(['data' => TicketDepartment::active()->get(['id', 'name', 'description'])->map(fn ($d) => [
            'id' => (string) $d->id, 'name' => $d->name, 'description' => $d->description,
        ])->all()]);
    }

    /** POST /api/client/tickets {subject, department_id, priority?, message, attachments[]?} */
    public function store(StoreTicketRequest $request): JsonResponse
    {
        $ticket = $this->tickets->open(
            $request->currentAccount(),
            $request->user('client'),
            $request->validated('subject'),
            (int) $request->validated('department_id'),
            $request->priority(),
            $request->validated('message'),
            $request->file('attachments', []),
        );

        return $this->thread($ticket)->response()->setStatusCode(201);
    }

    /** GET /api/client/tickets/{id}: the thread, without staff notes. */
    public function show(Request $request, int $id, Impersonation $impersonation): ClientTicketResource
    {
        $ticket = $this->find($request, $id);

        // Opening it counts as reading it. Staff looking in as the client must not mark the client's tickets as read.
        if (! $impersonation->isActive($request)) {
            $this->tickets->markRead($ticket, $request->user('client'));
        }

        return $this->thread($ticket);
    }

    /**
     * GET /api/client/tickets/feed?after=<cursor>: asked every few seconds by the client panel to show support replies
     * without a reload. The cursor is the id of the latest support reply the panel has seen. Without `after` it only
     * answers the starting cursor. `unread` is the number of tickets with a reply this person has not opened.
     */
    public function feed(Request $request): JsonResponse
    {
        $after = $request->query('after');
        if ($after !== null && ! ctype_digit((string) $after)) {
            throw ValidationException::withMessages(['after' => 'Send the cursor you were given.']);
        }

        $account = $request->currentAccount();
        $replies = fn () => TicketReply::query()->where('author_type', 'staff')->where('is_internal', false)
            ->whereHas('ticket', fn ($q) => $q->where('account_id', $account->getKey()));

        $latest = (int) $replies()->max('id');
        $events = [];

        if ($after !== null) {
            $new = $replies()->with(['ticket:id,subject,department_id', 'admin:id,name'])->where('id', '>', (int) $after)->orderBy('id')->limit(20)->get();

            $events = $new->map(fn (TicketReply $reply) => [
                'replyId' => (string) $reply->getKey(),
                'ticketId' => (string) $reply->ticket_id,
                'number' => $reply->ticket->number,
                'subject' => $reply->ticket->subject,
                // First name only, like in the thread.
                'staffName' => strtok((string) $reply->admin?->name, ' ') ?: 'Support',
                'createdAt' => $reply->created_at->toIso8601ZuluString(),
            ])->values()->all();

            $latest = $new->count() === 20 ? (int) $new->last()->getKey() : max($latest, (int) $after);
        }

        return response()->json(['data' => [
            'cursor' => $latest,
            'unread' => Ticket::where('account_id', $account->getKey())->unreadFor((int) $request->user('client')->getKey())->count(),
            'events' => $events,
        ]]);
    }

    /** POST /api/client/tickets/{id}/replies {message, attachments[]?}: reopens the ticket. */
    public function reply(ReplyTicketRequest $request, int $id): ClientTicketResource
    {
        $ticket = $this->find($request, $id);
        $this->tickets->clientReply($ticket, $request->user('client'), $request->validated('message'), $request->file('attachments', []));

        return $this->thread($ticket);
    }

    /** POST /api/client/tickets/{id}/close */
    public function close(Request $request, int $id): ClientTicketResource
    {
        $ticket = $this->tickets->close($this->find($request, $id), $request->user('client'));

        return $this->thread($ticket);
    }

    /** GET /api/client/tickets/{id}/attachments/{attachment}: a file of the thread (never of a staff note). */
    public function download(Request $request, int $id, int $attachment): StreamedResponse
    {
        $ticket = $this->find($request, $id);
        $file = TicketAttachment::whereKey($attachment)
            ->whereHas('reply', fn ($q) => $q->where('ticket_id', $ticket->getKey())->where('is_internal', false))
            ->first();

        if (! $file || ! Tickets::disk()->exists($file->path)) {
            throw new ApiException(404, 'not_found', 'That file does not exist.');
        }

        return self::send($file);
    }

    /** One file as a download, never shown inside the page: a ticket file is untrusted. */
    public static function send(TicketAttachment $file): StreamedResponse
    {
        return Tickets::disk()->download($file->path, $file->original_name, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** The ticket must belong to the current account. Another account's ticket is "not found", not "forbidden". */
    private function find(Request $request, int $id): Ticket
    {
        $ticket = Ticket::where('account_id', $request->currentAccount()->getKey())->find($id);

        if (! $ticket) {
            throw new ApiException(404, 'not_found', 'That ticket does not exist.');
        }

        return $ticket;
    }

    private function thread(Ticket $ticket): ClientTicketResource
    {
        return new ClientTicketResource($ticket->fresh()->load([
            'department:id,name',
            'opener:id,name',
            'replies' => fn ($q) => $q->where('is_internal', false),
            'replies.admin:id,name',
            'replies.clientUser:id,name',
            'replies.attachments',
        ]));
    }
}
