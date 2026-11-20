<?php

namespace App\Models;

use App\Models\Concerns\UsesDefaultConnectionWhenTesting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ModelVehicle extends Model
{
    use UsesDefaultConnectionWhenTesting;

    use HasFactory;

    protected $connection = 'ev'; // Use the sagansa database connection

    protected $fillable = [
        'image',
        'name',
        'category',
        'size_class',
        'platform',
        'brand_vehicle_id',
    ];

    protected $casts = [
        'category' => 'string',
        'size_class' => 'string',
        'platform' => 'string',
    ];

    public function brandVehicle()
    {
        return $this->belongsTo(BrandVehicle::class);
    }

    public function typeVehicles()
    {
        return $this->hasMany(TypeVehicle::class);
    }

    public function vehicles()
    {
        return $this->hasMany(Vehicle::class);
    }
}
