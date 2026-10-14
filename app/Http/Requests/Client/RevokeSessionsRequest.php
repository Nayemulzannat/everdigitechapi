<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

/** DELETE /api/client/sessions/others {password} */
class RevokeSessionsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['password' => ['required', 'string', 'current_password:client']];
    }

    public function messages(): array
    {
        return ['password.current_password' => 'Your password is incorrect.'];
    }
}
