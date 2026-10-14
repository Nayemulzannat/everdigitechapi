<?php

namespace App\Services;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Admin;
use App\Models\ClientAccount;
use App\Models\ClientUser;
use App\Models\Ticket;
use App\Models\TicketClientRead;
use App\Models\TicketReply;
use App\Notifications\ClientTicketReplyNotification;
use App\Notifications\StaffTicketAlertNotification;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Support tickets. A ticket is a thread: the first message is the first reply.
 * Status: open (waiting for support) -> answered (support replied) -> closed. A client reply reopens it.
 */
class Tickets
{
    /** Where attachments live. Private: they are served only through the API, after a permission check. */
    public const DISK = 'local';

    public const MAX_FILES = 3;

    public const MAX_FILE_KB = 5120;

    public const FILE_TYPES = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'txt', 'log', 'zip'];

    public function __construct(private readonly ActivityLogger $activity) {}

    /** @param  array<int, UploadedFile>  $files */
    public function open(ClientAccount $account, ClientUser $user, string $subject, int $departmentId, TicketPriority $priority, string $message, array $files = []): Ticket
    {
        $ticket = DB::transaction(function () use ($account, $user, $subject, $departmentId, $priority, $message, $files) {
            $ticket = Ticket::create([
                'account_id' => $account->getKey(),
                'opened_by' => $user->getKey(),
                'department_id' => $departmentId,
                'subject' => $subject,
                'status' => TicketStatus::Open,
                'priority' => $priority,
                'last_reply_by' => 'client',
                'last_reply_at' => now(),
            ]);

            $this->addReply($ticket, ['author_type' => 'client', 'client_user_id' => $user->getKey(), 'body' => $message], $files);

            return $ticket;
        });

        $this->activity->log('ticket.opened', $ticket, ['number' => $ticket->number, 'account_id' => $account->getKey()], $user);
        $this->alertSupport('new_ticket', $ticket, $user, $message);

        return $ticket;
    }

    /** A client reply. It reopens an answered or closed ticket. @param  array<int, UploadedFile>  $files */
    public function clientReply(Ticket $ticket, ClientUser $user, string $message, array $files = []): TicketReply
    {
        $reply = DB::transaction(function () use ($ticket, $user, $message, $files) {
            $reply = $this->addReply($ticket, ['author_type' => 'client', 'client_user_id' => $user->getKey(), 'body' => $message], $files);

            $ticket->forceFill(['status' => TicketStatus::Open, 'last_reply_by' => 'client', 'last_reply_at' => now(), 'closed_at' => null])->save();

            return $reply;
        });

        $this->activity->log('ticket.client_replied', $ticket, ['number' => $ticket->number], $user);
        $this->alertSupport('client_reply', $ticket, $user, $message);

        return $reply;
    }

    /** A staff reply (the client is emailed and the ticket becomes answered), or an internal note (nobody is told). @param  array<int, UploadedFile>  $files */
    public function staffReply(Ticket $ticket, Admin $admin, string $message, bool $internal = false, array $files = []): TicketReply
    {
        $reply = DB::transaction(function () use ($ticket, $admin, $message, $internal, $files) {
            $reply = $this->addReply($ticket, ['author_type' => 'staff', 'admin_id' => $admin->getKey(), 'body' => $message, 'is_internal' => $internal], $files);

            if (! $internal) {
                $ticket->forceFill(['status' => TicketStatus::Answered, 'last_reply_by' => 'staff', 'last_reply_at' => now(), 'closed_at' => null])->save();
            }

            return $reply;
        });

        if (! $internal) {
            $ticket->opener?->notify(new ClientTicketReplyNotification($ticket->number, $ticket->subject, Str::limit($message, 400), config('everdigitech.client_url').'/dashboard/support/'.$ticket->getKey(), $admin->name));
        }

        $this->activity->log($internal ? 'ticket.note_added' : 'ticket.staff_replied', $ticket, ['number' => $ticket->number], $admin);

        return $reply;
    }

    /** The client opened the ticket: every support reply up to now counts as read for them. */
    public function markRead(Ticket $ticket, ClientUser $user): void
    {
        $latest = (int) $ticket->replies()->where('is_internal', false)->max('id');

        TicketClientRead::updateOrCreate(
            ['ticket_id' => $ticket->getKey(), 'client_user_id' => $user->getKey()],
            ['read_reply_id' => $latest],
        );
    }

    /** Emails the support mailbox (queued, so a slow mail server never slows the client down). */
    private function alertSupport(string $kind, Ticket $ticket, ClientUser $user, string $message): void
    {
        $ticket->loadMissing(['account:id,name', 'department:id,name']);

        Notification::route('mail', [config('everdigitech.support.email') => config('everdigitech.support.name')])->notify(new StaffTicketAlertNotification(
            $kind,
            $ticket->number,
            $ticket->subject,
            config('everdigitech.admin_url').'/tickets/'.$ticket->getKey(),
            (string) $ticket->account?->name,
            $user->name,
            (string) $ticket->department?->name,
            $ticket->priority->value,
            $message,
        ));

    }

    public function close(Ticket $ticket, Admin|ClientUser $actor): Ticket
    {
        if ($ticket->status !== TicketStatus::Closed) {
            $ticket->forceFill(['status' => TicketStatus::Closed, 'closed_at' => now()])->save();
            $this->activity->log('ticket.closed', $ticket, ['number' => $ticket->number], $actor);
        }

        return $ticket;
    }

    /** Staff changes: status, priority, department. */
    public function update(Ticket $ticket, Admin $admin, array $changes): Ticket
    {
        $changes = array_filter($changes, fn ($value) => $value !== null);

        if (($changes['status'] ?? null) === TicketStatus::Closed->value) {
            $this->close($ticket, $admin);
            unset($changes['status']);
        } elseif (isset($changes['status'])) {
            $changes['closed_at'] = null;
        }

        if ($changes) {
            $ticket->forceFill($changes)->save();
            $this->activity->log('ticket.updated', $ticket, ['number' => $ticket->number, 'changes' => $changes], $admin);
        }

        return $ticket;
    }

    public function assign(Ticket $ticket, Admin $by, ?Admin $to): Ticket
    {
        $ticket->forceFill(['assigned_to' => $to?->getKey()])->save();
        $this->activity->log('ticket.assigned', $ticket, ['number' => $ticket->number, 'assigned_to' => $to?->getKey()], $by);

        return $ticket;
    }

    /** @param  array<string, mixed>  $attributes  @param  array<int, UploadedFile>  $files */
    private function addReply(Ticket $ticket, array $attributes, array $files): TicketReply
    {
        $reply = $ticket->replies()->create($attributes);

        foreach ($files as $file) {
            $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');

            $reply->attachments()->create([
                'original_name' => self::cleanName($file->getClientOriginalName()),
                // A random name: nothing the uploader chose is used as a path.
                'path' => $file->storeAs("tickets/{$ticket->getKey()}", Str::random(40).'.'.$extension, self::DISK),
                'mime' => (string) ($file->getMimeType() ?: 'application/octet-stream'),
                'size' => (int) $file->getSize(),
            ]);
        }

        return $reply;
    }

    /** The name the person sees and downloads: no folders, no control characters, not too long. */
    public static function cleanName(string $name): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F\\\\\/]+/', '_', basename(str_replace('\\', '/', $name))) ?? '');

        return Str::limit($name !== '' ? $name : 'file', 150, '');
    }

    public static function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }
}
