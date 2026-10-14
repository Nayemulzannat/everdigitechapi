<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Client\ReplyTicketRequest;
use Illuminate\Foundation\Http\FormRequest;

/** POST /api/admin/tickets/{id}/replies {message, internal?, attachments[]?}: a reply to the client, or an internal note. */
class StaffReplyRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('message'))) {
            $this->merge(['message' => trim($this->input('message'))]);
        }
        if ($this->has('internal')) {
            $this->merge(['internal' => filter_var($this->input('internal'), FILTER_VALIDATE_BOOLEAN)]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['message' => ['required', 'string', 'max:10000'], 'internal' => ['sometimes', 'boolean'], ...ReplyTicketRequest::attachmentRules()];
    }

    public function messages(): array
    {
        return ReplyTicketRequest::attachmentMessages();
    }
}
