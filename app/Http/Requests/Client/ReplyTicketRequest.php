<?php

namespace App\Http\Requests\Client;

use App\Services\Tickets;
use Illuminate\Foundation\Http\FormRequest;

/** POST /api/client/tickets/{id}/replies {message, attachments[]?} (multipart when there are files) */
class ReplyTicketRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('message'))) {
            $this->merge(['message' => trim($this->input('message'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['message' => ['required', 'string', 'max:10000'], ...self::attachmentRules()];
    }

    public function messages(): array
    {
        return self::attachmentMessages();
    }

    /** The rules for files, shared by every form that takes attachments (client and staff). @return array<string, mixed> */
    public static function attachmentRules(): array
    {
        return [
            'attachments' => ['sometimes', 'array', 'max:'.Tickets::MAX_FILES],
            'attachments.*' => ['file', 'mimes:'.implode(',', Tickets::FILE_TYPES), 'max:'.Tickets::MAX_FILE_KB],
        ];
    }

    /** @return array<string, string> */
    public static function attachmentMessages(): array
    {
        return [
            'attachments.max' => 'You can attach up to '.Tickets::MAX_FILES.' files.',
            'attachments.*.mimes' => 'Files must be one of: '.implode(', ', Tickets::FILE_TYPES).'.',
            'attachments.*.max' => 'Each file must be '.(Tickets::MAX_FILE_KB / 1024).' MB or smaller.',
            'attachments.*.file' => 'The attachment could not be uploaded.',
        ];
    }
}
