<?php

namespace App\Http\Requests\Client;

use App\Support\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;

/** POST /api/client/reset-password {token, email, password, password_confirmation}: the values from the emailed link. */
class ResetPasswordRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed', PasswordRules::client()],
        ];
    }
}
