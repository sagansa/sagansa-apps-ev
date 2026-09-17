<?php

namespace App\Models;

use App\Models\Concerns\UsesDefaultConnectionWhenTesting;
use Illuminate\Database\Eloquent\Model;

class OpenchargemapPoi extends Model
{
    use UsesDefaultConnectionWhenTesting;

    protected $connection = 'ev';
    protected $table = 'openchargemap_pois';

    protected $fillable = [
        'ocm_id',
        'uuid',
        'title',
        'address_line1',
        'address_line2',
        'town',
        'state_or_province',
        'postcode',
        'latitude',
        'longitude',
        'operator',
        'usage_cost',
        'status_title',
        'number_of_points',
        'data_quality_level',
        'connections',
        'raw_payload',
        'date_created',
        'date_last_status_update',
        'date_last_verified',
    ];

    protected $casts = [
        'ocm_id' => 'integer',
        'latitude' => 'float',
        'longitude' => 'float',
        'number_of_points' => 'integer',
        'data_quality_level' => 'integer',
        'connections' => 'array',
        'raw_payload' => 'array',
        'date_created' => 'datetime',
        'date_last_status_update' => 'datetime',
        'date_last_verified' => 'datetime',
    ];
}
