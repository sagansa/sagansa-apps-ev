<?php

namespace App\Console\Commands;

use App\Services\OcmHarvestService;
use Illuminate\Console\Command;

/**
 * Import bulk POI OpenChargeMap dari file JSON normalisasi
 * (default: ../data/spklu_points_openchargemap.json — hasil
 * tools/fetch_openchargemap.py + tools/build_spklu_points.py).
 *
 * Upsert by ocm_id; --replace menghapus POI yang tidak ada di file sumber.
 * Mapper memakai OcmHarvestService agar bentuk data import & harvest identik.
 */
class ImportOpenchargemap extends Command
{
    protected $signature = 'ocm:import
                            {file? : Path JSON (default ../data/spklu_points_openchargemap.json)}
                            {--replace : Hapus POI yang tidak ada di file sumber}';

    protected $description = 'Import POI OpenChargeMap dari JSON ke tabel openchargemap_pois (upsert by ocm_id)';

    public function handle(OcmHarvestService $harvest): int
    {
        $filePath = $this->argument('file')
            ?? base_path('../data/spklu_points_openchargemap.json');

        if (! file_exists($filePath)) {
            $this->error("File tidak ditemukan: {$filePath}");

            return Command::FAILURE;
        }

        $json = json_decode((string) file_get_contents($filePath), true);
        if (! is_array($json)) {
            $this->error('JSON tidak valid.');

            return Command::FAILURE;
        }

        $sourceIds = [];
        foreach (($json['points'] ?? []) as $point) {
            $id = (int) ($point['external_id'] ?? $point['id'] ?? $point['ID'] ?? 0);
            if ($id > 0) {
                $sourceIds[] = $id;
            }
        }
        $this->info('POI di file sumber: '.count($sourceIds));

        $result = $harvest->harvestFromLocalFile($filePath);

        $deleted = 0;
        if ($this->option('replace') && $sourceIds !== []) {
            $deleted = $this->pruneMissing($sourceIds);
        }

        $total = \App\Models\OpenchargemapPoi::count();
        $this->table(
            ['Keterangan', 'Nilai'],
            [
                ['Diambil dari file', $result['fetched'] ?? 0],
                ['Baru diinsert', $result['inserted'] ?? 0],
                ['Diupdate', $result['updated'] ?? 0],
                ['Dihapus (--replace)', $deleted],
                ['Error', $result['error'] ?? '-'],
                ['Total openchargemap_pois', $total],
            ]
        );

        return Command::SUCCESS;
    }

    private function pruneMissing(array $sourceIds): int
    {
        $deleted = 0;
        \App\Models\OpenchargemapPoi::query()
            ->whereNotIn('ocm_id', $sourceIds)
            ->chunkById(500, function ($pois) use (&$deleted) {
                $deleted += $pois->count();
                foreach ($pois as $poi) {
                    $poi->delete();
                }
            });

        return $deleted;
    }
}
