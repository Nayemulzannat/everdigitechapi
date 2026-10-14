<?php

namespace App\Http\Requests\Admin;

use App\Exceptions\ApiException;
use App\Models\Role;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /roles ({name, description}) and PUT /roles/{id} ({name, description, permissions}). */
class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // A locked role can't be changed by anyone, so say so before validating the body.
        if ($this->isMethod('PUT') && Role::query()->where('guard_name', 'admin')->whereKey($this->route('id'))->where('is_locked', true)->exists()) {
            throw new ApiException(403, 'role_locked', 'This role is locked and can\'t be changed.');
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('roles', 'name')->where('guard_name', 'admin')->ignore($this->route('id')),
            ],
            'description' => ['present', 'nullable', 'string', 'max:255'],
        ];

        if ($this->isMethod('PUT')) {
            $rules['permissions'] = ['present', 'array']; // an empty list is valid: it revokes everything
            $rules['permissions.*'] = ['string', Rule::in(PermissionCatalog::keys())];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'A role with this name already exists.',
            'permissions.*.in' => 'Unknown permission ":input".',
        ];
    }
}
