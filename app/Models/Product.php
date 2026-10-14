<?php

namespace App\Models;

use App\Enums\BillingCycle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One thing a client can buy. Money fields are whole numbers of paisa. */
class Product extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'feature_groups' => 'array',
            'setup_fee' => 'integer',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** The feature sections in a fixed shape: [{title, items[]}]. (MySQL's JSON type does not keep key order.) */
    public function featureSections(): array
    {
        return array_values(array_map(fn ($g) => ['title' => (string) ($g['title'] ?? ''), 'items' => array_values((array) ($g['items'] ?? []))], (array) ($this->feature_groups ?? [])));
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'product_group_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    /** The price for one cycle, or null when the product is not sold with it. */
    public function priceFor(BillingCycle $cycle): ?ProductPrice
    {
        return ($this->relationLoaded('prices') ? $this->prices : $this->prices()->get())->firstWhere('cycle', $cycle);
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }

    /** Matches the name or the tagline. */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);
        if ($term === '') {
            return;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';
        $query->where(fn (Builder $q) => $q->where('name', 'like', $like)->orWhere('tagline', 'like', $like));
    }
}
