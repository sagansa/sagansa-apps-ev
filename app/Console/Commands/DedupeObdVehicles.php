<?php

namespace App\Console\Commands;

use App\Models\ObdVehicle;
use App\Services\ObdVehicleDedupeReport;
use App\Services\ObdVehicleDedupeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Gabungkan kendaraan OBD2 ganda & tautkan entri lama ke kendaraan garasi
 * (2026-11-24 — feedback owner: tiap kendaraan tampil dua kali di pemilih
 * Cockpit). Aturan lengkap ada di [ObdVehicleDedupeService].
 *
 *   php artisan obd:dedupe-vehicles --dry-run       # lihat rencana, tanpa mengubah data
 *   php artisan obd:dedupe-vehicles                  # terapkan (cadangan JSON otomatis)
 *   php artisan obd:dedupe-vehicles --user=12        # hanya satu user
 *
 * Sebelum mengubah data, SEMUA baris user yang tersentuh dicadangkan ke
 * `storage/app/obd-vehicles-backup-<waktu>.json` (isi kolom mentah, id asli
 * dipertahankan) — pakai --no-backup untuk melewatkannya.
 */
class DedupeObdVehicles extends Command
{
    protected $signature = 'obd:dedupe-vehicles
        {--dry-run : Tampilkan rencana tanpa mengubah data}
        {--user=* : Batasi ke user id tertentu (boleh lebih dari satu)}
        {--no-backup : Jangan menulis cadangan JSON kondisi awal sebelum mengubah data}';

    protected $description = 'Gabungkan kendaraan OBD2 ganda & tautkan entri lama ke kendaraan garasi (satu garasi = satu entri)';

    public function handle(ObdVehicleDedupeService $service): int
    {
        $userIds = [];
        foreach ((array) $this->option('user') as $value) {
            if ($value !== null && $value !== '') {
                $userIds[] = (int) $value;
            }
        }
        $dryRun = (bool) $this->option('dry-run');
        $scopes = $userIds === [] ? [null] : $userIds;

        // Rencana dihitung lebih dulu (alur yang sama di dalam transaksi yang
        // dikembalikan) — sekaligus menentukan user yang barisnya dicadangkan.
        $reports = array_map(fn (?int $id) => $service->dedupe($id, dryRun: true), $scopes);

        if ($this->countLinked($reports) === 0 && $this->countRemoved($reports) === 0) {
            $this->info('Tidak ada kendaraan OBD2 ganda'
                .($userIds === [] ? '' : ' untuk user '.implode(', ', $userIds)).'.');

            return self::SUCCESS;
        }

        $this->table(['User', 'Aksi', 'Detail'], $this->planRows($reports));

        if ($dryRun) {
            $this->warn('DRY-RUN — tidak ada data yang diubah. Jalankan tanpa --dry-run untuk menerapkan.');

            return self::SUCCESS;
        }

        if (! $this->option('no-backup')) {
            $this->line('Cadangan kondisi awal: '.$this->writeBackup($reports));
        }

        $done = array_map(fn (?int $id) => $service->dedupe($id, dryRun: false), $scopes);
        $this->info(sprintf(
            'Selesai: %d entri lama ditautkan ke garasi, %d baris ganda digabung.',
            $this->countLinked($done),
            $this->countRemoved($done),
        ));

        return self::SUCCESS;
    }

    /** @param  ObdVehicleDedupeReport[]  $reports */
    private function planRows(array $reports): array
    {
        $rows = [];
        foreach ($reports as $report) {
            foreach ($report->linked as $link) {
                $rows[] = [$link['user_id'], 'Ditautkan', "baris {$link['row_id']} → garasi {$link['garage_vehicle_id']}"];
            }
            foreach ($report->merged as $merge) {
                $rows[] = [
                    $merge['user_id'],
                    'Digabung',
                    "bertahan {$merge['keeper_id']} ← dihapus ".implode(', ', $merge['removed_ids']),
                ];
            }
        }

        return $rows;
    }

    /** @param  ObdVehicleDedupeReport[]  $reports */
    private function writeBackup(array $reports): string
    {
        $userIds = array_values(array_unique(array_merge(
            [],
            ...array_map(fn (ObdVehicleDedupeReport $r) => $r->affectedUserIds(), $reports),
        )));

        $rows = ObdVehicle::query()
            ->whereIn('user_id', $userIds)
            ->orderBy('user_id')
            ->orderBy('created_at')
            ->get()
            ->map(fn (ObdVehicle $r) => $r->getAttributes())
            ->all();

        $path = 'obd-vehicles-backup-'.now()->format('Y-m-d-His').'.json';
        Storage::disk('local')->put($path, json_encode([
            'kind' => 'obd-vehicles-backup',
            'created_at' => now()->toIso8601String(),
            'rows' => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return Storage::disk('local')->path($path);
    }

    /** @param  ObdVehicleDedupeReport[]  $reports */
    private function countLinked(array $reports): int
    {
        return array_sum(array_map(fn (ObdVehicleDedupeReport $r) => count($r->linked), $reports));
    }

    /** @param  ObdVehicleDedupeReport[]  $reports */
    private function countRemoved(array $reports): int
    {
        return array_sum(array_map(fn (ObdVehicleDedupeReport $r) => count($r->removedIds()), $reports));
    }
}
