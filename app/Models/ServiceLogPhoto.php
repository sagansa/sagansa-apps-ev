<?php

namespace App\Models;

use App\Models\Concerns\UsesDefaultConnectionWhenTesting;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Foto servis kendaraan (Pro-only). `path` relatif di disk `public`
 * (mis. "service-logs/{uuid}/hash.jpg"). URL publik "/storage/...".
 */
class ServiceLogPhoto extends Model
{
    use HasUuids;
    use UsesDefaultConnectionWhenTesting;

    protected $connection = 'ev';

    public $timestamps = false;

    protected $fillable = [
        'service_log_id',
        'path',
    ];

    public function serviceLog()
    {
        return $this->belongsTo(ServiceLog::class);
    }

    public function url(): string
    {
        $path = $this->path;
        if (preg_match('#^https?://#', $path) || str_starts_with($path, '/')) {
            return $path;
        }

        return '/storage/' . ltrim($path, '/');
    }

    protected static function booted(): void
    {
        static::forceDeleted(function (self $photo) {
            if ($photo->path && Storage::disk('public')->exists($photo->path)) {
                Storage::disk('public')->delete($photo->path);
            }
        });
    }
}
