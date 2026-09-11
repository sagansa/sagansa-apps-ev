<?php

namespace App\Services;

use App\Models\SalesImport;
use App\Models\VehicleSalesStat;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Import file wholesales GAIKINDO (xlsx) ke sales_imports + vehicle_sales_stats.
 *
 * Format file berubah tiap tahun (jumlah sheet, jumlah blok header, posisi kolom,
 * angka float Eropa "4.936" = 4.936 unit), maka parser GENERIK berbasis deteksi
 * header: setiap blok header dicari dari token nama bulan; kolom FUEL/TANK/CC/
 * BRAND/TYPE dideteksi dari header dengan fallback heuristik kolom teks; baris
 * TOTAL/DOMESTIC resmi ditangkap sebagai angka pembanding EXACT (coverage
 * disimpan di meta — baris model yang hilang tidak mengotori angka resmi).
 */
class GaikindoImportService
{
    /** Token header bulan yang diakui (EN + ID) → nomor bulan. */
    protected const MONTH_TOKENS = [
        'JAN' => 1, 'JANUARY' => 1, 'JANUARI' => 1,
        'FEB' => 2, 'FEBRUARY' => 2, 'FEBRUARI' => 2,
        'MAR' => 3, 'MARCH' => 3, 'MARET' => 3,
        'APR' => 4, 'APRIL' => 4,
        'MAY' => 5, 'MEI' => 5,
        'JUN' => 6, 'JUNE' => 6, 'JUNI' => 6,
        'JUL' => 7, 'JULY' => 7, 'JULI' => 7,
        'AUG' => 8, 'AGU' => 8, 'AGUST' => 8, 'AUGUST' => 8, 'AGUSTUS' => 8,
        'SEP' => 9, 'SEPT' => 9, 'SEPTEMBER' => 9,
        'OCT' => 10, 'OKT' => 10, 'OCTOBER' => 10, 'OKTOBER' => 10,
        'NOV' => 11, 'NOVEMBER' => 11,
        'DEC' => 12, 'DES' => 12, 'DECEMBER' => 12, 'DESEMBER' => 12,
    ];

    /** Label section GAIKINDO → segment ternormalisasi. */
    protected const SEGMENT_PATTERNS = [
        '/^SEDAN/' => 'Sedan',
        '/^4\s*X\s*2/i' => '4X2',
        '/^4\s*X\s*4/i' => '4X4',
        '/^4WD|^4\s*WD/i' => '4X4',
        '/^2WD|^2\s*WD/i' => '4X2',
        '/^BUS/' => 'Bus',
        '/^PICK|^TRUCK/i' => 'PU/Truck',
        '/^DOUBLE\s*CABIN/i' => 'Double Cabin',
        '/^DC\b/' => 'Double Cabin',
        '/^AFFORDABLE|^LCGC|^LOW\s*COST/i' => 'LCGC',
        '/^MINIBUS/' => 'Minibus',
        '/^SUV/' => 'SUV',
        '/^MPV/' => 'MPV',
    ];

    /** Pola nama model BEV yang dikenal (fallback bila tanpa kolom FUEL & TANK). */
    protected const KNOWN_BEV_PATTERNS = [
        '/^AIR\s*EV/i', '/BINGUO/i', '/^ATTO/i', '/^SEAL(ION)?/i', '/DOLPHIN/i',
        '/^M6\b/i', '/^IONIQ/i', '/KONA\s*ELEC/i', '/^EV6/i', '/^BZ4X/i',
        '/^EX[0-9]/i', '/^J5\b/i', '/^E-?C3/i', '/^AION/i', '/^NETA/i',
        '/^MG4|^S5\s*EV|^S5\s*BEV/i', '/DARION/i', '/EKSION/i', '/MITRA\s*EV/i',
        '/^ICAR/i', '/OMODA\s*E/i', '/HYPTEC/i', '/ZEEKR/i', '/^E-?T6/i',
        '/^GELORA/i', '/^EC3[56]/i', '/VINFAST/i', '/^DENZA/i', '/^XEV/i',
    ];

