<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /api/admin/canned-replies, PUT /api/admin/canned-replies/{id} {title, body} */
class CannedReplyRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['title', 'body'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'max:100', Rule::unique('canned_replies', 'title')->ignore($this->route('id'))],
            // The same limit as a ticket message, so an inserted reply can always be sent.
            'body' => [$required, 'string', 'max:10000'],
        ];
    }

    public function messages(): array
    {
        return ['title.unique' => 'There is already a saved reply with this title.'];
    }
}
