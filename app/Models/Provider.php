<?php

namespace App\Models;

use App\Models\Concerns\NormalizesLoopbackMediaUrl;
use App\Models\Concerns\UsesDefaultConnectionWhenTesting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Provider extends Model
{
    use NormalizesLoopbackMediaUrl;
    use UsesDefaultConnectionWhenTesting;

    use HasUuids;
    use HasFactory;

    protected $connection = 'ev'; // Use the sagansa database connection

    protected $fillable = [
        'id',
        'name',
        'image',
        'status',
        'contact',
        'address',
        'province_id',
        'city_id',
        'district_id',
        'subdistrict_id',
        'postal_code_id',
    ];

    public function chargerLocations()
    {
        return $this->hasMany(ChargerLocation::class);
    }

    public function spkluLocations()
    {
        return $this->hasMany(SpkluLocation::class);
    }

    protected function image(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $this->resolveMediaPath($value),
            set: fn ($value) => $this->sanitizeImagePath($value),
        );
    }

    protected function logo(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $this->resolveMediaPath($value) ?? $this->image ?? asset('/images/ev-charging.png'),
        );
    }

    protected function markerIcon(): Attribute
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

        $trimmed = static::withoutLoopbackOrigin($trimmed) ?? $trimmed;

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

    protected function resolveMediaPath($path): ?string
    {
        if (!is_string($path)) {
            return null;
        }

        $trimmed = trim($path);
        if ($trimmed === '') {
            return null;
        }

        $trimmed = static::withoutLoopbackOrigin($trimmed) ?? $trimmed;

        // Full URLs (any external host) — return as-is
        if (Str::startsWith($trimmed, ['http://', 'https://', '//'])) {
            return $trimmed;
        }

        $normalized = ltrim($trimmed, '/');

        if ($normalized === '') {
            return null;
        }

        if (Str::startsWith($normalized, ['storage/'])) {
            return '/' . $normalized;
        }

        if (Str::startsWith($normalized, ['public/'])) {
            $normalized = Str::after($normalized, 'public/');
        } elseif (Str::startsWith($normalized, ['app/public/'])) {
            $normalized = Str::after($normalized, 'app/public/');
        }

        $storageCandidates = [
            $normalized,
        ];

        if (Str::startsWith($normalized, 'storage/')) {
            $storageCandidates[] = Str::after($normalized, 'storage/');
        }

        $storageCandidates = array_values(array_unique(array_filter(
            $storageCandidates,
            fn ($value) => $value !== null && $value !== ''
        )));

        foreach ($storageCandidates as $candidate) {
            if (!$candidate) {
                continue;
            }

            if (Storage::disk('public')->exists($candidate)) {
                return '/storage/' . ltrim($candidate, '/');
            }
        }

        if (Str::startsWith($trimmed, ['/'])) {
            return $trimmed;
        }

        return '/storage/' . ltrim($normalized, '/');
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
}
