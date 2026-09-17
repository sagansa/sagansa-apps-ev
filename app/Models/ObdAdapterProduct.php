<?php

namespace App\Models;

use App\Models\Concerns\UsesDefaultConnectionWhenTesting;
use Illuminate\Database\Eloquent\Model;

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
     * Scope: only active products, ordered by sort_order.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }
}
