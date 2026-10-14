<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/client/email/change/confirm: the four values from the link sent to the new address. */
class ConfirmEmailChangeRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'id' => ['required', 'integer', 'min:1'],
            'hash' => ['required', 'string', 'max:64'],
            'expires' => ['required', 'integer'],
            'signature' => ['required', 'string', 'max:128'],
        ];
    }
}
