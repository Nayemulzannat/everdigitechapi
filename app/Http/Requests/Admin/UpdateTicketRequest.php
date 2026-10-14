<?php

namespace App\Http\Requests\Admin;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** PUT /api/admin/tickets/{id} {status?, priority?, departmentId?} */
class UpdateTicketRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(array_column(TicketStatus::cases(), 'value'))],
            'priority' => ['sometimes', Rule::in(array_column(TicketPriority::cases(), 'value'))],
            'departmentId' => ['sometimes', 'integer', Rule::exists('ticket_departments', 'id')],
        ];
    }

    /** The changes as columns. @return array<string, mixed> */
    public function changes(): array
    {
        $data = $this->validated();

        return array_filter([
            'status' => $data['status'] ?? null,
            'priority' => $data['priority'] ?? null,
            'department_id' => $data['departmentId'] ?? null,
        ], fn ($value) => $value !== null);
    }
}