    /** Label baris rekap resmi (nilai EXACT untuk validasi coverage). */
    protected const OFFICIAL_GRAND = '/DOMESTIC.*(TOTAL|SALES)|TOTAL.*DOMESTIC|GRAND\s*TOTAL/i';
    protected const OFFICIAL_PASSENGER = '/PASSENGER.*(CAR|VEHICLE|TOTAL)/i';
    protected const OFFICIAL_COMMERCIAL = '/COMMERCIAL.*(CAR|VEHICLE|TOTAL)/i';
    protected const SUMMARY_SKIP = '/^(TOTAL|CUM|JUMLAH|SUB\s*TOTAL|PASSENGER|COMMERCIAL|DOMESTIC|GRAND)/i';

    protected VehicleSalesMatcher $matcher;

    protected int $importYear;

    public function importFromFile(string $filePath, ?int $year = null, string $source = 'gaikindo'): array
    {
        if (! file_exists($filePath)) {
            throw new \InvalidArgumentException("File tidak ditemukan: {$filePath}");
        }

        // Guard environment: lock sudah memuat paket, tapi server yang belum
        // menjalankan composer install (atau install-nya gagal karena ekstensi)
        // akan melempar "Class IOFactory not found" yang membingungkan.
        if (! class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
            throw new \RuntimeException(
                'Paket phpoffice/phpspreadsheet belum terpasang di server ini. '
                .'Jalankan: composer install --no-dev --optimize-autoloader  '
                .'(butuh PHP >= 8.2 dan ekstensi ext-gd, ext-zip, ext-xml, ext-mbstring), '
                .'lalu php artisan optimize:clear.'
            );
        }

        $this->importYear = $year ?? $this->detectYear($filePath);
        $this->matcher = new VehicleSalesMatcher;

        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);

