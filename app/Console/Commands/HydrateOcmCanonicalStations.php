<?php

namespace App\Console\Commands;

use App\Services\CanonicalStationHydrateService;
use Illuminate\Console\Command;

class HydrateOcmCanonicalStations extends Command
{
    protected $signature = 'ocm:hydrate-canonical';

    protected $description = 'Hydrate tabel kanonik charging_stations dari POI OpenChargeMap (openchargemap_pois). Idempoten — aman dijalankan berulang.';

    public function handle(CanonicalStationHydrateService $service): int
    {
        $this->info('Hydrate charging_stations dari OpenChargeMap...');

        try {
            $stats = $service->hydrateFromOcm();
        } catch (\Throwable $e) {
            $this->error('Hydrate gagal: '.$e->getMessage());

            return 1;
        }

        $this->newLine();
        $this->info('✓ Hydrate selesai.');
        $this->table(
            ['Keterangan', 'Jumlah'],
            [
                ['POI diproses', $stats['processed']],
                ['Dibuat', $stats['created']],
                ['Diupdate', $stats['updated']],
                ['Tidak berubah', $stats['unchanged']],
                ['Dilewati (tidak layak)', $stats['skipped']],
                ['Child charger ditulis', $stats['chargers']],
                ['Stasiun stale di-prune', $stats['pruned']],
            ]
        );

        return 0;
    }
}
