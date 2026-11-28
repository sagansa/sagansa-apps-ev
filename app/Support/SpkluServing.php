<?php

namespace App\Support;

use App\Models\ChargingStation;
use App\Services\CanonicalStationHydrateService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared query & marker helpers untuk SPKLU serving.
 *
 * Dipakai oleh SpkluLocationController (API) dan ExportAppSeed (seed generator)
 * agar query & marker identik — tidak ada drift format antar sumber.
 *
 * Komposisi serving (applyComposition) dipakai baseQuery + markers +
 * data_version SEKALIGUS — marker count selalu = baris tersaji (jaring
 * pengaman delta-below-count klien tidak terpicu palsu).
 */
class SpkluServing
{
    /**
     * Komposisi kanonik yang di-serving mobile:
     *   sumber utama (config spklu.serving_source, default pln) MINUS slice
     *   prefix master yang di-override data fetch provider (config
     *   spklu.pln_slice_prefixes, mis. "(VOLTRON)"), PLUS stasiun hasil
     *   adaptor fetch (source='voltron' dst.).
     */
    public static function applyComposition(Builder $query): Builder
    {
        $serving = config('spklu.serving_source');
        $prefixes = (array) config('spklu.pln_slice_prefixes', []);

        return $query->where(function ($q) use ($serving, $prefixes) {
            $q->where(function ($utama) use ($serving, $prefixes) {
                $utama->where('source', $serving);
                foreach ($prefixes as $prefix) {
                    $utama->where('nama_lokasi', 'not like', $prefix.'%');
                }
            });
            $q->orWhere('source', CanonicalStationHydrateService::SOURCE_VOLTRON);
        });
    }

    /**
     * Base query SPKLU — tanpa filter user (search, provinsi, radius, dst).
     * Dipakai SpkluLocationController::index() (filter user ditambahkan
     * di atasnya) dan ExportAppSeed (seed generator).
     *
     * @return Builder<ChargingStation>
     */
    public static function baseQuery(): Builder
    {
        return self::applyComposition(
            ChargingStation::with(['chargerBoxes.connectors', 'provider'])
        )
            ->whereNotNull('latitude')
            ->whereNotNull('longitude');
    }

    /**
     * Marker sinkronisasi delta klien — hanya berubah saat ada penambahan/
     * penghapusan lokasi atau edit master data (bukan perubahan status
     * availabilitas). Nilai timestamp dari DB (naive UTC) di-normalize ke
     * ISO-8601 UTC.
     *
     * Filter komposisi SAMA dengan [baseQuery] (applyComposition) dan filter
     * koordinat not-null juga SAMA — count harus sama dengan jumlah baris
     * yang benar-benar disajikan/di-seed, kalau tidak jaring pengaman
     * delta-below-count klien memicu full fetch permanen saat ada baris
     * ber-koordinat null di tabel.
     *
     * Dipindai dari SpkluLocationController::computeSyncMarkers() (baris 136–152).
     *
     * @return array{count: int, max_created_at: string|null, max_updated_at: string|null, data_version: string}
     */
    public static function computeSyncMarkers(): array
    {
        $stats = self::applyComposition(ChargingStation::query())
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
            // Sensor drift identitas dataset (termasuk PENGHAPUSAN baris):
            // md5(count|max_updated) berubah bila ada baris dihapus/ditambah/diedit,
            // bahkan bila marker lain kebetulan tetap. Klien (SpkluRepository)
            // membandingkan dgn SpkluCacheMeta.dataVersion → beda = full fetch
            // (purge ghost station). Tanpa filter lat/lng — probe identitas saja,
            // tapi TETAP memakai komposisi serving (applyComposition) yang sama
            // dgn baseQuery/markers: slice yang di-exclude & titik voltron yang
            // di-include ikut menggeser data_version (full fetch klien).
            'data_version' => self::computeDataVersion(),
        ];
    }

    /**
     * Versi dataset — dipakai mobile utk memutuskan perlu fetch penuh atau tidak.
     * Format: md5(count + '|' + max(updated_at))
     *
     * Tanpa filter lat/lng — probe identitas saja, namun komposisi serving
     * (applyComposition) SAMA dgn [baseQuery] & [computeSyncMarkers] agar
     * perubahan komposisi (slice masuk/keluar, titik fetch baru) terlihat klien.
     *
     * Dipindai dari SpkluLocationController::computeDataVersion() (baris 117–126).
     */
    public static function computeDataVersion(): string
    {
        $stats = self::applyComposition(ChargingStation::query())
            ->selectRaw('COUNT(*) as cnt, MAX(updated_at) as latest')
            ->first();

        $raw = ($stats->cnt ?? 0) . '|' . ($stats->latest ?? '');

        return md5($raw);
    }
}
