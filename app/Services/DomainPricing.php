<?php

namespace App\Services;

use App\Models\Setting;

/**
 * The rule that turns the registrar's cost (US cents) into our price (paisa) for an extension nobody priced by hand:
 * cost x taka per dollar x (1 + margin), rounded UP to a multiple of `roundTo` taka. Saved in the settings table.
 * Without a rate nothing is worked out and such extensions show "Ask for price".
 */
class DomainPricing
{
    /** @var array{usdRate: ?float, marginPercent: float, roundTo: int}|null */
    private ?array $rule = null;

    /** @return array{usdRate: ?float, marginPercent: float, roundTo: int} */
    public function rule(): array
    {
        $saved = $this->rule === null ? Setting::read('domain_pricing') : null;

        return $this->rule ??= [
            'usdRate' => isset($saved['usdRate']) && $saved['usdRate'] > 0 ? (float) $saved['usdRate'] : null,
            'marginPercent' => (float) ($saved['marginPercent'] ?? 30),
            'roundTo' => max(1, (int) ($saved['roundTo'] ?? 10)),
        ];
    }

    /** Our price in paisa for a cost in US cents, or null when there is no cost or no rate. */
    public function fromCost(?int $cents): ?int
    {
        $rule = $this->rule();
        if (! $cents || ! $rule['usdRate']) {
            return null;
        }

        $taka = ($cents / 100) * $rule['usdRate'] * (1 + $rule['marginPercent'] / 100);

        return (int) (ceil($taka / $rule['roundTo']) * $rule['roundTo'] * 100);
    }
}
