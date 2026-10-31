<?php

namespace App\Console\Commands;

use App\Services\OcmOperatorMatchService;
use Illuminate\Console\Command;

/**
 * Impor operator OpenChargeMap dari openchargemap_pois + entity-resolution
 * ke providers (auto-match guessProvider, sisanya auto-provision).
 * Jalankan setelah ocm:sync / ocm:import agar provider siap dipakai
 * ocm:hydrate-canonical.
 */
class ImportOcmOperators extends Command
{
    protected $signature = 'ocm:import-operators
                            {--refresh-reference : Paksa tarik ulang katalog /v3/referencedata/}';

    protected $description = 'Match & provision operator OpenChargeMap ke providers (entity-resolution)';

    public function handle(OcmOperatorMatchService $service): int
    {
        $this->info('Match operator OpenChargeMap → providers...');

        try {
            $stats = $service->importFromPois((bool) $this->option('refresh-reference'));
        } catch (\Throwable $e) {
            $this->error('Import gagal: '.$e->getMessage());

            return 1;
        }

        if ($stats['reference_error'] !== null) {
            $this->warn('Katalog referencedata gagal diperbarui: '.$stats['reference_error'].' — memakai snapshot lama bila ada.');
        }

        $this->newLine();
        $this->info('✓ Selesai.');
        $this->table(
            ['Keterangan', 'Jumlah'],
            [
                ['Operator dipindai', $stats['scanned']],
                ['Match ke provider ada', $stats['matched']],
                ['Provider baru di-provision', $stats['provisioned']],
                ['Kolom operator POI di-backfill', $stats['backfilled_operator']],
                ['Dilewati (noise)', $stats['skipped_noise']],
                ['Dilewati (sudah ada match)', $stats['skipped_existing']],
            ]
        );

        return 0;
    }
}
