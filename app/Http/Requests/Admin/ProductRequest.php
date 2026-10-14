<?php

namespace App\Http\Requests\Admin;

use App\Enums\BillingCycle;
use App\Models\Product;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/admin/products, PUT /api/admin/products/{id}
 * {groupId, name, tagline?, description?, features?[], setupFee?, isActive?, isFeatured?, sortOrder?, prices?[{cycle, amount}]}
 * Money is a whole number of paisa. When `prices` is sent it REPLACES the product's prices.
 */
class ProductRequest extends FormRequest
{
    /** Up to about 1 billion taka, so an amount can never overflow. */
    private const MAX_PAISA = 99_999_999_999;

    protected function prepareForValidation(): void
    {
        foreach (['name', 'tagline', 'description'] as $field) {
            if (is_string($this->input($field))) {
                $value = trim($this->input($field));
                $this->merge([$field => $value === '' && $field !== 'name' ? null : $value]);
            }
        }

        // A section with no lines is dropped; empty lines inside a section too.
        if (is_array($this->input('featureGroups'))) {
            $groups = [];
            foreach ($this->input('featureGroups') as $group) {
                $items = array_values(array_filter(array_map(fn ($line) => is_string($line) ? trim($line) : $line, (array) ($group['items'] ?? [])), fn ($line) => $line !== '' && $line !== null));
                $title = is_string($group['title'] ?? null) ? trim($group['title']) : ($group['title'] ?? '');
                if ($title !== '' || $items !== []) {
                    $groups[] = ['title' => $title, 'items' => $items];
                }
            }
            $this->merge(['featureGroups' => $groups]);
        }

        // Empty lines are not features.
        if (is_array($this->input('features'))) {
            $this->merge(['features' => array_values(array_filter(array_map(fn ($line) => is_string($line) ? trim($line) : $line, $this->input('features')), fn ($line) => $line !== '' && $line !== null))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $groupId = $this->input('groupId') ?? Product::find($this->route('id'))?->product_group_id;

        return [
            'groupId' => [$required, 'integer', Rule::exists('product_groups', 'id')],
            'name' => [$required, 'string', 'max:150', Rule::unique('products', 'name')->where('product_group_id', $groupId)->whereNull('deleted_at')->ignore($this->route('id'))],
            'tagline' => ['sometimes', 'nullable', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'features' => ['sometimes', 'array', 'max:20'],
            'features.*' => ['string', 'max:120'],
            'featureGroups' => ['sometimes', 'array', 'max:8'],
            'featureGroups.*.title' => ['required', 'string', 'max:60'],
            'featureGroups.*.items' => ['required', 'array', 'min:1', 'max:40'],
            'featureGroups.*.items.*' => ['string', 'max:120'],
            'setupFee' => ['sometimes', 'integer', 'min:0', 'max:'.self::MAX_PAISA],
            'isActive' => ['sometimes', 'boolean'],
            'isFeatured' => ['sometimes', 'boolean'],
            'sortOrder' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'prices' => ['sometimes', 'array', 'max:'.count(BillingCycle::cases())],
            'prices.*.cycle' => ['required', Rule::in(array_column(BillingCycle::cases(), 'value')), 'distinct'],
            'prices.*.amount' => ['required', 'integer', 'min:1', 'max:'.self::MAX_PAISA],
            'prices.*.compareAmount' => ['nullable', 'integer', 'gt:prices.*.amount', 'max:'.self::MAX_PAISA],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'This group already has a product with this name.',
            'prices.*.cycle.distinct' => 'Each billing cycle can have only one price.',
            'prices.*.amount.min' => 'A price must be more than zero. Leave a cycle out to not sell with it.',
            'prices.*.compareAmount.gt' => 'The regular price must be higher than the real price, or leave it empty.',
            'features.max' => 'Use at most 20 feature lines.',
        ];
    }

    /** A product that clients can buy needs at least one price. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $existing = $this->route('id') ? Product::withCount('prices')->find($this->route('id')) : null;
                // A product that does not exist is answered with 404 by the controller, not with a price error.
                if ($this->route('id') && ! $existing) {
                    return;
                }

                $active = $this->has('isActive') ? $this->boolean('isActive') : ($existing?->is_active ?? true);
                $priceCount = $this->has('prices') ? count($this->input('prices')) : ($existing?->prices_count ?? 0);

                if ($active && $priceCount === 0) {
                    $validator->errors()->add('prices', 'Give it at least one price, or switch the product off.');
                }
            },
        ];
    }
}