        $parsedRows = [];
        $official = [];
        $warnings = [];
        $sheetSummaries = [];

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            // index "A1" → mudah dikonversi ke nomor kolom
            $data = $sheet->toArray(null, false, false, true);
            $result = $this->parseSheet($sheet->getTitle(), $data, $warnings);
            if ($result === null) {
                continue; // sheet tanpa header bulan — bukan tabel penjualan
            }
            $sheetSummaries[] = ['sheet' => $sheet->getTitle(), 'rows' => count($result['rows'])];
            $parsedRows = array_merge($parsedRows, $result['rows']);
            foreach ($result['official'] as $key => $value) {
                $official[$key] = $value; // sheet/blok terakhir menang (rekap di bawah)
            }
        }
        $spreadsheet->disconnectWorksheets();

        if ($parsedRows === []) {
            throw new \RuntimeException('Tidak ada baris model terparse — format file tidak dikenali.');
        }

        $parsedTotal = array_sum(array_column($parsedRows, 'total'));
        $officialTotal = $official['grand']['total'] ?? null;
        $coverage = ($officialTotal !== null && $officialTotal > 0)
            ? round($parsedTotal / $officialTotal, 4)
            : null;

        // Baris rekap resmi adalah satu-satunya anchor exact — tanpa dia,
        // tidak ada cara memverifikasi hasil parse sama sekali.
        if ($officialTotal === null || $officialTotal <= 0) {
            throw new \RuntimeException(
                'Baris rekap resmi (DOMESTIC SALES TOTAL) tidak ditemukan/tidak terbaca. '
                .'Layout file kemungkinan tidak didukung — import dibatalkan agar data rusak tidak masuk DB.'
            );
        }
        // Over-parse = kontaminasi baris kumulatif/sel merge (mis. file 2025 REV1
        // mencapai 1600%). Lebih baik gagal bersih daripada menyimpan angka salah.
        if ($coverage > 1.10) {
            throw new \RuntimeException(sprintf(
                'Hasil parse %.1f%% dari total resmi (%s vs %s) — mengandung baris kumulatif/sel '
                .'merge yang tidak dapat dipisahkan. Gunakan file versi bersih. Import dibatalkan.',
                $coverage * 100,
                number_format($parsedTotal),
                number_format($officialTotal),
            ));
        }

        $monthsPresent = [];
        foreach ($parsedRows as $row) {
            foreach ($row['months'] as $m => $u) {
                if ($u != 0) {
                    $monthsPresent[$m] = true;
                }
            }
        }
        $periodStart = null;
        $periodEnd = null;
        if ($monthsPresent !== []) {
            $firstMonth = min(array_keys($monthsPresent));
            $lastMonth = max(array_keys($monthsPresent));
            $periodStart = sprintf('%04d-%02d-01', $this->importYear, $firstMonth);
            $periodEnd = sprintf('%04d-%02d-01', $this->importYear, $lastMonth);
        }

        $status = 'processed';
        if ($coverage < 0.9) {
            // Under-parse: masih disimpan (angka resmi tetap exact sebagai
            // penyebut share BEV), tapi ditandai partial supaya admin tahu.
            $status = 'partial';
            $warnings[] = sprintf('Coverage %.1f%% < 90%% — sebagian blok model kemungkinan merged/tidak terbaca.', $coverage * 100);
        }

        // Deteksi kontaminasi kolom brand (artefak konversi PDF→Excel: potongan
        // section/kategorisasi ikut menjadi raw_brand). Data seperti ini membuat
        // agregat powertrain menyesatkan meski coverage tampak bagus.
        $dirtyBrands = collect($parsedRows)
            ->filter(fn ($row) => str_contains($row['brand'], "\n")
                || preg_match('/CC\s*[<≤>]|^[A-Z]{1,2}\d|\d{1,3}[.,]\d{3}/', $this->matcher->normalize($row['brand'])))
            ->count();
        if ($dirtyBrands > max(10, intdiv(count($parsedRows), 20))) {
            $status = 'partial';
            $warnings[] = sprintf(
                '%d/%d baris memiliki raw_brand tercemar artefak konversi (potongan segmen/angka). '
                .'File kemungkinan hasil konversi PDF→Excel yang tidak faithful pada level baris.',
                $dirtyBrands,
                count($parsedRows),
            );
        }

        return DB::connection($this->connectionName())->transaction(function () use ($filePath, $source, $parsedRows, $official, $coverage, $warnings, $sheetSummaries, $status, $periodStart, $periodEnd, $parsedTotal, $officialTotal) {
            $import = SalesImport::create([
                'file_name' => basename($filePath),
                'source' => $source,
                'year' => $this->importYear,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'status' => $status,
                'meta' => [
                    'sheets' => $sheetSummaries,
                    'official' => $official,
                    'parsed_total' => $parsedTotal,
                    'official_total' => $officialTotal,
                    'coverage' => $coverage,
                    'warnings' => array_values(array_unique($warnings)),
                ],
            ]);

            $statCount = $this->persistStats($import->id, $parsedRows);

            $meta = $import->meta;
            $meta['matcher'] = $this->matcher->summary();
            $import->meta = $meta;
            $import->save();

            app(VehicleMarketService::class)->flush();

            return [
                'import_id' => $import->id,
                'year' => $this->importYear,
                'status' => $status,
                'model_rows' => count($parsedRows),
                'stat_rows' => $statCount,
                'parsed_total' => $parsedTotal,
                'official_total' => $officialTotal,
                'coverage' => $coverage,
                'official' => $official,
                'matcher' => $meta['matcher'],
                'warnings' => array_values(array_unique($warnings)),
            ];
        });
    }

    // ------------------------------------------------------------------ parser

    /**
     * Parse satu sheet. File 2024/2025 punya >1 blok header (layout kolom
     * berbeda antar segmen) — setiap blok diparse dengan peta kolomnya sendiri.
     *
     * @return array{rows: array<int, array>, official: array<string, array>}|null
     */
    protected function parseSheet(string $sheetTitle, array $data, array &$warnings): ?array
    {
        $headers = $this->findHeaders($data);
        if ($headers === []) {
            // Buku cetak terbaru kadang menaruh rekap resmi pada sheet TANPA
            // kolom bulan — sheet seperti ini tetap dipindai khusus rekap,
            // bukan dibuang begitu saja.
            return ['rows' => [], 'official' => $this->scanOfficialRowsWithoutBlocks($data)];
        }

        $rows = [];
        $official = [];

        foreach ($headers as $i => $header) {
            $blockEnd = ($i + 1 < count($headers)) ? $headers[$i + 1]['row'] - 1 : count($data);
            $blockRows = $this->parseBlock($data, $header, $header['row'] + 1, $blockEnd, $warnings, $sheetTitle);
            $rows = array_merge($rows, $blockRows);

            // Rekap resmi diambil dari blok TERAKHIR (baris bawah file).
            if ($i + 1 >= count($headers)) {
                $official = $this->collectOfficial($data, $header, $header['row'] + 1, count($data));
            }
        }

        return ['rows' => $rows, 'official' => $official];
    }

    /**
     * @param array{row: int, months: array<int, int>, total: ?int, fuel: ?int, tank: ?int, cc: ?int, labelCol: int} $header
     *
     * @return array<int, array>
     */
    protected function parseBlock(array $data, array $header, int $fromRow, int $toRow, array &$warnings, string $sheetTitle): array
    {
        $monthCols = $header['months'];
        $leftmostCol = min(array_values($monthCols));
        [$brandCol, $modelCol] = $this->findBrandModelCols($data, $header, $fromRow, $toRow);

        if ($modelCol === null) {
            $warnings[] = "{$sheetTitle}: blok header baris {$header['row']} — kolom model tidak terdeteksi.";

            return [];
        }

        $rows = [];
        $currentBrand = null;
        $currentSegment = null;

        for ($r = $fromRow; $r <= $toRow; $r++) {
            $brandCell = trim((string) $this->cell($data, $r, $brandCol));
            $modelCell = trim((string) $this->cell($data, $r, $modelCol));
            $label = $this->rowLabel($data, $r, $leftmostCol);

            if ($label === '') {
                continue;
            }

            // Baris rekap → skip dari baris model (angka resmi ditangkap collectOfficial).
            if ($this->isSummaryRow($label)) {
                continue;
            }

            // Header section (SEDAN, 4X2, BUS, ...) → set segment aktif.
            // Guard: tanpa brand cell & tanpa titik desimal — nama model seperti
            // "PICK UP 2.5" (brand di-merge ke atas) tidak boleh jadi section.
            if ($brandCell === '' && ! str_contains($label, '.') && ($segment = $this->matchSegment($label))) {
                $currentSegment = $segment;
                $currentBrand = null; // section baru menutup grup brand sebelumnya

                continue;
            }

            // Baris model: brand bisa kosong (vertical merge) → inherit.
            if ($brandCell !== '') {
                $currentBrand = $brandCell;
            }
            if ($modelCell === '' || $currentBrand === null) {
                continue;
            }

            $months = [];
            $anyNonZero = false;
            foreach ($monthCols as $month => $col) {
                $units = $this->parseUnits($this->cell($data, $r, $col));
                $months[$month] = $units;
                if ($units != 0) {
                    $anyNonZero = true;
                }
            }

            $totalValue = $header['total'] !== null
                ? $this->parseUnits($this->cell($data, $r, $header['total']))
                : array_sum($months);
            if (! $anyNonZero && $totalValue == 0) {
                continue; // baris tanpa data
            }

            $fuel = $header['fuel'] !== null ? trim((string) $this->cell($data, $r, $header['fuel'])) : '';
            $tank = $header['tank'] !== null ? trim((string) $this->cell($data, $r, $header['tank'])) : '';
            $cc = $header['cc'] !== null ? trim((string) $this->cell($data, $r, $header['cc'])) : '';
            $kwh = VehicleSalesMatcher::extractKwh($tank);

            $rows[] = [
                'brand' => $currentBrand,
                'model' => $modelCell,
                'segment' => $currentSegment,
                'powertrain' => $this->classifyPowertrain($fuel, $cc, $kwh, $modelCell),
                'kwh' => $kwh,
                'months' => $months,
                'total' => $totalValue != 0 ? $totalValue : array_sum($months),
            ];
        }

        return $rows;
    }

    /**
     * Cari SEMUA baris header (≥4 token bulan berbeda) — satu per blok layout.
     *
     * @return array<int, array{row: int, months: array<int, int>, total: ?int, fuel: ?int, tank: ?int, cc: ?int}>
     */
    protected function findHeaders(array $data): array
    {
        $headers = [];
        $lastHeaderRow = 0;

        foreach ($data as $rowRef => $row) {
            $r = (int) filter_var($rowRef, FILTER_SANITIZE_NUMBER_INT);
            if ($r <= $lastHeaderRow || $r > count($data)) {
                continue;
            }

            $months = [];
            $total = $fuel = $tank = $cc = null;

            foreach ($row as $colLetter => $value) {
                $token = mb_strtoupper(trim((string) $value));
                if ($token === '' || mb_strlen($token) > 12) {
                    continue;
                }
                $col = Coordinate::columnIndexFromString($colLetter);

                if (isset(self::MONTH_TOKENS[$token]) && ! in_array(self::MONTH_TOKENS[$token], $months, true)) {
                    $months[self::MONTH_TOKENS[$token]] = $col;

                    continue;
                }
                if ($total === null && $token === 'TOTAL') {
                    $total = $col;
                } elseif ($fuel === null && str_contains($token, 'FUEL')) {
                    $fuel = $col;
                } elseif ($tank === null && str_contains($token, 'TANK')) {
                    $tank = $col;
                } elseif ($cc === null && ($token === 'CC' || str_contains($token, '(CC)'))) {
                    $cc = $col;
                }
            }

            if (count($months) >= 4) {
                ksort($months);
                $headers[] = ['row' => $r, 'months' => $months, 'total' => $total, 'fuel' => $fuel, 'tank' => $tank, 'cc' => $cc];
                $lastHeaderRow = $r;
            }
        }

        return $headers;
    }

    /**
     * Deteksi kolom brand & model: dari header eksplisit, atau heuristik dua
     * kolom teks paling kiri sebelum area bulan.
     *
     * @param array{row: int, months: array<int, int>, ...} $header
     *
     * @return array{0: ?int, 1: ?int} [brandCol, modelCol]
     */
    protected function findBrandModelCols(array $data, array $header, int $fromRow, int $toRow): array
    {
        $firstMonthCol = min(array_values($header['months']));
        $row = $data[$header['row']] ?? [];

        $brandCol = $modelCol = null;
        foreach ($row as $colLetter => $value) {
            $token = mb_strtoupper(trim((string) $value));
            $col = Coordinate::columnIndexFromString($colLetter);
            if ($token === '' || $col >= $firstMonthCol) {
                continue;
            }
            if ($brandCol === null && (str_contains($token, 'BRAND') || str_contains($token, 'MEREK') || str_contains($token, 'MANUFACTURER'))) {
                $brandCol = $col;
            }
            if ($modelCol === null && (str_contains($token, 'TYPE') || str_contains($token, 'MODEL') || str_contains($token, 'SERIES'))) {
                $modelCol = $col;
            }
        }
        if ($brandCol !== null && $modelCol !== null && $brandCol !== $modelCol) {
            return [$brandCol, $modelCol];
        }

        // Heuristik: dua kolom berisi teks (non-numerik) paling kiri sebelum bulan.
        $textCount = [];
        $rowsScanned = 0;
        for ($r = $fromRow; $r <= $toRow && $rowsScanned < 250; $r++, $rowsScanned++) {
            for ($c = 1; $c < $firstMonthCol; $c++) {
                $v = trim((string) $this->cell($data, $r, $c));
                if ($v !== '' && preg_match('/[A-Za-z]{2}/', $v)) {
                    $textCount[$c] = ($textCount[$c] ?? 0) + 1;
                }
            }
        }
        $threshold = max(3, (int) ceil($rowsScanned * 0.15));
        $candidates = array_keys(array_filter($textCount, fn ($n) => $n >= $threshold));
        sort($candidates);
        if (count($candidates) >= 2) {
            return [$candidates[0], $candidates[1]];
        }
        if (count($candidates) === 1) {
            return [$candidates[0], $candidates[0] + 1];
        }

        return [2, 3]; // layout GAIKINDO paling umum: B=brand, C=model
    }

    /** Gabungan sel teks di kiri area bulan sebagai label baris. */
    protected function rowLabel(array $data, int $r, int $leftmostCol): string
    {
        $parts = [];
        for ($c = 1; $c < $leftmostCol; $c++) {
            $v = trim((string) $this->cell($data, $r, $c));
            if ($v !== '') {
                $parts[] = $v;
            }
        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', $parts)) ?? '');
    }

    /**
     * Tangkap angka resmi (grand/passenger/commercial) dari baris rekap.
     *
     * @param array{row: int, months: array<int, int>, total: ?int, ...} $header
     *
     * @return array<string, array{label: string, total: int, months: array<int, int>}>
     */
    protected function collectOfficial(array $data, array $header, int $fromRow, int $toRow): array
    {
        $official = [];
        $leftmostCol = min(array_values($header['months']));

        for ($r = $fromRow; $r <= $toRow; $r++) {
            $label = $this->rowLabel($data, $r, $leftmostCol);
            if ($label === '' ) {
                continue;
            }

            $type = null;
            if (preg_match(self::OFFICIAL_GRAND, $label)) {
                $type = 'grand';
            } elseif (preg_match(self::OFFICIAL_PASSENGER, $label) && ! str_contains(mb_strtoupper($label), 'COMMERCIAL')) {
                $type = 'passenger';
            } elseif (preg_match(self::OFFICIAL_COMMERCIAL, $label)) {
                $type = 'commercial';
            }
            if ($type === null || isset($official[$type])) {
                continue;
            }

            $candidate = $this->captureOfficialFromColumns($data, $header, $r);
            if ($candidate === null) {
                // Layout rekap GAIKINDO: label di satu baris, angka di baris berikutnya.
                $candidate = $this->captureOfficialFromColumns($data, $header, $r + 1);
            }
            if ($candidate === null) {
                // Layout buku cetak (2022–2026 baru): seluruh rekap berupa SATU
                // sel gabungan raksasa — teks label + deretan angka ribuan-koma
                // + persen. Diekstrak dari teks langsung.
                $candidate = $this->captureOfficialFromMergedText($data, $r);
            }
            if ($candidate === null) {
                continue; // label cocok tapi tanpa angka yang bisa dibaca
            }

            $official[$type] = ['label' => mb_substr($label, 0, 80)] + $candidate;
        }

        return $official;
    }

    /**
     * Scan rekap resmi pada sheet TANPA kolom bulan (sheet rekap tersendiri di
     * buku cetak terbaru). Label dideteksi dari gabungan sel seluruh baris,
     * angka dari ekstraksi teks sel gabungan.
     *
     * @return array<string, array{label: string, total: int, months: array<int, int>}>
     */
    protected function scanOfficialRowsWithoutBlocks(array $data): array
    {
        $official = [];

        foreach ($data as $rowRef => $row) {
            $r = is_int($rowRef) ? $rowRef : (int) filter_var($rowRef, FILTER_SANITIZE_NUMBER_INT);
            if ($r < 1) {
                continue;
            }

            $fullLabel = trim(preg_replace('/\s+/', ' ', implode(' ', array_map('strval', $row))) ?? '');
            if ($fullLabel === '') {
                continue;
            }

            $type = null;
            if (preg_match(self::OFFICIAL_GRAND, $fullLabel)) {
                $type = 'grand';
            } elseif (preg_match(self::OFFICIAL_PASSENGER, $fullLabel) && ! str_contains(mb_strtoupper($fullLabel), 'COMMERCIAL')) {
                $type = 'passenger';
            } elseif (preg_match(self::OFFICIAL_COMMERCIAL, $fullLabel)) {
                $type = 'commercial';
            }
            if ($type === null || isset($official[$type])) {
                continue;
            }

            $candidate = $this->captureOfficialFromMergedText($data, $r)
                ?: $this->captureOfficialFromMergedText($data, $r + 1);
            if ($candidate === null) {
                continue;
            }

            $official[$type] = ['label' => mb_substr($fullLabel, 0, 80)] + $candidate;
        }

        return $official;
    }

    /**
     * Baca angka rekap per-kolom (layout klasik); null bila semuanya nol/kosong.
     *
     * @param array{row: int, months: array<int, int>, total: ?int, ...} $header
     *
     * @return array{total: int, months: array<int, int>}|null
     */
    protected function captureOfficialFromColumns(array $data, array $header, int $r): ?array
    {
        $months = [];
        foreach ($header['months'] as $month => $col) {
            $months[$month] = $this->parseUnits($this->cell($data, $r, $col));
        }
        $total = $header['total'] !== null
            ? $this->parseUnits($this->cell($data, $r, $header['total']))
            : array_sum($months);
        if ($total == 0) {
            $total = array_sum($months);
        }
        if ($total == 0 && array_sum($months) == 0) {
            return null;
        }

        return ['total' => $total, 'months' => $months];
    }

    /**
     * Ekstraksi baris rekap dari SEL GABUNGAN raksasa. Tiga varian nyata:
     *   1) bulanan(12/7)+total+kumulatif — "… 84,149 … 105,354 1,048,040 …"
     *   2) total-tahunan tunggal — P-cell "1.005.802\n100%" (kasus 2023)
     *   3) YTD parsial dengan dash pengisi — "… 81,115 - - - - - 517.742 …"
     * Algoritma: persen dibuang; token numerik dipindai berurutan; batas
     * total = token pertama (indeks ≥4) yang nilainya ≥ jumlah semua token
     * sebelumnya (toleransi pembulatan 2%). Singleton → total-only.
     *
     * @return array{total: int, months: array<int, int>}|null
     */
    protected function captureOfficialFromMergedText(array $data, int $r): ?array
    {
        foreach ([$r, $r + 1, $r - 1] as $rr) {
            $row = $data[$rr] ?? [];
            $parts = [];
            foreach ($row as $value) {
                $sv = trim((string) $value);
                if ($sv !== '') {
                    $parts[] = $sv;
                }
            }
            $text = implode(' ', $parts);
            $text = preg_replace('/\d+\s*%/', '', $text) ?? '';
            // Terima dua gaya penulisan: ber-grup ribuan (84,149 / 84.149)
            // maupun angka panjang polos tanpa pemisah.
            if (! preg_match('/\d{1,3}(?:[.,]\d{3})+/', $text) && ! preg_match('/\d{5,}/', $text)) {
                continue;
            }

            preg_match_all('/\d{1,3}(?:[.,]\d{3})+|\d{4,}/', $text, $matches);
            $values = array_map(fn ($token) => (int) preg_replace('/[.,]/', '', $token), $matches[0]);
            if ($values === []) {
                continue;
            }

            // Varian singleton: hanya satu angka ⇒ itu total tahunan.
            if (count($values) === 1) {
                $total = $values[0];

                return ($total >= 1000 && $total <= 50_000_000)
                    ? ['total' => $total, 'months' => []]
                    : null;
            }

            // Cari batas total: token pertama ≥ running-sum sebelumnya (2% bawah).
            $sumBefore = 0;
            $totalIndex = null;
            foreach ($values as $i => $value) {
                if ($i >= 4 && $sumBefore > 0 && $value >= $sumBefore - intdiv($sumBefore, 50)) {
                    $totalIndex = $i;
                    break;
                }
                $sumBefore += $value;
            }
            if ($totalIndex === null || $totalIndex < 4) {
                continue;
            }

            $total = $values[$totalIndex];
            if ($total < 1000 || $total > 50_000_000) {
                continue;
            }

            $months = [];
            foreach (array_slice($values, 0, $totalIndex) as $i => $unit) {
                $months[$i + 1] = $unit;
            }

            return ['total' => $total, 'months' => $months];
        }

        return null;
    }

    protected function matchSegment(string $label): ?string
    {
        $label = trim(preg_replace('/\s+/', ' ', $label) ?? '');
        foreach (self::SEGMENT_PATTERNS as $pattern => $segment) {
            if (preg_match($pattern, $label)) {
                // Label section pendek ("4X2", "SEDAN"); nama model tidak
                // diawali kata-kata ini karena label berisi brand lebih dulu.
                return mb_strlen($label) <= 40 ? $segment : null;
            }
        }

        return null;
    }

    protected function isSummaryRow(string $label): bool
    {
        if (is_numeric($label)) {
            return true; // nomor baris di kolom paling kiri
        }

        return (bool) preg_match(self::SUMMARY_SKIP, $label);
    }

    /**
     * Parse angka unit GAIKINDO: int; float Eropa (4.936 = 4.936 unit, 3
     * desimal eksak); string ribuan "4.936"; "-" / kosong → 0; koreksi bisa
     * negatif (-11).
     */
    protected function parseUnits(mixed $value): int
    {
        if ($value === null || $value === '' || is_bool($value)) {
            return 0;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            if (floor($value) == $value) {
                return (int) $value;
            }
            $shifted = $value * 1000;

            return abs($shifted - round($shifted)) < 0.001 ? (int) round($shifted) : (int) round($value);
        }

        $s = trim((string) $value);
        if ($s === '' || in_array($s, ['-', '–', '—', 'N/A', 'NA', '*'], true)) {
            return 0;
        }
        $s = str_replace([' ', ','], ['', '.'], $s);
        if (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $s)) {
            return (int) str_replace('.', '', $s);
        }
        if (is_numeric($s)) {
            return $this->parseUnits((float) $s);
        }
        if (preg_match('/-?\d+/', $s, $m)) {
            return (int) $m[0];
        }

        return 0;
    }

    /** Klasifikasi powertrain dari sinyal kolom FUEL / CC / TANK / nama model. */
    protected function classifyPowertrain(string $fuel, string $cc, ?float $kwh, string $model): string
    {
        $fuelUpper = mb_strtoupper(preg_replace('/\s+/', ' ', trim($fuel)) ?? '');
        if ($fuelUpper !== '') {
            if (str_contains($fuelUpper, 'PHEV') || str_contains($fuelUpper, 'REEV')) {
                return 'PHEV';
            }
            if (str_contains($fuelUpper, 'HEV') || str_contains($fuelUpper, 'HYBRID')) {
                return 'HEV';
            }
            if ($fuelUpper === 'EV' || str_contains($fuelUpper, 'BEV') || str_contains($fuelUpper, 'ELECTRIC')) {
                return 'BEV';
            }
            // Pemecahan konvensional (2026-09): exact match agar 'D' tidak
            // tertangkap substring lain; selain ini tetap 'ICE'.
            if (in_array($fuelUpper, ['G', 'BENZIN', 'GASOLINE', 'PETROL'], true)) {
                return 'G';
            }
            if (in_array($fuelUpper, ['D', 'DIESEL'], true)) {
                return 'D';
            }
            if ($fuelUpper === 'CNG') {
                return 'CNG';
            }
            if (in_array($fuelUpper, ['FCEV', 'H2', 'HYDROGEN'], true)) {
                return 'FCEV';
            }

            return 'ICE';
        }

        // Tanpa kolom FUEL (format 2026): CC kosong + TANK ber-kWh → BEV.
        $ccClean = str_replace(['-', '–', ' '], '', $cc);
        if (($ccClean === '' || $ccClean === '0') && $kwh !== null) {
            return 'BEV';
        }

        // Sinyal dari nama model: powertrain eksplisit menang.
        if (preg_match('/\bPHEV\b|REEV|\bSHS\b/i', $model)) {
            return 'PHEV';
        }
        if (preg_match('/\bHEV\b|HYBRID/i', $model)) {
            return 'HEV';
        }
        if (preg_match('/\bBEV\b|\bEV\b/i', $model)) {
            return 'BEV';
        }

        foreach (self::KNOWN_BEV_PATTERNS as $pattern) {
            if (preg_match($pattern, $model)) {
                return 'BEV';
            }
        }

        return 'ICE';
    }

    // ------------------------------------------------------------------ persist

    protected function persistStats(int $importId, array $rows): int
    {
        $now = now()->format('Y-m-d H:i:s');
        $batch = [];
        $count = 0;
        $matchCache = [];

        foreach ($rows as $row) {
            $key = $row['brand'] . '||' . $row['model'];
            if (! isset($matchCache[$key])) {
                $matchCache[$key] = $this->matcher->match($row['brand'], $row['model'], $row['kwh']);
            }
            $match = $matchCache[$key];

            $base = [
                'sales_import_id' => $importId,
                'raw_brand' => mb_substr($row['brand'], 0, 255),
                'raw_model' => mb_substr($row['model'], 0, 255),
                'brand_vehicle_id' => $match['brand_vehicle_id'],
                'model_vehicle_id' => $match['model_vehicle_id'],
                'segment' => $row['segment'],
                'powertrain' => $row['powertrain'],
                'year' => $this->importYear,
                'origin_country' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            // Baris bulanan + satu agregat tahunan (month NULL).
            foreach ($row['months'] as $month => $units) {
                if ($units == 0) {
                    continue;
                }
                $batch[] = $base + ['month' => $month, 'units' => $units];
                $count++;
            }
            $batch[] = $base + ['month' => null, 'units' => $row['total']];
            $count++;

            if (count($batch) >= 500) {
                $this->insertStats($batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $this->insertStats($batch);
        }

        return $count;
    }

    protected function insertStats(array $batch): void
    {
        VehicleSalesStat::query()->getConnection()
            ->table((new VehicleSalesStat)->getTable())
            ->insert($batch);
    }

    protected function detectYear(string $filePath): int
    {
        if (preg_match('/(20\d{2})/', basename($filePath), $m)) {
            return (int) $m[1];
        }

        throw new \InvalidArgumentException('Tahun tidak terdeteksi dari nama file '.$filePath.' — gunakan --year=YYYY.');
    }

    protected function connectionName(): string
    {
        return app()->environment('testing') ? config('database.default') : 'ev';
    }

    /** Akses sel hasil toArray(returnCellRef=true): key baris/kolom "A1". */
    protected function cell(array $data, int $row, ?int $col): mixed
    {
        if ($col === null || $col < 1) {
            return null;
        }
        $letter = Coordinate::stringFromColumnIndex($col);

        return $data[$row][$letter] ?? $data[(string) $row][$letter] ?? null;
    }
}
