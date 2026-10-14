<?php

namespace App\Http\Resources;

use App\Models\DomainExtension;
use App\Services\DomainPricing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An extension in the admin panel (camelCase). Prices are paisa, null = not set. `cost*` is what the registrar charges us
 * (US cents). `effective*` is what the client pays before tax: the price typed, or worked out from the cost by the rule.
 *
 * @property DomainExtension $resource
 */
class AdminDomainExtensionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $e = $this->resource;
        $rule = app(DomainPricing::class);

        return [
            'id' => (string) $e->getKey(),
            'extension' => $e->extension,
            'tag' => $e->tag,
            'registerPrice' => $e->register_price,
            'renewPrice' => $e->renew_price,
            'transferPrice' => $e->transfer_price,
            'effectiveRegisterPrice' => $e->price('register', $rule),
            'effectiveRenewPrice' => $e->price('renew', $rule),
            'effectiveTransferPrice' => $e->price('transfer', $rule),
            'priceSource' => $e->register_price ? 'manual' : ($e->price('register', $rule) ? 'auto' : null),
            'costRegister' => $e->cost_register,
            'costRenew' => $e->cost_renew,
            'costTransfer' => $e->cost_transfer,
            'usage' => $e->usage,
            'restrictions' => $e->restrictions,
            'isFeatured' => $e->is_featured,
            'isActive' => $e->is_active,
            'sortOrder' => $e->sort_order,
        ];
    }
}
