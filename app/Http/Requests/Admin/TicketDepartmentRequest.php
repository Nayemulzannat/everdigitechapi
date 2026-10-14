<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /api/admin/ticket-departments, PUT /api/admin/ticket-departments/{id} {name, description?, isActive?} */
class TicketDepartmentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['name', 'description'] as $field) {
            if (is_string($this->input($field))) {
                $value = trim($this->input($field));
                $this->merge([$field => $value === '' && $field === 'description' ? null : $value]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            // MySQL compares names without regard to case, so "billing" and "Billing" are the same name.
            'name' => [$required, 'string', 'max:100', Rule::unique('ticket_departments', 'name')->ignore($this->route('id'))],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['name.unique' => 'There is already a department with this name.'];
    }
}
