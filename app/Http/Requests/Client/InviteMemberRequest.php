<?php

namespace App\Http\Requests\Client;

use App\Rules\NotDisposableEmail;
use App\Support\AccountPermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /api/client/team/invitations {email, permissions[]} */
class InviteMemberRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255', Rule::notIn([strtolower((string) $this->user('client')?->email)]), new NotDisposableEmail],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::in(AccountPermissionCatalog::keys())],
        ];
    }

    public function messages(): array
    {
        return [
            'email.not_in' => 'You cannot invite yourself.',
            'permissions.required' => 'Choose what this person may do.',
            'permissions.min' => 'Choose what this person may do.',
            'permissions.*.in' => 'One of the chosen permissions does not exist.',
        ];
    }
}
