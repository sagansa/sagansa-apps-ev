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
        'service_items',
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
        'service_items' => 'array',
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

    public function photos()
    {
        return $this->hasMany(ServiceLogPhoto::class);
    }

    /**
     * Label tampilan: item pertama service_items, fallback service_type lama,
     * terakhir label generik. Dipakai reminder engine bila menyentuh service_type.
     */
    public function displayLabel(): string
    {
        $items = $this->service_items;
        if (is_string($items)) {
            $items = json_decode($items, true);
        }
        if (is_array($items) && count($items) > 0 && is_string($items[0]) && trim($items[0]) !== '') {
            return trim($items[0]);
        }
        if (! empty($this->service_type)) {
            return $this->service_type;
        }

        return 'servis';
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
