<?php

namespace App\Models;

use App\Models\Concerns\UsesDefaultConnectionWhenTesting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ObdVehicleProfile extends Model
{
    use UsesDefaultConnectionWhenTesting;

    protected $connection = 'ev';

    protected $fillable = [
        'model_vehicle_id',
        'platform',
        'vin_pattern',
        'year_min',
        'year_max',
        'grade',
        'version',
        'status',
        'pid_list',
        'metadata',
    ];

    protected $casts = [
        'pid_list' => 'array',
        'metadata' => 'array',
        'year_min' => 'integer',
        'year_max' => 'integer',
    ];

    public function modelVehicle(): BelongsTo
    {
        return $this->belongsTo(ModelVehicle::class);
    }

    /**
     * Scope: only active profiles.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope: filter by platform.
     */
    public function scopeForPlatform($query, string $platform)
    {
        return $query->where('platform', $platform);
    }

    /**
     * Scope: filter by grade.
     */
    public function scopeForGrade($query, string $grade)
    {
        return $query->where('grade', $grade);
    }
}
