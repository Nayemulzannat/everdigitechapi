<?php

namespace App\Http\Requests\Admin;

use App\Enums\ClientGroup;
use App\Enums\ClientStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** PUT /api/admin/clients/{id} {name?, company?, group?, status?, notes?} */
class ClientUpdateRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['name', 'company', 'notes'] as $field) {
            if (array_key_exists($field, $this->all()) && is_string($this->input($field))) {
                $value = trim($this->input($field));
                $this->merge([$field => $value === '' && $field !== 'name' ? null : $value]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'company' => ['sometimes', 'nullable', 'string', 'max:255'],
            'group' => ['sometimes', Rule::in(array_column(ClientGroup::cases(), 'value'))],
            'status' => ['sometimes', Rule::in(array_column(ClientStatus::cases(), 'value'))],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
