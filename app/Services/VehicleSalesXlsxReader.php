<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Pembaca XLSX penjualan GAIKINDO hasil gaikindo-converter — sheet "Data"
 * (judul baris 2-3, header baris 4, data mulai baris 5; header bisa di baris
 * mana pun ≤10). Kolom: BRAND, MODEL/TYPE (alias TYPE MODEL / MODEL TYPE),
 * CC, TRANS, FUEL, TANK, GVW (KG), GEAR RATIO, WHEEL & TYRE, PS/HP,
 * WHEEL BASE (MM), DIMENSION (MM), SEATER, DRIVE SYS, SPEED, DOOR, WHEELS,
 * CBU/CKD, ORIGIN, JAN..DEC, TOTAL.
 *
 * Bentuk baris IDENTIK dengan VehicleSalesCsvReader::read() — brand,
 * type_model, raw_full, fuel, cells, units, junk* — plus key `specs`:
 * map kolom spesifikasi non-kosong (kunci = kolom type_vehicles).
 */
class VehicleSalesXlsxReader
{
    /** Header spesifikasi → kolom type_vehicles (nilai kosong tidak dikirim). */
    public const SPEC_COLUMNS = [
        'CC' => 'cc',
        'TRANS' => 'trans',
        'FUEL' => 'fuel',
        'TANK' => 'tank',
        'GVW (KG)' => 'gvw',
        'GEAR RATIO' => 'gear_ratio',
        'WHEEL & TYRE' => 'wheel_tyre',
        'PS/HP' => 'ps_hp',
        'WHEEL BASE (MM)' => 'wheel_base',
        'DIMENSION (MM)' => 'dimension',
        'SEATER' => 'seater',
        'DRIVE SYS' => 'drive_sys',
        'SPEED' => 'speed',
        'DOOR' => 'door',
        'WHEELS' => 'wheels',
        'CBU/CKD' => 'cbu_ckd',
        'ORIGIN' => 'origin_country',
    ];

