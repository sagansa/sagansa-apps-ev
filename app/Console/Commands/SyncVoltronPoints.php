<?php

namespace App\Console\Commands;

use App\Services\VoltronSyncService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Ingest snapshot fetch Voltron → voltron_points + hydrate kanonik
 * (source='voltron'). Jalur CLI manual — jalur otomatis memakai endpoint
 * /api/v1/internal/spklu/voltron-import yang memanggil service yang sama.
 *
 * Replace-semantics: titik yang hilang dari snapshot dihapus; sanity gate
 * 80% menolak penurunan drastis (lihat spec ingest Voltron).
 */
class SyncVoltronPoints extends Command
{
    protected $signature = 'spklu:sync-voltron
                            {file? : Path snapshot (default: ../data/spklu_points_voltron.json)}';

    protected $description = 'Import snapshot fetch Voltron ke voltron_points + hydrate charging_stations (source=voltron)';

    public function handle(VoltronSyncService $service): int
    {
        $filePath = $this->argument('file')
            ?? base_path('../data/spklu_points_voltron.json');

        if (! file_exists($filePath)) {
            $this->error("File tidak ditemukan: {$filePath}");

            return Command::FAILURE;
        }

        $snapshot = json_decode((string) file_get_contents($filePath), true);
        if (! is_array($snapshot)) {
            $this->error('JSON tidak valid.');

            return Command::FAILURE;
        }

        $this->info("Meng-ingest snapshot Voltron: {$filePath}");

        try {
            $summary = $service->sync($snapshot);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->error("{$field}: ".implode('; ', $messages));
            }

            return Command::FAILURE;
        }

        $this->newLine();
        $this->info('Sync Voltron selesai!');
        $this->table(
            ['Keterangan', 'Nilai'],
            [
                ['Import — baru', $summary['import']['imported']],
                ['Import — update', $summary['import']['updated']],
                ['Import — dihapus (hilang dari snapshot)', $summary['import']['pruned']],
                ['Hydrate — dibuat', $summary['hydrate']['created']],
                ['Hydrate — diupdate', $summary['hydrate']['updated']],
                ['Hydrate — tidak berubah', $summary['hydrate']['unchanged']],
                ['Hydrate — di-prune (stale)', $summary['hydrate']['pruned']],
            ]
        );

        return Command::SUCCESS;
    }
}
