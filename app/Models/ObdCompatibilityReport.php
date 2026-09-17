<?php

namespace App\Models;

use App\Models\Concerns\UsesDefaultConnectionWhenTesting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ObdCompatibilityReport extends Model
{
    use UsesDefaultConnectionWhenTesting;

    protected $connection = 'ev';

    protected $fillable = [
        'report_id',
        'user_id',
        'model_vehicle_id',
        'platform',
        'adapter_type',
        'supported_pids',
        'unsupported_pids',
        'opt_in',
    ];

    protected $casts = [
        'supported_pids' => 'array',
        'unsupported_pids' => 'array',
        'opt_in' => 'boolean',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (ObdCompatibilityReport $model) {
            if (empty($model->report_id)) {
                $model->report_id = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function modelVehicle(): BelongsTo
    {
        return $this->belongsTo(ModelVehicle::class);
    }
}
