<?php

namespace App\Support;

use App\Models\ChargingStation;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared query & marker helpers untuk SPKLU serving.
 *
 * Dipakai oleh SpkluLocationController (API) dan ExportAppSeed (seed generator)
 * agar query & marker identik — tidak ada drift format antar sumber.
 */
class SpkluServing
{
    /**
     * Base query SPKLU — tanpa filter user (search, provinsi, radius, dst).
     * Identik dengan baris 20–23 SpkluLocationController::index().
     *
     * @return Builder<ChargingStation>
     */
    public static function baseQuery(): Builder
    {
        return ChargingStation::with(['chargerBoxes.connectors', 'provider'])
            ->where('source', config('spklu.serving_source'))
            ->whereNotNull('latitude')
            ->whereNotNull('longitude');
    }

    /**
     * Marker sinkronisasi delta klien — hanya berubah saat ada penambahan/
     * penghapusan lokasi atau edit master data (bukan perubahan status
     * availabilitas). Nilai timestamp dari DB (naive UTC) di-normalize ke
     * ISO-8601 UTC.
     *
     * Filter koordinat not-null SAMA dengan [baseQuery] — count harus sama
     * dengan jumlah baris yang benar-benar disajikan/di-seed, kalau tidak
     * jaring pengaman delta-below-count klien memicu full fetch permanen
     * saat ada baris ber-koordinat null di tabel.
     *
     * Dipindai dari SpkluLocationController::computeSyncMarkers() (baris 136–152).
     *
     * @return array{count: int, max_created_at: string|null, max_updated_at: string|null}
     */
    public static function computeSyncMarkers(): array
    {
        $stats = ChargingStation::query()
            ->where('source', config('spklu.serving_source'))
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->selectRaw('COUNT(*) AS cnt, MAX(created_at) AS max_created, MAX(updated_at) AS max_updated')
            ->first();

        return [
            'count' => (int) ($stats->cnt ?? 0),
            'max_created_at' => $stats->max_created
                ? \Illuminate\Support\Carbon::rawParse($stats->max_created, 'UTC')->toISOString()
                : null,
            'max_updated_at' => $stats->max_updated
                ? \Illuminate\Support\Carbon::rawParse($stats->max_updated, 'UTC')->toISOString()
                : null,
        ];
    }

    /**
     * Versi dataset — dipakai mobile utk memutuskan perlu fetch penuh atau tidak.
     * Format: md5(count + '|' + max(updated_at))
     *
     * Dipindai dari SpkluLocationController::computeDataVersion() (baris 117–126).
     */
    public static function computeDataVersion(): string
    {
        $stats = ChargingStation::where('source', config('spklu.serving_source'))
            ->selectRaw('COUNT(*) as cnt, MAX(updated_at) as latest')
            ->first();

        $raw = ($stats->cnt ?? 0) . '|' . ($stats->latest ?? '');

        return md5($raw);
    }
}
