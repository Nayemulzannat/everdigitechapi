<?php

namespace App\Models;

use App\Enums\BillingCycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The price of a product for one billing cycle, in paisa for the whole cycle. */
class ProductPrice extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['cycle' => BillingCycle::class, 'amount' => 'integer', 'compare_amount' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
