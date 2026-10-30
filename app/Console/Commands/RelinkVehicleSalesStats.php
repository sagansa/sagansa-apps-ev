<?php

namespace App\Console\Commands;

use App\Services\VehicleSalesStatsRelinker;
use Illuminate\Console\Command;

/**
 * Sinkronkan ulang FK seluruh stats dari master CONNECTING — isi NULL-link
 * & perbaiki SALAH-link (mis. koreksi model family / typo brand GAIKINDO)
 * lintas tahun, tanpa re-upload file. Master = sumber kebenaran; baris yang
 * tidak dikenal master tidak pernah dilepas link-nya.
 *
 * Contoh:
 *   php artisan vehicle-connecting:relink
 *   php artisan vehicle-connecting:relink --year=2024
 *   php artisan vehicle-connecting:relink --dry-run
 */
class RelinkVehicleSalesStats extends Command
{
    protected $signature = 'vehicle-connecting:relink
        {--year= : Batasi tahun stats}
        {--dry-run : Laporankan tanpa menulis apa pun}';

    protected $description = 'Sinkronkan ulang FK stats dari master CONNECTING — isi NULL-link & perbaiki salah-link lintas tahun tanpa re-upload file';

    public function handle(VehicleSalesStatsRelinker $relinker): int
    {
        $option = (string) $this->option('year');
        $year = $option !== '' ? (int) $option : null;

        if ($year !== null && ($year < 2015 || $year > 2100)) {
            $this->error('--year harus 2015-2100.');

            return self::FAILURE;
        }

        $report = $relinker->relinkAll($year, (bool) $this->option('dry-run'));

        $this->table(
            ['Keterangan', 'Nilai'],
            [
                ['Cakupan', $year !== null ? "tahun {$year}" : 'semua tahun'],
                ['Mode', $this->option('dry-run') ? 'DRY-RUN (tidak menulis)' : 'menulis'],
                ['Baris diperiksa', $report['checked']],
                ['NULL-link terisi', $report['filled']],
                ['Salah-link diperbaiki', $report['fixed']],
                ['Tak berubah / tak dikenal master', $report['unchanged']],
            ]
        );

        if ($report['samples'] !== []) {
            $this->line('Contoh perpindahan:');

            foreach ($report['samples'] as $sample) {
                $this->line('  '.$sample);
            }
        }

        if ($report['filled'] === 0 && $report['fixed'] === 0) {
            $this->info('✓ Semua baris sudah konsisten dengan master.');
        } else {
            $this->info($this->option('dry-run')
                ? 'Dry-run selesai — jalankan tanpa --dry-run untuk menerapkan.'
                : '✓ Relink selesai — cache Pasar EV di-flush.');
        }

        return self::SUCCESS;
    }
}
