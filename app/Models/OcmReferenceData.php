<?php

namespace App\Models;

use App\Models\Concerns\UsesDefaultConnectionWhenTesting;
use Illuminate\Database\Eloquent\Model;

/**
 * Snapshot katalog referensi OCM per scope — dipakai menerjemahkan ID integer
 * di respons compact (OperatorID, ConnectionTypeID, dst) menjadi nama.
 */
class OcmReferenceData extends Model
{
    use UsesDefaultConnectionWhenTesting;

    protected $connection = 'ev';

    protected $table = 'ocm_reference_data';

    protected $primaryKey = 'scope';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = true;

    protected $fillable = [
        'scope',
        'payload',
        'fetched_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'fetched_at' => 'datetime',
    ];
}
