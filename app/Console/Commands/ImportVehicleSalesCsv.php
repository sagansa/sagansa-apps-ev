<?php

namespace App\Console\Commands;

use App\Models\SalesImport;
use App\Models\TypeVehicle;
use App\Models\VehicleSalesStat;
use App\Services\VehicleNameSplitter;
use App\Services\VehicleSalesMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Import penjualan wholesales GAIKINDO (CSV kolom BRAND, TYPE MODEL, FUEL
 * opsional, JAN..DEC — atau XLSX hasil gaikindo-converter sheet "Data"
 * dengan kolom spesifikasi lengkap) ke sales_imports + vehicle_sales_stats,
 * lalu match ke katalog brand/model/type kendaraan.
 *
 * PENTING — REPLACE per periode: command ini MENGHAPUS dulu seluruh
 * vehicle_sales_stats milik periode yang sama (import tahunan: satu tahun;
 * import bulanan: satu bulan pada tahun itu), baru menulis ulang. Re-upload
 * file yang diperbaiki bersih menggantikan angka lama (idempotent), tidak
 * pernah menumpuk dua kali. Baris sales_imports tetap append-only sebagai
 * riwayat file.
 *
 * Format nilai bulan: angka, atau "-" (tidak ada/nol); koreksi negatif
 * (mis. "(70)" = -70 retur) ikut tersimpan agar Σ bulan = rekap resmi.
 * Import tahunan menghasilkan baris per bulan bernilai ≠ 0 plus SATU baris
 * agregat (month = NULL) berisi jumlah bulan tersebut. Import bulanan
 * (--month) mengambil angka dari kolom UNITS (file bulanan) atau kolom
 * bulan terkait (file format tahunan).
 *
 * Spesifikasi (XLSX): baris yang ter-link ke type kendaraan meng-upsert
 * kolom spec di type_vehicles — nilai non-kosong TERBARU MENIMPA, sel
 * kosong TIDAK menghapus nilai lama (kebijakan 2026-10-30).
 *
 * Contoh:
 *   php artisan vehicle-sales:import-csv docs/csv/GAIKINDO_2022.csv
 *   php artisan vehicle-sales:import-csv ~/Downloads/GAIKINDO_2025.xlsx
 *   php artisan vehicle-sales:import-csv GAIKINDO_2026-01.csv --month=1
 */
class ImportVehicleSalesCsv extends Command
{
    /** @var list<string> */
    protected const MONTH_COLUMNS = [
        'JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN',
        'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC',
    ];

    protected $signature = 'vehicle-sales:import-csv
        {file : Path ke file CSV/XLSX GAIKINDO}
        {--year= : Tahun periode (default: dideteksi dari nama file)}
        {--month= : Bulan periode 1-12 (kosong = import tahunan JAN..DEC)}
        {--source=gaikindo-csv : Sumber data}
        {--require-full-link : Tolak import bila masih ada baris BEV yang gagal ter-match ke katalog (jalankan vehicle-sales:preview dulu)}';

    protected $description = 'Import wholesales GAIKINDO (CSV atau XLSX konverter) per tahun/bulan ke vehicle_sales_stats + match katalog brand/model/type (stats periode terkait DIGANTI, bukan ditumpuk; XLSX juga meng-upsert spesifikasi type).';

