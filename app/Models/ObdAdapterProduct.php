<?php

namespace App\Models;

use App\Models\Concerns\UsesDefaultConnectionWhenTesting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ObdAdapterProduct extends Model
{
    use UsesDefaultConnectionWhenTesting;

    protected $connection = 'ev';

    protected $fillable = [
        'name',
        'brand',
        'type',
        'image_url',
        'affiliate_url',
        'price_idr',
        'description',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'price_idr' => 'decimal:2',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Link marketplace produk (multi-link, 2026-11-25) — terurut.
     */
    public function links(): HasMany
    {
        return $this->hasMany(ObdAdapterProductLink::class, 'obd_adapter_product_id')
            ->orderBy('sort_order');
    }

    /**
     * Scope: only active products, ordered by sort_order.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }
}
