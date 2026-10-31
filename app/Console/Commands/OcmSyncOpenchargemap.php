<?php

namespace App\Console\Commands;

use App\Services\OcmSyncService;
use Illuminate\Console\Command;

/**
 * Sinkronisasi POI OpenChargeMap dari API v3 langsung per negara (port
 * tools/fetch_openchargemap.py — tool Python tetap dipertahankan utk
 * backfill manual). Upsert ke openchargemap_pois by ocm_id via
 * OcmHarvestService::upsertPoi; state incremental di ocm_sync_state.
 */
class OcmSyncOpenchargemap extends Command
{
    protected $signature = 'ocm:sync
                            {--country= : Kode negara ISO-2 / csv (default: semua dari services.openchargemap.countries)}
                            {--full : Paksa full fetch (abaikan modifiedsince)}
                            {--maxresults=1000 : Jumlah POI per halaman (maks 1000)}
                            {--delay=1.5 : Jeda antar halaman dalam detik}
                            {--backoff-init=30 : Backoff awal saat HTTP 429 dalam detik}
                            {--backoff-max=300 : Backoff maksimum saat HTTP 429 dalam detik}';

    protected $description = 'Sync POI OpenChargeMap per negara ke openchargemap_pois (incremental via modifiedsince)';

    public function handle(): int
    {
        $key = config('services.openchargemap.key');
        if (empty($key)) {
            $this->error('OCM_API_KEY belum diisi — daftar gratis di openchargemap.org lalu set env OCM_API_KEY.');

            return self::FAILURE;
        }

        $countries = array_filter(array_map(
            'strtoupper',
            array_map('trim', explode(',', (string) ($this->option('country') ?: config('services.openchargemap.countries', 'ID'))))
        ));
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
}