    public function handle(VehicleNameSplitter $splitter, VehicleSalesMatcher $matcher): int
    {
        $filePath = (string) $this->argument('file');

        if (! is_file($filePath)) {
            $this->error("File tidak ditemukan: {$filePath}");

            return self::FAILURE;
        }

        $year = $this->resolveYear($filePath);

        if ($year === null) {
            $this->error('Tahun tidak diketahui: berikan --year atau sisipkan 4 digit tahun pada nama file (mis. GAIKINDO_2022.csv).');

            return self::FAILURE;
        }

        $month = null;

        if ($this->option('month') !== null && $this->option('month') !== '') {
            $month = (int) $this->option('month');

            if ($month < 1 || $month > 12) {
                $this->error('--month harus angka 1-12.');

                return self::FAILURE;
            }
        }

        $parsed = $this->readRows($filePath, $month);

        if ($parsed === null) {
            return self::FAILURE; // sebabnya sudah dilaporkan
        }

        [$rows, $junkSkipped] = $parsed;

        // --require-full-link: pre-flight read-only — semua baris BEV wajib
        // ter-match ke katalog SEBELUM ada penulisan (lihat
        // vehicle-sales:preview utk laporannya). Non-BEV memang by-design
        // tidak ter-link (aturan BEV-only).
        if ($this->option('require-full-link')) {
            $unlinked = [];

            foreach ($rows as $row) {
                // LAPISAN 0: sudah ada di CONNECTING → ter-link, lewati cek.
                if ($matcher->connectingHit($row['brand'], $row['type_model']) !== null) {
                    continue;
                }

                $split = $splitter->split($row['brand'], $row['type_model'], $row['fuel']);

                if ($split['flag'] === 'junk' || $split['model'] === '') {
                    continue;
                }

                $probe = $matcher->preview($row['brand'], $split['model'], $row['type_model']);

                if ($probe['brand_new'] || $probe['model_new']) {
                    $unlinked[] = $row['brand'].' | '.$split['model'];
                }
            }

            if ($unlinked !== []) {
                $this->error('Import ditolak (--require-full-link): '.count($unlinked).
                    ' kombinasi BEV belum ada di katalog. Jalankan vehicle-sales:preview, lengkapi katalog, lalu ulangi.');
                $this->line('  contoh: '.implode('; ', array_slice(array_unique($unlinked), 0, 8)));

                return self::FAILURE;
            }
        }

        try {
            $summary = DB::transaction(function () use ($rows, $junkSkipped, $filePath, $year, $month, $splitter, $matcher) {
                // REPLACE-first: bersihkan stats periode yang sama agar
                // re-upload idempotent — angka lama tidak pernah menumpuk.
                VehicleSalesStat::query()
                    ->where('year', $year)
                    ->when($month !== null, fn ($q) => $q->where('month', $month))
                    ->delete();

                $import = SalesImport::create([
                    'file_name' => basename($filePath),
                    'source' => (string) $this->option('source'),
                    'year' => $year,
                    'status' => 'processed',
                ]);

                $statsCreated = 0;
                $typesCreated = 0;
                $specsUpdated = 0;
                $skipped = $junkSkipped;
                /** @var array<int, true> $brandIds */
                $brandIds = [];
                /** @var array<int, true> $modelIds */
                $modelIds = [];

                foreach ($rows as $row) {
                    $split = $splitter->split($row['brand'], $row['type_model'], $row['fuel']);

                    if ($split['flag'] === 'junk' || $split['model'] === '') {
                        $skipped++;

                        continue;
                    }

                    // SEMUA powertrain ter-link ke katalog (BEV/HEV/PHEV/ICE) —
                    // hierarki brand-model-type mencakup seluruh pasar.
                    // Match di level KELUARGA (hasil splitter), bukan varian penuh.
                    $match = $matcher->match($row['brand'], $split['model'], null, $row['type_model']);

                    // CONNECTING hit: type & powertrain ikut master, tanpa
                    // pemecah nama; resolveType hanya untuk baris fallback.
                    $typeId = $match['type_vehicle_id'] ?? null;
                    $powertrain = $match['connecting_powertrain'] ?? $split['powertrain'];

                    if ($typeId === null && $match['model_vehicle_id'] !== null && $split['type'] !== '') {
                        $typeId = $this->resolveType($match['model_vehicle_id'], $split['type'], $typesCreated, $powertrain);
                    }

                    // Spesifikasi XLSX konverter → type_vehicles (nilai
                    // non-kosong TERBARU menimpa; sel kosong tidak menghapus).
                    $specs = $row['specs'] ?? [];

                    if ($specs !== [] && $typeId !== null) {
                        $typeEntity = TypeVehicle::query()->find($typeId);

                        if ($typeEntity !== null) {
                            $typeEntity->fill($specs);
                            $typeEntity->specs_source = 'gaikindo';
                            $typeEntity->specs_updated_at = now();
                            $typeEntity->save();
                            $specsUpdated++;
                        }
                    }

                    $base = [
                        'sales_import_id' => $import->id,
                        'raw_brand' => $row['brand'],
                        'raw_model' => $row['type_model'],
                        'brand_vehicle_id' => $match['brand_vehicle_id'],
                        'model_vehicle_id' => $match['model_vehicle_id'],
                        'type_vehicle_id' => $typeId,
                        'powertrain' => $powertrain,
                        'year' => $year,
                    ];

                    $createStat = function (int $units, ?int $statMonth) use ($base, &$statsCreated, &$brandIds, &$modelIds): void {
                        VehicleSalesStat::create($base + [
                            'month' => $statMonth,
                            'units' => $units,
                        ]);

                        $statsCreated++;

                        if ($base['brand_vehicle_id'] !== null) {
                            $brandIds[$base['brand_vehicle_id']] = true;
                        }

                        if ($base['model_vehicle_id'] !== null) {
                            $modelIds[$base['model_vehicle_id']] = true;
                        }
                    };

                    if ($month === null) {
                        // Tahunan: satu baris per bulan bernilai ≠ 0
                        // (koreksi negatif ikut) ...
                        $sum = 0;

                        foreach ($row['cells'] as $cellMonth => $units) {
                            if ($units !== 0) {
                                $createStat($units, $cellMonth);
                                $sum += $units;
                            }
                        }

                        // ... plus SATU baris agregat tahunan (month = NULL).
                        if ($sum !== 0) {
                            $createStat($sum, null);
                        }
                    } else {
                        // Bulanan: dari kolom UNITS (file bulanan) atau kolom
                        // bulan terkait (file format tahunan).
                        $units = $row['units'] ?? $row['cells'][$month];

                        if ($units !== 0) {
                            $createStat($units, $month);
                        }
                    }
                }

                if ($statsCreated === 0) {
                    throw new RuntimeException(sprintf(
                        'Tidak ada statistik dihasilkan dari %d baris terbaca (semua nol/kosong/junk) — import dibatalkan, tidak ada yang ditulis.',
                        count($rows),
                    ));
                }

                return [
                    'rows_read' => count($rows),
                    'skipped' => $skipped,
                    'stats' => $statsCreated,
                    'brands' => count($brandIds),
                    'models' => count($modelIds),
                    'types' => $typesCreated,
                    'specs_updated' => $specsUpdated,
                ];
            });
        } catch (RuntimeException $e) {
            $this->error('Import dibatalkan: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Keterangan', 'Nilai'],
            [
                ['File', basename($filePath)],
                ['Periode', $month === null ? "tahunan {$year}" : "bulan {$month} tahun {$year}"],
                ['Baris dibaca', $summary['rows_read']],
                ['Baris dilewati (junk/kosong)', $summary['skipped']],
                ['Baris stats dibuat', $summary['stats']],
                ['Brand ter-match ke katalog', $summary['brands']],
                ['Model ter-match ke katalog', $summary['models']],
                ['Type baru dibuat', $summary['types']],
                ['Type ter-update spesifikasi', $summary['specs_updated']],
                ['Matcher: brand/model baru', $matcher->summary()['created_brands'].' / '.$matcher->summary()['created_models']],
            ]
        );

        $this->info('✓ Import berhasil (stats periode terkait telah diganti).');

        // Cache respons Pasar EV 24 jam — versi-key dinaikkan agar mobile
        // langsung melihat data baru.
        app(\App\Services\VehicleConnectingSyncService::class)->flushMarketCache();

        return self::SUCCESS;
    }

