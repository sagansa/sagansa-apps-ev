<?php

namespace App\Models;

use App\Models\Concerns\UsesDefaultConnectionWhenTesting;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hasil scan/koneksi OBD2 yang dikirim otomatis dari mobile — bahan baku
 * database dukungan kendaraan (lihat migration `obd_scan_reports`).
 * Developer mereviewnya di Filament (ObdScanReportResource) lalu melahirkan
 * baris `obd_vehicle_profiles` baru untuk kendaraan yang belum didukung.
 */
class ObdScanReport extends Model
{
    use HasFactory;
    use HasUuids;
    use UsesDefaultConnectionWhenTesting;

    protected $connection = 'ev';

    protected $fillable = [
        'user_id',
        'model_vehicle_id',
        'vin',
        'vin_shared',
        'adapter_type',
        'elm_protocol',
        'profile_key',
        'profile_id',
        'profile_version',
        'profile_match',
        'ecus_responded',
        'supported_pids',
        'unsupported_pids',
        'discovery',
        'dtc_codes',
        'atrv_voltage',
        'app_version',
        'device_platform',
        'session_started_at',
        'duration_ms',
        'captured_at',
    ];

    protected $casts = [
        'vin_shared' => 'boolean',
        'profile_id' => 'integer',
        'ecus_responded' => 'array',
        'supported_pids' => 'array',
        'unsupported_pids' => 'array',
        'discovery' => 'array',
        'dtc_codes' => 'array',
        'atrv_voltage' => 'float',
        'session_started_at' => 'integer',
        'duration_ms' => 'integer',
        'captured_at' => 'datetime',
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
