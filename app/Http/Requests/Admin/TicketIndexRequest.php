<?php

namespace App\Http\Requests\Admin;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** GET /api/admin/tickets?page=&status=&priority=&department=&assigned=&search=&sort= ("all" and empty values mean no filter). */
class TicketIndexRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::in(['all', ...array_column(TicketStatus::cases(), 'value')])],
            'priority' => ['sometimes', Rule::in(['all', ...array_column(TicketPriority::cases(), 'value')])],
            'department' => ['sometimes', 'nullable', 'integer'],
            // "me", "unassigned", or the id of a staff member
            'assigned' => ['sometimes', 'nullable', 'regex:/^(all|me|unassigned|\d+)$/'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            // newest = latest activity first (default); oldest = the one waiting longest first
            'sort' => ['sometimes', Rule::in(['newest', 'oldest'])],
        ];
    }
}
