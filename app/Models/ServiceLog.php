<?php

namespace App\Models;

use App\Models\Concerns\UsesDefaultConnectionWhenTesting;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Catatan servis kendaraan (fitur Pro). Interval berulang (`interval_months`
 * dan/atau `interval_km`) dihitung server menjadi `next_due_date`/`next_due_km`
 * saat store/update — sumber jadwal pengingat servis di mobile.
 */
class ServiceLog extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;
    use UsesDefaultConnectionWhenTesting;

    protected $connection = 'ev'; // Use the sagansa database connection

    protected $fillable = [
        'vehicle_id',
        'user_id',
        'date',
        'odometer_km',
        'service_type',
        'workshop',
        'cost_rp',
        'notes',
        'interval_months',
        'interval_km',
        'next_due_date',
        'next_due_km',
    ];

    protected $casts = [
        'date' => 'date',
        'odometer_km' => 'integer',
        'cost_rp' => 'integer',
        'interval_months' => 'integer',
        'interval_km' => 'integer',
        'next_due_date' => 'date',
        'next_due_km' => 'integer',
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Hitung next_due dari interval. Return hanya field yang berubah —
     * interval kosong → next_due null (tidak ada pengingat).
     */
    public static function computeNextDue(?string $date, $odometerKm, $intervalMonths, $intervalKm): array
    {
        return [
            'next_due_date' => ($date && $intervalMonths)
                ? \Illuminate\Support\Carbon::parse($date)->addMonths((int) $intervalMonths)->toDateString()
                : null,
            'next_due_km' => ($odometerKm !== null && $intervalKm)
                ? (int) $odometerKm + (int) $intervalKm
                : null,
        ];
    }
}
