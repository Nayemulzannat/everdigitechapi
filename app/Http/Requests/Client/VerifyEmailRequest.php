<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/client/email/verify: the four values from the link in the email. */
class VerifyEmailRequest extends FormRequest
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
