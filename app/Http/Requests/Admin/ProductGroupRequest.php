<?php

namespace App\Http\Requests\Admin;

use App\Enums\ProductType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /api/admin/product-groups, PUT /api/admin/product-groups/{id} {name, type, description?, sortOrder?, isActive?} */
class ProductGroupRequest extends FormRequest
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
            'name' => [$required, 'string', 'max:100', Rule::unique('product_groups', 'name')->ignore($this->route('id'))],
            'type' => [$required, Rule::in(array_column(ProductType::cases(), 'value'))],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'sortOrder' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['name.unique' => 'There is already a group with this name.'];
    }
}
