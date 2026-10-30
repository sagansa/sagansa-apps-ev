<?php

namespace App\Services;

use App\Models\TypeVehicle;

/**
 * Inti analisis "apa yang baru di laporan ini?" — dipakai command
 * vehicle-sales:preview DAN halaman admin Preview Impor Penjualan.
 * Read-only terhadap katalog & stats (matcher mode preview).
 */
class VehicleSalesPreviewService
{
    public function __construct(
        protected VehicleNameSplitter $splitter,
        protected VehicleSalesMatcher $matcher,
        protected VehicleSalesRowsLoader $reader,
    ) {}

    /**
     * @return array{summary: array{rows: int, skipped: int, nonBev: int, matched: int, new: int},
     *               new: list<array{brand: string, raw_brand: string, raw_model: string, model: string, type: string, fuel: ?string, powertrain: string, units: int, brand_name: ?string}>}
     */
    public function analyze(string $csvPath, ?int $month = null): array
    {
        try {
            [$rows, $junkSkipped, $junkRows] = $this->reader->read($csvPath, $month);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException($e->getMessage());
        }

        $new = [];
        $matched = 0;
        $skipped = $junkSkipped;
        /** @var list<array{brand: string, type_model: string, reason: string}> $skippedRows */
        $skippedRows = $junkRows;

        // Status brand katalog utk badge "model baru di {brand}" vs "belum ada
        // di CONNECTING" — di-cache per brand mentah (probe ringan, tanpa
        // pencocokan model).
        /** @var array<string, ?string> $brandStatus */
        $brandStatus = [];

        foreach ($rows as $row) {
            $gabungan = trim(preg_replace('/\s+/', ' ', $row['raw_full']));

            // SATU perbandingan: teks utuh baris ↔ raw_gabungan CONNECTING.
            // Ketemu = match; tidak ketemu = BARU — pemecahan nama hanya
            // sebagai PREFILL agar bisa dilengkapi, bukan sebagai match.
            if ($gabungan !== '' && $this->matcher->connectingHitRaw($gabungan) !== null) {
                $matched++;

                continue;
            }

            $key = mb_strtoupper(preg_replace('/[^A-Z0-9]/u', '', $gabungan));
            $units = $month !== null
                ? ($row['units'] ?? $row['cells'][$month] ?? 0)
                : array_sum($row['cells']);

            if (! isset($new[$key])) {
                if (! array_key_exists($row['brand'], $brandStatus)) {
                    $probe = $this->matcher->preview($row['brand'], '', $row['type_model']);
                    $brandStatus[$row['brand']] = ($probe['brand_new'] ?? true)
                        ? null
                        : ($probe['brand_name'] ?? null);
                }

                // Prefill keluarga/varian via pemecah nama; powertrain dari
                // FUEL file (G/D/CNG → ICE, Hybrid/HEV → HEV, PHEV → PHEV).
                $split = $this->splitter->split($row['brand'], $row['type_model'], $row['fuel']);

                $powertrain = VehicleNameSplitter::fuelToPowertrain($row['fuel']);

                if ($powertrain === null) {
                    $powertrain = strtoupper(trim((string) ($split['powertrain'] ?? '')));
                }

                if (! in_array($powertrain, ['BEV', 'HEV', 'PHEV', 'ICE'], true)) {
                    $powertrain = '';
                }

                $new[$key] = [
                    'brand' => $gabungan,
                    'raw_brand' => trim((string) $row['brand']),
                    'raw_model' => trim((string) $row['type_model']),
                    'model' => trim((string) ($split['model'] ?? '')) !== ''
                        ? $split['model']
                        : trim((string) $row['type_model']),
                    'type' => trim((string) $row['type_model']),
                    'fuel' => $row['fuel'] !== null ? strtoupper(trim((string) $row['fuel'])) : null,
                    'powertrain' => $powertrain,
                    'units' => 0,
                    'brand_name' => $brandStatus[$row['brand']],
                ];
            }

            $new[$key]['units'] += $units;
        }

        return [
            'summary' => [
                'rows' => count($rows),
                'skipped' => $skipped,
                'matched' => $matched,
                'new' => count($new),
            ],
            'new' => array_values($new),
            'skipped_rows' => $skippedRows,
        ];
    }

    /** Tulis kombinasi baru sebagai CSV siap-gabung ke CONNECTING. */
    public function exportNewCsv(array $newRows, string $outPath): int
    {
        $handle = fopen($outPath, 'w');
        fputcsv($handle, ['BRAND', 'MODEL', 'TYPE', 'POWERTRAIN', 'CATEGORY', 'SIZE']);

        foreach ($newRows as $n) {
            fputcsv($handle, [$n['brand'], $n['model'], $n['type'], $n['powertrain'], '', '']);
        }

        fclose($handle);

        return count($newRows);
    }

    /** True bila type sudah terdaftar di bawah model (read-only). */
    public function typeExists(int $modelId, string $typeName): bool
    {
        return TypeVehicle::query()
            ->where('model_vehicle_id', $modelId)
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($typeName))])
            ->exists();
    }
}
