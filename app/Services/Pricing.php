<?php

namespace App\Services;

use App\Enums\BillingCycle;
use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\Setting;

/**
 * Prices and tax. Every amount is a whole number of paisa (100 paisa = 1 taka).
 *
 * The tax is what an admin saved under Settings (the `settings` table, key "tax"); until someone saves it, the defaults in
 * config('everdigitech.tax') (.env) apply. With `inclusive` false the prices in the catalog are WITHOUT tax and
 * tax is added on top; with it true the prices already contain tax and the tax is the part inside them.
 */
class Pricing
{
    /** @var array{label: string, rate: float, inclusive: bool}|null */
    private ?array $tax = null;

    /** @return array{label: string, rate: float, inclusive: bool} */
    public function tax(): array
    {
        $saved = $this->tax === null ? Setting::read('tax') : null;

        return $this->tax ??= [
            'label' => (string) ($saved['label'] ?? config('everdigitech.tax.label')),
            'rate' => (float) ($saved['rate'] ?? config('everdigitech.tax.rate')),
            'inclusive' => (bool) ($saved['inclusive'] ?? config('everdigitech.tax.inclusive')),
        ];
    }

    /**
     * Splits an amount from the catalog into what is before tax, the tax, and what the client pays.
     *
     * @return array{net: int, tax: int, total: int}
     */
    public function breakdown(int $amount): array
    {
        ['rate' => $rate, 'inclusive' => $inclusive] = $this->tax();

        if ($inclusive) {
            $net = (int) round($amount / (1 + $rate / 100), 0, PHP_ROUND_HALF_UP);

            return ['net' => $net, 'tax' => $amount - $net, 'total' => $amount];
        }

        $tax = (int) round($amount * $rate / 100, 0, PHP_ROUND_HALF_UP);

        return ['net' => $amount, 'tax' => $tax, 'total' => $amount + $tax];
    }

    /**
     * What buying a product with a billing cycle costs: the price for the cycle, the one-time setup fee, and the sum of both.
     * Used by the order step. 422 `cycle_not_available` when the product is not sold with that cycle.
     *
     * @return array{cycle: string, months: ?int, price: array{net: int, tax: int, total: int}, setup_fee: array{net: int, tax: int, total: int}, due_now: array{net: int, tax: int, total: int}}
     */
    public function quote(Product $product, BillingCycle $cycle): array
    {
        $price = $product->priceFor($cycle);
        if (! $price) {
            throw new ApiException(422, 'cycle_not_available', 'This product is not sold with that billing cycle.');
        }

        return [
            'cycle' => $cycle->value,
            'months' => $cycle->months(),
            'price' => $this->breakdown($price->amount),
            'setup_fee' => $this->breakdown($product->setup_fee),
            'due_now' => $this->breakdown($price->amount + $product->setup_fee),
        ];
    }
}
