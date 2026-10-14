<?php

namespace App\Http\Resources;

use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A ticket for the client panel (snake_case). A list row, or the whole thread when `replies` is loaded.
 * Internal notes between staff are never part of it: load only the replies that are not internal.
 *
 * @property Ticket $resource
 */
class ClientTicketResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $ticket = $this->resource;

        return [
            'id' => (string) $ticket->getKey(),
            'number' => $ticket->number,
            'subject' => $ticket->subject,
            'status' => $ticket->status->value,
            'priority' => $ticket->priority->value,
            'department' => ['id' => (string) $ticket->department_id, 'name' => $ticket->department?->name],
            'opened_by' => $ticket->opener?->name,
            'last_reply_by' => $ticket->last_reply_by,
            // Support replied and this person has not opened the ticket since (only set in the list).
            'unread' => (bool) ($ticket->unread ?? false),
            'last_reply_at' => $ticket->last_reply_at?->toIso8601ZuluString(),
            'created_at' => $ticket->created_at->toIso8601ZuluString(),
            'closed_at' => $ticket->closed_at?->toIso8601ZuluString(),
            $this->mergeWhen($ticket->relationLoaded('replies'), fn () => [
                'replies' => $ticket->replies->map(fn (TicketReply $reply) => [
                    'id' => (string) $reply->getKey(),
                    'author_type' => $reply->author_type,
                    // Staff are shown by first name only; the client does not need a staff member's full name or email.
                    'author_name' => $reply->author_type === 'staff' ? strtok((string) $reply->admin?->name, ' ') ?: 'Support' : $reply->clientUser?->name,
                    'body' => $reply->body,
                    'created_at' => $reply->created_at->toIso8601ZuluString(),
                    'attachments' => $reply->attachments->map(fn ($a) => ['id' => (string) $a->getKey(), 'name' => $a->original_name, 'size' => $a->size, 'mime' => $a->mime])->all(),
                ])->all(),
            ]),
        ];
    }
}
