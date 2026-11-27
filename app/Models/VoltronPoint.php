<?php

namespace App\Models;

use App\Models\Concerns\UsesDefaultConnectionWhenTesting;
use Illuminate\Database\Eloquent\Model;

class VoltronPoint extends Model
{
    use UsesDefaultConnectionWhenTesting;

    protected $connection = 'ev';
    protected $table = 'voltron_points';

    protected $fillable = [
        'external_id', 'nama', 'alamat', 'kota', 'provinsi',
        'latitude', 'longitude', 'operator', 'status', 'jumlah_poin',
        'konektor', 'raw_payload', 'diambil_pada',
    ];

    protected $casts = [
        'konektor' => 'array',
        'raw_payload' => 'array',
        'diambil_pada' => 'datetime',
        'latitude' => 'float',
        'longitude' => 'float',
        'jumlah_poin' => 'integer',
    ];
}
