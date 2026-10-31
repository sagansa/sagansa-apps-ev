<?php

namespace App\Console\Commands;

use App\Services\OcmReferenceDataService;
use App\Services\OcmSyncService;
use App\Services\OcmTileSyncService;
use Illuminate\Console\Command;

/**
 * Sinkronisasi POI OpenChargeMap dari API v3 langsung (port
 * tools/fetch_openchargemap.py — tool Python tetap dipertahankan utk
 * backfill manual). Upsert ke openchargemap_pois by ocm_id via
 * OcmHarvestService::upsertPoi; state incremental di ocm_sync_state.
 *
 * Tiga mode:
 *  - default            : incremental per negara (OCM_COUNTRIES / --country)
 *  - --all-countries    : incremental SEMUA negara di katalog referencedata
 *  - --tile             : bootstrap seluruh dunia via quadtree bbox (sekali
 *                         awal / --full ulang) — menembus limit ~1000 POI per
 *                         query karena paginasi offset OCM rusak.
 */
class OcmSyncOpenchargemap extends Command
{
    protected $signature = 'ocm:sync
                            {--country= : Kode negara ISO-2 / csv (default: semua dari services.openchargemap.countries)}
                            {--all-countries : Incremental semua negara di katalog referencedata (±250)}
                            {--tile : Bootstrap seluruh dunia via quadtree bbox (abaikan modifiedsince)}
                            {--full : Paksa full fetch (abaikan modifiedsince)}
                            {--maxresults=1000 : Jumlah POI per halaman (maks 1000)}
                            {--delay=1.5 : Jeda antar halaman/tile dalam detik}
                            {--backoff-init=30 : Backoff awal saat HTTP 429 dalam detik}
                            {--backoff-max=300 : Backoff maksimum saat HTTP 429 dalam detik}
                            {--tile-lat-step=10 : Ukuran tile awal: step latitude (derajat)}
                            {--tile-lon-step=20 : Ukuran tile awal: step longitude (derajat)}
                            {--tile-maxresults=1000 : Cap per tile — penuh berarti subdivisi}
                            {--tile-depth=8 : Kedalaman subdivisi maksimum}';

    protected $description = 'Sync POI OpenChargeMap ke openchargemap_pois (per negara / all-countries / bootstrap tiling world)';

    public function handle(): int
    {
        $key = config('services.openchargemap.key');
        if (empty($key)) {
            $this->error('OCM_API_KEY belum diisi — daftar gratis di openchargemap.org lalu set env OCM_API_KEY.');

            return self::FAILURE;
        }

        if ($this->option('tile')) {
            return $this->handleTileBootstrap();
        }

        $countries = null;

        if ($this->option('all-countries')) {
            $countries = array_values(array_unique(array_values(
                app(OcmReferenceDataService::class)->countryIsoMap()
            )));

            // Fase sebelum bootstrap world (katalog belum diisi) — entri
            // scheduler harian tidak boleh gagal: jatuh ke OCM_COUNTRIES.
            if ($countries === []) {
                $this->warn('Katalog negara kosong (jalankan: php artisan ocm:import-operators --refresh-reference) — memakai OCM_COUNTRIES.');
                $countries = null;
            }
        }

        if ($countries === null) {
            $countries = array_filter(array_map(
                'strtoupper',
                array_map('trim', explode(',', (string) ($this->option('country') ?: config('services.openchargemap.countries', 'ID'))))
            ));
        }

        if ($countries === []) {
            $this->error('Tidak ada negara untuk di-sync.');

            return self::FAILURE;
        }

        $service = app(OcmSyncService::class, [
            'pageDelaySec' => (float) $this->option('delay'),
            'backoffInitialSec' => (int) $this->option('backoff-init'),
            'backoffMaxSec' => (int) $this->option('backoff-max'),
        ]);

        $failures = 0;
        foreach (array_values($countries) as $country) {
            $this->info("Sync OpenChargeMap country={$country} ...");

            $stats = $service->syncCountry(
                $country,
                (bool) $this->option('full'),
                max(1, min(1000, (int) $this->option('maxresults'))),
                function (array $p): void {
                    $this->line(sprintf('  offset=%5d  +%d  (total %d)', $p['offset'], $p['batch'], $p['total']));
                }
            );

            if ($stats['error'] !== null) {
                $this->error("  ✗ {$country}: {$stats['error']}");
                $failures++;

                continue;
            }

            $stopped = $stats['stopped_reason'] !== null
                ? ' (stop: '.$stats['stopped_reason'].')'
                : '';
            $this->info(sprintf(
                '  ✓ %s: mode=%s fetched=%d baru=%d update=%d%s',
                $country,
                $stats['mode'],
                $stats['fetched'],
                $stats['inserted'],
                $stats['updated'],
                $stopped
            ));
        }

        return $failures === count($countries) ? self::FAILURE : self::SUCCESS;
    }

    private function handleTileBootstrap(): int
    {
        $this->info('Bootstrap POI OpenChargeMap seluruh dunia (quadtree bbox tiling) ...');
        $this->line('  Perkiraan: ±300–800 request, 15–30 menit dgn delay default; aman dihentikan & diulang (idempoten).');

        $service = app(OcmTileSyncService::class, [
            'latStep' => (float) $this->option('tile-lat-step'),
            'lonStep' => (float) $this->option('tile-lon-step'),
            'maxDepth' => (int) $this->option('tile-depth'),
            'tileMaxResults' => max(1, min(1000, (int) $this->option('tile-maxresults'))),
            'requestDelaySec' => (float) $this->option('delay'),
            'backoffInitialSec' => (int) $this->option('backoff-init'),
            'backoffMaxSec' => (int) $this->option('backoff-max'),
        ]);

        $stats = $service->bootstrapWorld(
            function (array $t): void {
                $this->line(sprintf(
                    '  tile=%4d depth=%d batch=%4d (unik %d)',
                    $t['tiles'],
                    $t['depth'],
                    $t['batch'],
                    $t['unique']
                ));
            }
        );

        if ($stats['error'] !== null) {
            $this->error('✗ Bootstrap gagal: '.$stats['error']);

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('✓ Bootstrap selesai.');
        $this->table(
            ['Keterangan', 'Nilai'],
            [
                ['Request', $stats['requests']],
                ['Tile diproses (subdivisi)', $stats['tiles'].' ('.$stats['subdivided'].')'],
                ['POI unik tersimpan', $stats['unique'].' (baru '.$stats['inserted'].', update '.$stats['updated'].')'],
                ['Dilewati (negara tak dikenal)', $stats['skipped_unknown_country']],
                ['Negara tercakup', count($stats['countries'])],
            ]
        );

        return self::SUCCESS;
    }
}
