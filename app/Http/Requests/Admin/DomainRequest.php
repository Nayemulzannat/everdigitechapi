<?php

namespace App\Http\Requests\Admin;

use App\Enums\DomainStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/admin/domains (a domain bought outside the website, added by hand), PUT /api/admin/domains/{id}
 * {accountId, name, registrar?, status?, registeredAt?, expiresAt?, autoRenew?, locked?, whoisPrivacy?, nameservers?[], notes?}
 */
class DomainRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => strtolower(trim($this->input('name')))]);
        }
        foreach (['notes', 'registeredAt', 'expiresAt'] as $field) {
            if (array_key_exists($field, $this->all()) && is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field)) ?: null]);
            }
        }
        if (is_array($this->input('nameservers'))) {
            $this->merge(['nameservers' => array_values(array_filter(array_map(fn ($ns) => is_string($ns) ? strtolower(trim($ns)) : $ns, $this->input('nameservers')), fn ($ns) => $ns !== '' && $ns !== null))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'accountId' => [$required, 'integer', Rule::exists('client_accounts', 'id')->whereNull('deleted_at')],
            'name' => [$required, 'string', 'max:253', 'regex:/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/', Rule::unique('domains', 'name')->ignore($this->route('id'))],
            'registrar' => ['sometimes', Rule::in(['manual', 'dynadot'])],
            'status' => ['sometimes', Rule::in(array_column(DomainStatus::cases(), 'value'))],
            'registeredAt' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'expiresAt' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:registeredAt'],
            'autoRenew' => ['sometimes', 'boolean'],
            'locked' => ['sometimes', 'boolean'],
            'whoisPrivacy' => ['sometimes', 'boolean'],
            'nameservers' => ['sometimes', 'array', 'max:6'],
            'nameservers.*' => ['string', 'max:253', 'regex:/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex' => 'Enter a domain name like mybrand.com.',
            'name.unique' => 'This domain is already in the list.',
            'expiresAt.after_or_equal' => 'The expiry date cannot be before the registration date.',
            'nameservers.*.regex' => 'A name server looks like ns1.example.com.',
        ];
    }
}
