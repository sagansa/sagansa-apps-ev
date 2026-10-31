<?php

namespace App\Models;

use App\Models\Concerns\UsesDefaultConnectionWhenTesting;
use Illuminate\Database\Eloquent\Model;

/**
 * Match operator OpenChargeMap → providers (entity-resolution).
 * Dihasilkan OcmOperatorMatchService (auto) atau review manual di Filament.
 */
class OcmOperatorMatch extends Model
{
    use UsesDefaultConnectionWhenTesting;

    protected $connection = 'ev';

    protected $table = 'ocm_operator_matches';

    protected $fillable = [
        'ocm_operator_id',
        'operator_title',
        'provider_id',
        'match_status',
        'match_method',
    ];

    protected $casts = [
        'ocm_operator_id' => 'integer',
    ];

    public function provider()
    {
        return $this->belongsTo(Provider::class, 'provider_id');
    }
}
