<?php

namespace App\Models;

use App\Models\Concerns\UsesDefaultConnectionWhenTesting;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicle extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;
    use UsesDefaultConnectionWhenTesting;

    protected $connection = 'ev'; // Use the sagansa database connection

    protected $fillable = [
        'user_id',
        'image',
        'license_plate',
        'battery_capacity_kwh',
        'ac_charging_power_kw',
        'initial_odometer',
        'tax_due_date',
        'brand_vehicle_id',
        'model_vehicle_id',
        'type_vehicle_id',
        'ownership',
        'status',
    ];

    protected $casts = [
        'battery_capacity_kwh' => 'float',
        'ac_charging_power_kw' => 'float',
        'initial_odometer' => 'float',
        'tax_due_date' => 'date',
        'status' => 'integer',
    ];

    public function brandVehicle()
    {
        return $this->belongsTo(BrandVehicle::class);
    }

    public function modelVehicle()
    {
        return $this->belongsTo(ModelVehicle::class);
    }

    public function typeVehicle()
    {
        return $this->belongsTo(TypeVehicle::class);
    }

    public function charges()
    {
        return $this->hasMany(Charge::class);
    }

    public function stateOfHealths()
    {
        return $this->hasMany(StateOfHealth::class);
    }

    public function batteries()
    {
        return $this->hasMany(Battery::class);
    }

    /**
     * Catatan servis kendaraan (fitur Pro) — sumber jadwal pengingat servis
     * di mobile via `next_due_date`/`next_due_km`.
     */
    public function serviceLogs()
    {
        return $this->hasMany(ServiceLog::class);
    }

    /**
     * Baterai aktif terbaru (terpasang, belum pensiun) — dipakai utk
     * auto-assign battery_id saat sesi charging / SoH dibuat tanpa eksplisit.
     */
    public function activeBattery()
    {
        return $this->hasOne(Battery::class)
            ->active()
            ->orderByDesc('installed_at');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getMaxKmNowAttribute()
    {
        return $this->charges->max('km_now');
    }

    /**
     * Kapasitas baterai efektif (kWh) — kolom per-kendaraan menang, fallback ke
     * kapasitas level trim (typeVehicle.battery_capacity). Nullable: bila kedua
     * sumber kosong, estimasi kWh tidak bisa dihitung (mobile menampilkan hint).
     * Serialisasi API otomatis memakai camelCase `batteryCapacityKwh`.
     */
    public function getBatteryCapacityKwhAttribute(): ?float
    {
        $raw = $this->attributes['battery_capacity_kwh'] ?? $this->typeVehicle?->battery_capacity;

        return $raw === null ? null : (float) $raw;
    }
}
