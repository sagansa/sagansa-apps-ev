<?php

namespace App\Models;

use App\Models\Concerns\UsesDefaultConnectionWhenTesting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ObdAccess extends Model
{
    use UsesDefaultConnectionWhenTesting;

    protected $connection = 'ev';

    protected $fillable = [
        'user_id',
        'status',
        'granted_by',
        'granted_at',
    ];

    protected $casts = [
        'granted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if this access record is granted.
     */
    public function isGranted(): bool
    {
        return $this->status === 'granted';
    }
}
