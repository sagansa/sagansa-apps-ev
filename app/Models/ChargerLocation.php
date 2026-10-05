<?php

namespace App\Models;

use App\Models\Concerns\UsesDefaultConnectionWhenTesting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Str;

class ChargerLocation extends Model
{
    use UsesDefaultConnectionWhenTesting;

    use HasUuids;
    use HasFactory;
    use SoftDeletes;

    protected $connection = 'ev'; // Use the sagansa database connection

    protected $fillable = [
        'image',
        'name',
        'is_rest_area',
        'provider_id',
        'location_on',
        'status',
        'description',
        'latitude',
        'longitude',
        'parking',
        'address',
        'province_id',
        'city_id',
        'province_name',
        'city_name',
        'district_id',
        'subdistrict_id',
        'postal_code_id',
        'user_id',
        'data_source',
        'verification_status',
        'master_location_id',
        'is_master',
        'verified_by',
        'verified_at',
        // Soft-link station All Map (charging_stations) — hasil pindahan data
        // saat sesi mobile pertama kali mencatat lokasi ini.
        'charging_station_id',
    ];

    protected $appends = [
        'name',
        'location',
    ];

    protected $withCount = ['charges'];

    protected function image(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $this->resolveMediaPath($value),
            set: fn ($value) => $this->sanitizeImagePath($value),
        );
    }

    protected function sanitizeImagePath($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || in_array($trimmed, ['/images/ev-charging.png', 'images/ev-charging.png', '/images/ev-station.png', 'images/ev-station.png'])) {
            return null;
        }

        if (Str::startsWith($trimmed, ['http://', 'https://', '//'])) {
            return $trimmed;
        }

        $normalized = ltrim($trimmed, '/');

        if (Str::startsWith($normalized, 'storage/')) {
            $normalized = Str::after($normalized, 'storage/');
        }

        if (Str::startsWith($normalized, 'public/')) {
            $normalized = Str::after($normalized, 'public/');
        } elseif (Str::startsWith($normalized, 'app/public/')) {
            $normalized = Str::after($normalized, 'app/public/');
        }

        $normalized = ltrim($normalized, '/');

        return $normalized !== '' ? $normalized : null;
    }

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function province()
    {
        return $this->belongsTo(Province::class);
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function district()
    {
        return $this->belongsTo(District::class);
    }

    public function subdistrict()
    {
        return $this->belongsTo(Subdistrict::class);
    }

    public function postalCode()
    {
        return $this->belongsTo(PostalCode::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function chargers()
    {
        return $this->hasMany(Charger::class);
    }

    public function charges()
    {
        return $this->hasMany(Charge::class);
    }

    public function discountHomeChargings()
    {
        return $this->hasMany(DiscountHomeCharging::class);
    }

    public function locationReports()
    {
        return $this->hasMany(LocationReport::class, 'location_id');
    }

    public function locationUpdates()
    {
        return $this->hasMany(LocationUpdate::class, 'location_id');
    }

    public function duplicationsAsPrimary()
    {
        return $this->hasMany(LocationDuplication::class, 'primary_location_id');
    }

    public function duplicationsAsDuplicate()
    {
        return $this->hasMany(LocationDuplication::class, 'duplicate_location_id');
    }

    public function auditLogs()
    {
        return $this->hasMany(LocationAuditLog::class, 'location_id');
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by'); // This will work even with the ID type mismatch
    }

    public function masterLocation()
    {
        return $this->belongsTo(ChargerLocation::class, 'master_location_id');
    }

    public function childLocations()
    {
        return $this->hasMany(ChargerLocation::class, 'master_location_id');
    }

    // Define the accessor
    public function getNameAttribute()
    {
        // Return the name attribute or any transformation you need
        return $this->attributes['name'] ?? 'Default Name';
    }

    public function getLocationAttribute(): array
    {
        return [
            "lat" => (float)$this->latitude,
            "lng" => (float)$this->longitude,
        ];
    }

    public function setLocationAttribute(array|string|null $location): void
    {
        if (is_array($location)) {
            $this->attributes['latitude'] = $location['lat'] ?? $location['latitude'] ?? null;
            $this->attributes['longitude'] = $location['lng'] ?? $location['longitude'] ?? null;
            unset($this->attributes['location']);

            return;
        }

        if (is_string($location) && str_contains($location, ',')) {
            [$latitude, $longitude] = array_map('trim', explode(',', $location, 2));

            $this->attributes['latitude'] = $latitude;
            $this->attributes['longitude'] = $longitude;
            unset($this->attributes['location']);
        }
    }

    public function getCoordinateAttribute()
    {
        return number_format($this->latitude, 5) . ', ' . number_format($this->longitude, 5);
    }

    public static function getComputedLocation(): string
    {
        return 'location';
    }

    public function getChargerLocationNameAttribute()
    {
        return $this->provider->name . ' - ' . $this->name;
    }

    protected function resolveMediaPath($path): ?string
    {
        if (!is_string($path)) {
            return null;
        }

        $trimmed = trim($path);
        if ($trimmed === '') {
            return null;
        }

        if (Str::startsWith($trimmed, ['http://', 'https://'])) {
            return $trimmed;
        }

        if (Str::startsWith($trimmed, ['/'])) {
            return $trimmed;
        }

        if (Str::startsWith($trimmed, ['storage/'])) {
            return '/' . ltrim($trimmed, '/');
        }

        return '/storage/' . ltrim($trimmed, '/');
    }

    /**
     * Scope to get charger locations near a given point, ordered by distance
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param float $latitude
     * @param float $longitude
     * @param int $distanceInKm
     * @return \Illuminate\Database\Query\Builder
     */
    public function scopeNear($query, $latitude, $longitude, $distanceInKm = 10)
    {
        return $query->select('charger_locations.*')
        ->selectRaw("
            (6371 * acos(
                cos(radians(?)) *
                cos(radians(latitude)) *
                cos(radians(longitude) - radians(?)) +
                sin(radians(?)) *
                sin(radians(latitude))
            )) AS distance
        ", [$latitude, $longitude, $latitude])
        ->whereRaw("
            (6371 * acos(
                cos(radians(?)) *
                cos(radians(latitude)) *
                cos(radians(longitude) - radians(?)) +
                sin(radians(?)) *
                sin(radians(latitude))
            )) < ?", [$latitude, $longitude, $latitude, $distanceInKm])
        ->orderBy('distance');
    }
}
