<?php

namespace App\Models;

use App\Models\Concerns\UsesDefaultConnectionWhenTesting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TypeVehicle extends Model
{
    use UsesDefaultConnectionWhenTesting;

    use HasFactory;

    protected $connection = 'ev'; // Use the sagansa database connection

    protected $fillable = [
        'name',
        'model_vehicle_id',
        'image',
        'type_charger',
        'battery_capacity',
        'powertrain',
        // Spesifikasi GAIKINDO (import XLSX gaikindo-converter)
        'cc',
        'trans',
        'fuel',
        'tank',
        'gvw',
        'gear_ratio',
        'wheel_tyre',
        'ps_hp',
        'wheel_base',
        'dimension',
        'seater',
        'drive_sys',
        'speed',
        'door',
        'wheels',
        'cbu_ckd',
        'origin_country',
        'specs_source',
        'specs_updated_at',
    ];

    public function modelVehicle()
    {
        return $this->belongsTo(ModelVehicle::class);
    }

    public function vehicles()
    {
        return $this->hasMany(Vehicle::class);
    }

    public function typeCharger()
    {
        return $this->belongsTo(TypeCharger::class);
    }

    protected $casts = [
        'type_charger' => 'array',
        'specs_updated_at' => 'datetime',
    ];
}
