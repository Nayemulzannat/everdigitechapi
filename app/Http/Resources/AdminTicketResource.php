<?php

namespace App\Http\Resources;

use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A ticket for the admin panel (camelCase, like the other admin resources). A list row, or the whole thread, internal
 * notes included, when `replies` is loaded.
 *
 * @property Ticket $resource
 */
class AdminTicketResource extends JsonResource
{
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
            'client' => [
                'accountId' => (string) $ticket->account_id,
                'accountName' => $ticket->account?->name,
                'openedBy' => $ticket->opener ? ['name' => $ticket->opener->name, 'email' => $ticket->opener->email] : null,
            ],
            'assignedTo' => $ticket->assignee ? ['id' => (string) $ticket->assignee->getKey(), 'name' => $ticket->assignee->name] : null,
            'lastReplyBy' => $ticket->last_reply_by,
            'lastReplyAt' => $ticket->last_reply_at?->toIso8601ZuluString(),
            'createdAt' => $ticket->created_at->toIso8601ZuluString(),
            'closedAt' => $ticket->closed_at?->toIso8601ZuluString(),
            $this->mergeWhen($ticket->relationLoaded('replies'), fn () => [
                'replies' => $ticket->replies->map(fn (TicketReply $reply) => [
                    'id' => (string) $reply->getKey(),
                    'authorType' => $reply->author_type,
                    'authorName' => $reply->author_type === 'staff' ? ($reply->admin?->name ?? 'Support') : ($reply->clientUser?->name ?? 'Client'),
                    'body' => $reply->body,
                    'isInternal' => $reply->is_internal,
                    'createdAt' => $reply->created_at->toIso8601ZuluString(),
                    'attachments' => $reply->attachments->map(fn ($a) => ['id' => (string) $a->getKey(), 'name' => $a->original_name, 'size' => $a->size, 'mime' => $a->mime])->all(),
                ])->all(),
            ]),
        ];
    }
}