    /** Tahun dari --year, else 4 digit pertama yang cocok di nama file. */
    protected function resolveYear(string $filePath): ?int
    {
        $option = $this->option('year');

        if ($option !== null && $option !== '') {
            return (int) $option;
        }

        return preg_match('/(\d{4})/', basename($filePath), $m) ? (int) $m[1] : null;
    }

    /**
     * Delegasi ke VehicleSalesRowsLoader (CSV atau XLSX konverter — sumber
     * parsing bersama untuk import/preview/GUI); error dilaporkan gaya
     * console.
     *
     * @return ?array{0: list<array<string, mixed>>, 1: int}
     */
    protected function readRows(string $filePath, ?int $month): ?array
    {
        try {
            return app(\App\Services\VehicleSalesRowsLoader::class)->read($filePath, $month);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return null;
        }
    }

    /** Sel bulan CSV: integer, atau "-"/kosong = nol. Tolerir pemisah ribuan. */
    protected function parseUnits(mixed $value): int
    {
        return app(\App\Services\VehicleSalesCsvReader::class)->parseUnits($value);
    }

    /**
     * TypeVehicle untuk varian PENUH di bawah model (nama exact case-
     * insensitive); dibuat bila belum ada — type_charger wajib diisi []
     * (kolom JSON NOT NULL di skema existing).
     */
    protected function resolveType(int $modelId, string $typeName, int &$created, ?string $powertrain = null): int
    {
        $typeName = trim($typeName);

        $existing = TypeVehicle::query()
            ->where('model_vehicle_id', $modelId)
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($typeName)])
            ->first();

        if ($existing !== null) {
            // Type existing dgn powertrain kosong → isi dari baris laporan.
            if ($existing->powertrain === null && $powertrain !== null && $powertrain !== '') {
                $existing->powertrain = $powertrain;
                $existing->save();
            }

            return $existing->id;
        }

        $created++;

        return TypeVehicle::create([
            'model_vehicle_id' => $modelId,
            'name' => $typeName,
            'type_charger' => [], // kolom json NOT NULL di skema existing
            'powertrain' => $powertrain !== null && $powertrain !== '' ? $powertrain : null,
        ])->id;
    }
}
