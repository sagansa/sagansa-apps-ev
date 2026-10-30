<?php

namespace App\Console\Commands;

use App\Models\ModelVehicle;
use App\Services\VehicleNameSplitter;
use App\Services\VehicleSalesMatcher;
use App\Services\VehicleSalesRowsLoader;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Bangkitkan CSV master CONNECTING dari laporan GAIKINDO (XLSX konverter /
 * CSV) — baris laporan adalah sumber kebenaran: teks mentah "BRAND
 * MODEL/TYPE" tersimpan apa adanya sebagai raw_gabungan, digabung SAAT
 * EKSEKUSI di sini (converter tidak diubah). MODEL = keluarga hasil
 * splitter (nama katalog bila ter-resolve), TYPE = varian penuh mentah;
 * CATEGORY/SIZE diwarisi dari katalog bila model sudah ada. POWERTRAIN
 * dari fuel/splitter. Output diimpor lewat vehicle-connecting:import
 * (upsert by raw_gabungan, idempoten).
 *
 * Contoh:
 *   php artisan vehicle-connecting:from-xlsx ~/Downloads/3_GAIKINDO_wholesales_data_jansep2026.xlsx
 *   php artisan vehicle-connecting:from-xlsx laporan.xlsx --out=docs/csv/MASTER_CONNECTING_2026.csv
 */
class GenerateVehicleConnectingFromXlsx extends Command
{
    protected $signature = 'vehicle-connecting:from-xlsx
        {file : Path laporan GAIKINDO (XLSX konverter atau CSV)}
        {--out= : Path CSV hasil (default: <repo>/docs/csv/GAIKINDO_CONNECTING_FROM_XLSX_<Ymd>.csv)}';

    protected $description = 'Bangkitkan CSV master CONNECTING dari XLSX/CSV laporan GAIKINDO (raw_gabungan = gabungan brand+model saat eksekusi; kategori diwarisi katalog bila model sudah ada)';

    /** Bucket powertrain konsisten dgn master lama & scope stats (ICE = G/D/CNG). */
    protected const POWERTRAIN_MAP = [
        'BEV' => 'BEV',
        'EV' => 'BEV',
        'HEV' => 'HEV',
        'PHEV' => 'PHEV',
        'FCEV' => 'FCEV',
        'G' => 'ICE',
        'D' => 'ICE',
        'CNG' => 'ICE',
        'PETROL' => 'ICE',
        'DIESEL' => 'ICE',
    ];

    public function handle(
        VehicleSalesRowsLoader $loader,
        VehicleNameSplitter $splitter,
        VehicleSalesMatcher $matcher,
    ): int {
        $filePath = (string) $this->argument('file');

        if (! is_file($filePath)) {
            $this->error("File tidak ditemukan: {$filePath}");

            return self::FAILURE;
        }

        $outPath = (string) ($this->option('out')
            ?: base_path('../docs/csv/GAIKINDO_CONNECTING_FROM_XLSX_'.now()->format('Ymd').'.csv'));

        try {
            [$rows, $junkSkipped] = $loader->read($filePath, null);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! is_dir(dirname($outPath))) {
            mkdir(dirname($outPath), 0777, true);
        }

        $handle = fopen($outPath, 'w');
        fputcsv($handle, ['BRAND MODEL TYPE', 'FUEL', 'BRAND', 'MODEL', 'TYPE', 'POWERTRAIN', 'CATEGORY', 'SIZE']);

        // Cache katalog model — kategori diwarisi tanpa query per baris.
        $modelsById = ModelVehicle::query()->get()->keyBy('id');

        /** @var array<string, true> $seen duplikat varian dalam satu file (squashed key). */
        $seen = [];
        $written = 0;
        $linked = 0;
        /** @var list<string> $unlinked */
        $unlinked = [];
        $skipped = $junkSkipped;

        foreach ($rows as $row) {
            $split = $splitter->split($row['brand'], $row['type_model'], $row['fuel']);

            if ($split['flag'] === 'junk' || trim((string) $split['model']) === '') {
                $skipped++;

                continue;
            }

            $gabungan = trim((string) preg_replace('/\s+/', ' ', $row['raw_full']));
            $key = (string) preg_replace('/[^A-Z0-9]/u', '', mb_strtoupper($gabungan));

            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            // MODEL: nama katalog bila ter-resolve (dijamin cocok saat master
            // diimpor — resolusi pakai nama yang sama), selain itu keluarga
            // hasil splitter.
            $probe = $matcher->preview($row['brand'], $split['model'], $row['type_model']);
            $model = ($probe['model_vehicle_id'] !== null && ! $probe['model_new'])
                ? (string) $probe['model_name']
                : $split['model'];

            $catalog = $probe['model_vehicle_id'] !== null
                ? ($modelsById[$probe['model_vehicle_id']] ?? null)
                : null;

            if ($catalog !== null) {
                $linked++;
            } else {
                $unlinked[] = $row['brand'].' | '.$model;
            }

            $powertrainRaw = strtoupper(trim((string) ($split['powertrain'] ?? '')));

            if ($powertrainRaw === '' && $row['fuel'] !== null) {
                $powertrainRaw = strtoupper(trim((string) $row['fuel']));
            }

            fputcsv($handle, [
                $gabungan,
                $row['fuel'] !== null ? strtoupper((string) $row['fuel']) : '',
                $row['brand'],
                $model,
                $row['type_model'],
                self::POWERTRAIN_MAP[$powertrainRaw] ?? ($powertrainRaw !== '' ? $powertrainRaw : ''),
                $catalog?->category ?? '',
                $catalog?->size_class ?? '',
            ]);
            $written++;
        }

        fclose($handle);

        $this->table(
            ['Keterangan', 'Nilai'],
            [
                ['File sumber', basename($filePath)],
                ['CSV master', $outPath],
                ['Baris unik ditulis', $written],
                ['Ter-link katalog (brand+model)', $linked],
                ['Belum link katalog', count($unlinked)],
                ['Dilewati (junk/tanpa model)', $skipped],
            ]
        );

        if ($unlinked !== []) {
            $this->warn('Belum link katalog: '.count($unlinked).' — baris tetap ditulis dgn link NULL; lengkapi katalog lewat Sinkronisasi CONNECTING → Terapkan ke Katalog.');
            $this->line('  contoh: '.implode('; ', array_slice(array_unique($unlinked), 0, 8)));
        }

        $this->info('✓ Lanjut: php artisan vehicle-connecting:import '.escapeshellarg($outPath));

        return self::SUCCESS;
    }
}
