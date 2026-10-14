<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** PUT /api/admin/settings/tax {label, rate, inclusive} (rate is a percent, 0 to 100, up to 2 decimals) */
class TaxSettingsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('label'))) {
            $this->merge(['label' => trim($this->input('label'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:20'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'inclusive' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['rate.decimal' => 'Use at most two decimals, like 7.5.', 'rate.max' => 'The rate is a percent, so at most 100.'];
    }
}
