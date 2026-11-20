<?php

namespace App\Models;

use App\Models\Concerns\UsesDefaultConnectionWhenTesting;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu kendaraan OBD2 milik user (registry tab Cockpit mobile) — dipindahkan
 * dari internal storage mobile ke backend (2026-11-20) agar lintas perangkat
 * dan bisa dikelola manual dari Filament bila ada data OBD2 baru.
 *
 * Profil PID TIDAK disimpan di sini — mobile me-resolve dari katalog
 * `obd_vehicle_profiles` via `model_vehicle_id`/brand+model dengan fallback
 * Universal otomatis. Yang tersimpan: identitas + preferensi user + discovery
 * + konfigurasi metrik + statistik konsumsi (JSON bentukan mobile).
 */
class ObdVehicle extends Model
{
    use HasUuids;
    use UsesDefaultConnectionWhenTesting;

    protected $connection = 'ev';

    protected $table = 'obd_vehicles';

    protected $fillable = [
        'user_id',
        'model_vehicle_id',
        'garage_vehicle_id',
        'nickname',
        'brand_name',
        'model_name',
        'platform',
        'usable_kwh',
        'efficiency_km_per_kwh',
        'invert_current',
        'metric_config',
        'discovered',
        'usage',
        'sort_order',
        'last_used_at',
    ];

    protected $casts = [
        'model_vehicle_id' => 'integer',
        'usable_kwh' => 'float',
        'efficiency_km_per_kwh' => 'float',
        'invert_current' => 'boolean',
        'metric_config' => 'array',
        'discovered' => 'array',
        'usage' => 'array',
        'sort_order' => 'integer',
        'last_used_at' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function modelVehicle(): BelongsTo
    {
        return $this->belongsTo(ModelVehicle::class);
    }
}
