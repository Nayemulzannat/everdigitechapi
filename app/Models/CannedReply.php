<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved answer. The body may contain {client_name}, {ticket_number} and {staff_name}: the admin panel fills them
 * in when the reply is inserted into a ticket.
 */
class CannedReply extends Model
{
    protected $guarded = [];

    public function author(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by')->withTrashed();
    }
}
