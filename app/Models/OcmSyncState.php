<?php

namespace App\Models;

use App\Models\Concerns\UsesDefaultConnectionWhenTesting;
use Illuminate\Database\Eloquent\Model;

/**
 * State sinkronisasi OCM per negara — sumber modifiedsince utk
 * ocm:sync incremental (pengganti file data/openchargemap_sync_state.json).
 */
class OcmSyncState extends Model
{
    use UsesDefaultConnectionWhenTesting;

    protected $connection = 'ev';

    protected $table = 'ocm_sync_state';

    protected $fillable = [
        'country',
        'last_sync_utc',
        'poi_count',
        'mode',
    ];

    protected $casts = [
        'last_sync_utc' => 'datetime',
        'poi_count' => 'integer',
    ];
}
