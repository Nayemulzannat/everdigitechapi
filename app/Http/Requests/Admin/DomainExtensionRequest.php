<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/admin/domain-extensions, PUT /api/admin/domain-extensions/{id}
 * {extension, tag?, registerPrice?, renewPrice?, transferPrice?, isFeatured?, isActive?, sortOrder?}. Prices are paisa (null = ask for price).
 */
class DomainExtensionRequest extends FormRequest
{
    private const MAX_PAISA = 99_999_999_999;

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('extension'))) {
            $extension = strtolower(trim($this->input('extension')));
            $this->merge(['extension' => $extension !== '' && ! str_starts_with($extension, '.') ? '.'.$extension : $extension]);
        }
        if (is_string($this->input('tag'))) {
            $this->merge(['tag' => trim($this->input('tag')) ?: null]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $price = ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.self::MAX_PAISA];

        return [
            'extension' => [$required, 'string', 'max:30', 'regex:/^\.[a-z0-9]{2,}(\.[a-z0-9]{2,})?$/', Rule::unique('domain_extensions', 'extension')->ignore($this->route('id'))],
            'tag' => ['sometimes', 'nullable', 'string', 'max:40'],
            'registerPrice' => $price,
            'renewPrice' => $price,
            'transferPrice' => $price,
            'isFeatured' => ['sometimes', 'boolean'],
            'isActive' => ['sometimes', 'boolean'],
            'sortOrder' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    public function messages(): array
    {
        return [
            'extension.regex' => 'Use an extension like .com or .com.bd (letters and digits only).',
            'extension.unique' => 'This extension is already in the list.',
        ];
    }
}
