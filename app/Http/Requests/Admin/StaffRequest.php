<?php

namespace App\Http\Requests\Admin;

use App\Enums\AdminStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /staff {name, email, phone, role} and PUT /staff/{id} {name, phone, role, status}.
 * `role` is a role id. The email can't be changed once the account exists.
 */
class StaffRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'email' => is_string($this->input('email')) ? strtolower(trim($this->input('email'))) : $this->input('email'),
            'phone' => is_string($this->input('phone')) ? (trim($this->input('phone')) ?: null) : $this->input('phone'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ()\-]{6,30}$/'],
            'role' => ['required', 'integer', Rule::exists('roles', 'id')->where('guard_name', 'admin')],
        ];

        if ($this->isMethod('POST')) {
            // Soft-deleted accounts keep their email reserved (unique index), so they count too.
            $rules['email'] = ['required', 'string', 'email', 'max:255', Rule::unique('admins', 'email')];
        } else {
            $rules['status'] = ['required', Rule::enum(AdminStatus::class)];
        }

        return $rules;
    }

    public function messages(): array
    {
        return ['email.unique' => 'A staff member with this email already exists.'];
    }
}
