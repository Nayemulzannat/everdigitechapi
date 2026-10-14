<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** How far one client has read one ticket. See the migration for the idea. */
class TicketClientRead extends Model
{
    public const CREATED_AT = null;

    protected $guarded = [];
}