    /**
     * @return array{0: list<array{brand: string, type_model: string, raw_full: string, fuel: ?string, cells: array<int, int>, units: ?int, specs: array<string, mixed>}>, 1: int, 2: list<array{brand: string, type_model: string, reason: string}>}
     *
     * @throws RuntimeException bila header tidak memenuhi kebutuhan periode.
     */
    public function read(string $filePath, ?int $month): array
    {
        try {
            $spreadsheet = IOFactory::load($filePath);
        } catch (\PhpOffice\PhpSpreadsheet\Reader\Exception $e) {
            throw new RuntimeException("File XLSX tidak bisa dibaca: {$e->getMessage()}");
        }

        $sheet = $spreadsheet->getSheetByName('Data') ?? $spreadsheet->getActiveSheet();

        // Nilai mentah (tanpa format angka) supaya 84.149 tetap int, bukan
        // string berformat "84,149".
        $grid = $sheet->toArray(null, true, false, false);

        [$headerIdx, $headerRow] = $this->findHeader($grid);

        if ($headerIdx === null) {
            throw new RuntimeException('Sheet "Data" tidak memuat baris header BRAND + MODEL/TYPE — file bukan hasil gaikindo-converter?');
        }

        $idx = static fn (string $name): ?int => ($i = array_search($name, $headerRow, true)) === false ? null : $i;

        $brandI = $idx('BRAND');
        $typeModelI = $idx('MODEL/TYPE') ?? $idx('TYPE MODEL') ?? $idx('MODEL TYPE');
        $fuelI = $idx('FUEL');
        $unitsI = $idx('UNITS');

        $monthI = [];
        foreach (VehicleSalesCsvReader::MONTH_COLUMNS as $position => $name) {
            $monthI[$position + 1] = $idx($name);
        }

        if ($brandI === null || $typeModelI === null) {
            throw new RuntimeException('Header XLSX wajib memuat kolom BRAND + MODEL/TYPE.');
        }

        if ($month === null && in_array(null, $monthI, true)) {
            throw new RuntimeException('Import tahunan butuh kolom JAN..DEC (file bulanan? gunakan --month).');
        }

        if ($month !== null && $unitsI === null && $monthI[$month] === null) {
            throw new RuntimeException('Import bulanan butuh kolom UNITS atau kolom '.VehicleSalesCsvReader::MONTH_COLUMNS[$month - 1].'.');
        }

        $specI = [];
        foreach (self::SPEC_COLUMNS as $header => $column) {
            if (($i = $idx($header)) !== null) {
                $specI[$i] = $column;
            }
        }

        $rows = [];
        $junkSkipped = 0;
        /** @var list<array{brand: string, type_model: string, reason: string}> $junkRows */
        $junkRows = [];

        for ($r = $headerIdx + 1, $n = count($grid); $r < $n; $r++) {
            $line = $grid[$r];

            $brand = trim((string) ($line[$brandI] ?? ''));
            $typeModel = trim((string) ($line[$typeModelI] ?? ''));

            if ($brand === '' && $typeModel === '') {
                continue; // baris kosong penuh (abaikan diam-diam, tanpa noise)
            }

            $normBrand = mb_strtoupper(preg_replace('/\s+/', ' ', $brand) ?? '');
            $normTypeModel = mb_strtoupper(preg_replace('/\s+/', ' ', $typeModel) ?? '');

            if (in_array($normBrand, ['TOTAL', 'CUMULATIVE'], true)
                || in_array($normTypeModel, ['TOTAL', 'CUMULATIVE'], true)) {
                $junkSkipped++;
                $junkRows[] = ['brand' => $brand, 'type_model' => $typeModel, 'reason' => 'Baris TOTAL/subtotal'];

                continue;
            }

            $cells = [];
            foreach ($monthI as $cellMonth => $cellIndex) {
                $cells[$cellMonth] = $cellIndex === null
                    ? 0
                    : (new VehicleSalesCsvReader)->parseUnits($line[$cellIndex] ?? null);
            }

            $specs = [];
            foreach ($specI as $i => $column) {
                $value = $this->normalizeSpec($line[$i] ?? null);
                if ($value !== null) {
                    $specs[$column] = $value;
                }
            }

            $fuelRaw = $fuelI === null ? null : $this->normalizeSpec($line[$fuelI] ?? null);
            $fuel = is_string($fuelRaw) && in_array(strtoupper($fuelRaw), ['-', ''], true) ? null : $fuelRaw;

            $rows[] = [
                'brand' => $brand,
                'type_model' => $typeModel,
                'raw_full' => trim($brand.' '.$typeModel),
                'fuel' => $fuel !== null ? (string) $fuelRaw : null,
                'cells' => $cells,
                'units' => $unitsI === null ? null : (new VehicleSalesCsvReader)->parseUnits($line[$unitsI] ?? null),
                'specs' => $specs,
            ];
        }

        return [$rows, $junkSkipped, $junkRows];
    }

    /**
     * Baris header = baris ≤10 pertama yang memuat BRAND dan salah satu
     * MODEL/TYPE | TYPE MODEL | MODEL TYPE (konverter menaruhnya di baris 4).
     *
     * @param list<array<int, mixed>> $grid
     *
     * @return array{0: ?int, 1: list<string>}
     */
    protected function findHeader(array $grid): array
    {
        foreach (array_slice($grid, 0, 10, true) as $r => $line) {
            $cells = array_map(
                static fn ($cell): string => mb_strtoupper(trim(preg_replace('/\s+/', ' ', (string) $cell) ?? '')),
                $line,
            );

            if (in_array('BRAND', $cells, true)
                && (in_array('MODEL/TYPE', $cells, true)
                    || in_array('TYPE MODEL', $cells, true)
                    || in_array('MODEL TYPE', $cells, true))) {
                return [$r, $cells];
            }
        }

        return [null, []];
    }

    /** '-' / kosong / null → null; angka integral float → int; sisanya apa adanya. */
    protected function normalizeSpec(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_float($value) && $value === floor($value)) {
            return (int) $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' || $trimmed === '-' ? null : $trimmed;
        }

        return $value;
    }
}
