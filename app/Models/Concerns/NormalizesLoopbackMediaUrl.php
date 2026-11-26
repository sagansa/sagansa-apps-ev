<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

trait NormalizesLoopbackMediaUrl
{
    /**
     * URL absolut yang hostnya loopback (sisa APP_URL=http://localhost saat data dibuat)
     * dikembalikan sebagai path relatifnya; nilai lain → null (biarkan pemanggil memutuskan).
     *
     * Kalau lolos begitu saja, browser pengunjung meminta file dari perangkat pengunjung
     * sendiri dan Chrome memunculkan prompt "wants to access other apps and services on
     * this device" (izin loopback) saat halaman dimuat.
     */
    protected static function withoutLoopbackOrigin(string $url): ?string
    {
        if (! Str::startsWith($url, ['http://', 'https://', '//'])) {
            return null;
        }

        if (! in_array(parse_url($url, PHP_URL_HOST), ['localhost', '127.0.0.1'], true)) {
            return null;
        }

        return parse_url($url, PHP_URL_PATH) ?: null;
    }
}
