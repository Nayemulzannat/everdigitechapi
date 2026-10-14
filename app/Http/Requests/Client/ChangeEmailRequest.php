<?php

namespace App\Http\Requests\Client;

use App\Rules\NotDisposableEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /api/client/email/change {new_email, password} */
class ChangeEmailRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('new_email'))) {
            $this->merge(['new_email' => strtolower(trim($this->input('new_email')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'new_email' => [
                'required', 'string', 'email', 'max:255',
                Rule::notIn([strtolower((string) $this->user('client')?->email)]),
                Rule::unique('client_users', 'email'),
                new NotDisposableEmail,
            ],
            'password' => ['required', 'string', 'current_password:client'],
        ];
    }

    public function messages(): array
    {
        return [
            'new_email.not_in' => 'That is already your email address.',
            'new_email.unique' => 'An account with this email already exists.',
            'password.current_password' => 'Your password is incorrect.',
        ];
    }
}
