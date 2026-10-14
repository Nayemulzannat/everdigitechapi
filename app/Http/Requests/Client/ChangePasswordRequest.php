<?php

namespace App\Http\Requests\Client;

use App\Support\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;

/** PUT /api/client/password */
class ChangePasswordRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password:client'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', PasswordRules::client()],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.current_password' => 'Your current password is incorrect.',
            'password.different' => 'The new password must be different from the current one.',
        ];
    }
}
