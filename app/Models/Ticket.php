<?php

namespace App\Models;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A support request of an account: a subject and a thread of replies. */
class Ticket extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'priority' => TicketPriority::class,
            'last_reply_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** "TKT-000123": what people say on the phone. Derived from the id, so it never has gaps or duplicates. */
    public function getNumberAttribute(): string
    {
        return 'TKT-'.str_pad((string) $this->getKey(), 6, '0', STR_PAD_LEFT);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class, 'account_id')->withTrashed();
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(ClientUser::class, 'opened_by')->withTrashed();
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(TicketDepartment::class, 'department_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'assigned_to')->withTrashed();
    }

    public function replies(): HasMany
    {
        return $this->hasMany(TicketReply::class)->oldest('id');
    }

    /**
     * Adds `unread` to each ticket for one client: a reply from support (not a staff note) that this person has not
     * opened yet. Their own replies never count.
     */
    public function scopeWithUnreadFor(Builder $query, int $userId): void
    {
        $query->addSelect('tickets.*')->selectRaw(
            'exists (select 1 from ticket_replies r where r.ticket_id = tickets.id and r.author_type = \'staff\' and r.is_internal = 0 '
            .'and r.id > coalesce((select read_reply_id from ticket_client_reads c where c.ticket_id = tickets.id and c.client_user_id = ?), 0)) as unread',
            [$userId],
        );
    }

    /** Only the tickets that are new for this client (see scopeWithUnreadFor). */
    public function scopeUnreadFor(Builder $query, int $userId): void
    {
        $query->whereRaw(
            'exists (select 1 from ticket_replies r where r.ticket_id = tickets.id and r.author_type = \'staff\' and r.is_internal = 0 '
            .'and r.id > coalesce((select read_reply_id from ticket_client_reads c where c.ticket_id = tickets.id and c.client_user_id = ?), 0))',
            [$userId],
        );
    }

    /** Matches the subject or the ticket number ("TKT-000123", "123"). */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);
        if ($term === '') {
            return;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';
        $digits = preg_replace('/\D/', '', $term);

        $query->where(function (Builder $q) use ($like, $digits, $term) {
            $q->where('subject', 'like', $like);
            if ($digits !== '' && preg_match('/^(tkt-?)?\d+$/i', $term)) {
                $q->orWhere('id', (int) $digits);
            }
        });
    }
}
