<?php

namespace App\Http\Requests\Client;

use App\Enums\TicketPriority;
use App\Services\Tickets;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /api/client/tickets (multipart when there are files) */
class StoreTicketRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['subject', 'message'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:150'],
            'department_id' => ['required', 'integer', Rule::exists('ticket_departments', 'id')->where('is_active', true)],
            'priority' => ['sometimes', Rule::in(array_column(TicketPriority::cases(), 'value'))],
            'message' => ['required', 'string', 'max:10000'],
            ...ReplyTicketRequest::attachmentRules(),
        ];
    }

    public function priority(): TicketPriority
    {
        return TicketPriority::tryFrom((string) $this->validated('priority')) ?? TicketPriority::Medium;
    }

    public function messages(): array
    {
        return ['department_id.exists' => 'Choose a department.', ...ReplyTicketRequest::attachmentMessages()];
    }
}
