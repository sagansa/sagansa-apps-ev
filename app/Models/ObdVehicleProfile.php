<?php

namespace App\Models;

use App\Models\Concerns\UsesDefaultConnectionWhenTesting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
     * Many-to-many (keputusan owner 2026-11-22): satu profil → banyak model
     * (mis. profil BYD untuk Atto 3 + Dolphin), satu model → beberapa profil
     * dibedakan rentang tahun. Kolom `model_vehicle_id` dipertahankan sebagai
     * PRIMARY (model pertama) demi kompatibilitas API/mobile yang lama.
     */
    public function modelVehicles(): BelongsToMany
    {
        return $this->belongsToMany(
            ModelVehicle::class,
            'obd_profile_model_vehicles',
            'obd_vehicle_profile_id',
            'model_vehicle_id',
        );
    }

    /** Selaraskan kolom primary `model_vehicle_id` dgn link pivot pertama. */
    public function refreshPrimaryModel(): void
    {
        $first = $this->modelVehicles()->first();

        if ($this->model_vehicle_id !== $first?->id) {
            $this->update(['model_vehicle_id' => $first?->id]);
        }
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
