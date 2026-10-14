<?php

namespace App\Models;

use App\Services\DomainPricing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** An extension we sell, with its prices in paisa (null = no price set). */
class DomainExtension extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'register_price' => 'integer',
            'renew_price' => 'integer',
            'transfer_price' => 'integer',
            'is_featured' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'cost_register' => 'integer',
            'cost_renew' => 'integer',
            'cost_transfer' => 'integer',
        ];
    }

    /**
     * What the client pays for a year, in paisa, before tax: the price an admin typed, or else worked out from the
     * registrar's cost with the pricing rule (rate and margin). Null = no price: the website says "Ask for price".
     *
     * @param  'register'|'renew'|'transfer'  $kind
     */
    public function price(string $kind, ?DomainPricing $rule = null): ?int
    {
        $manual = $this->{$kind.'_price'};
        if ($manual) {
            return $manual;
        }

        return ($rule ?? app(DomainPricing::class))->fromCost($this->{'cost_'.$kind});
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }

    /** The extension a domain name ends with, the longest match first (".com.bd" before ".bd"). */
    public static function forDomain(string $domain): ?self
    {
        return static::query()->get()->sortByDesc(fn (self $e) => strlen($e->extension))->first(fn (self $e) => str_ends_with($domain, $e->extension));
    }
}
