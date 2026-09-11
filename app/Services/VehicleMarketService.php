<?php

namespace App\Services;

use App\Models\SalesImport;
use App\Models\VehicleSalesStat;
use App\Models\TypeVehicle;
use App\Models\ModelVehicle;
use Illuminate\Support\Facades\Cache;

/**
 * Agregasi data pasar kendaraan untuk API publik /vehicle-market/*.
 *
 * Cache 24 jam dengan version-key: importer menaikkan versi lewat flush()
 * sehingga angka langsung segar setelah import baru tanpa menunggu TTL.
 */
class VehicleMarketService
{
    public const TTL = 86400;

    /**
     * Bucket agregasi komposisi. Sejak 2026-09 powertrain dipecah: ICE →
     * G/D (+ CNG, FCEV). Bucket KONVENSIONAL menampung legacy `ICE` dan
     * nilai pecahan baru sehingga stats lama tetap terhitung; FCEV berdiri
     * sendiri (field API `fcev_units`, additive — app lama aman).
     */
    public const CONVENTIONAL_POWERTRAINS = ['ICE', 'G', 'D', 'CNG'];
    public const FCEV_POWERTRAINS = ['FCEV'];

    /**
     * Meta data untuk klien cek apakah ada data baru (Revisi 2, poin 5).
     * Query murah: 2 agregat + version counter cache.
     */
    public function meta(): array
    {
        $latestImport = SalesImport::query()
            ->selectRaw('MAX(created_at) as last_import_at, MAX(id) as latest_import_id, MAX(year) as latest_year')
            ->first();

        $latestMonth = VehicleSalesStat::query()
            ->latestImports()
            ->monthly()
            ->where('year', $latestImport->latest_year ?? now()->year)
            ->max('month');

        $dataVersion = Cache::rememberForever($this->versionKey(), fn () => 1);

        // MAX(created_at) hasil agregat SQL berupa STRING (tanpa cast model) —
        // bungkus Carbon manual agar ISO8601 konsisten.
        $lastImportAt = $latestImport->last_import_at !== null
            ? \Illuminate\Support\Carbon::parse($latestImport->last_import_at)->toISOString()
            : null;

        return [
            'last_import_at' => $lastImportAt,
            'latest_import_id' => $latestImport->latest_import_id ? (int) $latestImport->latest_import_id : null,
            'data_version' => (int) $dataVersion,
            'latest_year' => $latestImport->latest_year ? (int) $latestImport->latest_year : null,
            'latest_month' => $latestMonth ? (int) $latestMonth : null,
        ];
    }

    public function summary(): array
    {
        return $this->cached('summary', function () {
            $annual = VehicleSalesStat::query()
                ->latestImports()
                ->whereNull('month')
                ->selectRaw('year, powertrain, SUM(units) as units')
                ->groupBy('year', 'powertrain')
                ->get();

            $official = $this->officialTotalsByYear();
            $monthCoverage = $this->monthCoverageByYear();

            $years = [];
            foreach ($annual->pluck('year')->unique()->sort()->values() as $year) {
                $byPower = $annual->where('year', $year);
                $bev = (int) $byPower->where('powertrain', 'BEV')->sum('units');
                $phev = (int) $byPower->where('powertrain', 'PHEV')->sum('units');
                $hev = (int) $byPower->where('powertrain', 'HEV')->sum('units');
                $ice = (int) $byPower->whereIn('powertrain', self::CONVENTIONAL_POWERTRAINS)->sum('units');
                $fcev = (int) $byPower->whereIn('powertrain', self::FCEV_POWERTRAINS)->sum('units');
                $parsed = $bev + $phev + $hev + $ice + $fcev;
                $officialTotal = $official[$year]['total'] ?? null;
                $base = $officialTotal ?? $parsed;

                $years[] = [
                    'year' => (int) $year,
                    'total_units' => $parsed,
                    'official_total' => $officialTotal,
                    'bev_units' => $bev,
                    'phev_units' => $phev,
                    'hev_units' => $hev,
                    'ice_units' => $ice,
                    'fcev_units' => $fcev,
                    'bev_share' => $base > 0 ? round($bev / $base, 4) : null,
                    'is_full_year' => ($monthCoverage[$year] ?? 0) >= 12,
                ];
            }

            // Ringkasan tahun terbaru + pertumbuhan YoY (hanya bila tahun penuh —
            // membandingkan tahun parsial dengan tahun penuh menyesatkan).
            $latest = null;
            if ($years !== []) {
                $latestYear = $years[count($years) - 1];
                $prevIndex = count($years) - 2;
                $growth = null;
                if ($prevIndex >= 0 && $latestYear['is_full_year'] && $latestYear['bev_units'] > 0 && $years[$prevIndex]['bev_units'] > 0) {
                    $growth = round(
                        ($latestYear['bev_units'] - $years[$prevIndex]['bev_units']) / $years[$prevIndex]['bev_units'],
                        4
                    );
                }
                $latest = [
                    'year' => $latestYear['year'],
                    'bev_units' => $latestYear['bev_units'],
                    'bev_share' => $latestYear['bev_share'],
                    'bev_yoy_growth' => $growth,
                    'is_full_year' => $latestYear['is_full_year'],
                ];
            }

            return ['years' => $years, 'latest' => $latest];
        });
    }

    public function trend(int|string|null $year = null, ?string $brand = null, ?string $model = null, ?string $powertrain = null): array
    {
        // Revisi 2, poin 7: powertrain default BEV untuk scope forecast
        $powertrain = strtoupper($powertrain ?? 'BEV');

        // Mode "Semua Tahun" → pola musiman: rata-rata share tiap bulan
        // kalender lintas tahun (bukan akumulasi mentah yang bias ke tahun besar).
        if ($year === 'all') {
            $key = "trend:v3:all:" . ($brand ?? '-') . ':' . ($model ?? '-') . ":{$powertrain}";

            return $this->cached($key, fn () => $this->seasonalTrend($brand, $model, $powertrain));
        }

        // Default: tahun terbaru yang BENAR-BENAR punya baris bulanan (di
        // scope filter bila ada) — bukan now()->year yang bisa kosong.
        $year ??= $this->latestMonthlyYear($brand, $model);
        $key = "trend:v3:{$year}:" . ($brand ?? '-') . ':' . ($model ?? '-') . ":{$powertrain}";

        return $this->cached($key, function () use ($year, $brand, $model, $powertrain) {
            $query = VehicleSalesStat::query()
                ->latestImports()
                ->monthly()
                ->where('year', $year);
            if ($brand !== null) {
                $query->where('raw_brand', $brand);
            }
            if ($model !== null) {
                $query->where('raw_model', $model);
            }
            $monthly = $query
                ->selectRaw('month, powertrain, SUM(units) as units')
                ->groupBy('month', 'powertrain')
                ->get();

            // Total resmi GAIKINDO hanya valid utk scope NASIONAL — saat
            // difilter per brand/model, market_total = hasil parse.
            $officialMonths = ($brand === null && $model === null)
                ? ($this->officialTotalsByYear()[$year]['months'] ?? [])
                : [];

            $months = [];
            foreach (range(1, 12) as $m) {
                $byPower = $monthly->where('month', $m);
                $bev = (int) $byPower->where('powertrain', 'BEV')->sum('units');
                $phev = (int) $byPower->where('powertrain', 'PHEV')->sum('units');
                $hev = (int) $byPower->where('powertrain', 'HEV')->sum('units');
                $ice = (int) $byPower->whereIn('powertrain', self::CONVENTIONAL_POWERTRAINS)->sum('units');
                $fcev = (int) $byPower->whereIn('powertrain', self::FCEV_POWERTRAINS)->sum('units');
                if ($bev + $phev + $hev + $ice + $fcev === 0 && ! isset($officialMonths[$m])) {
                    continue;
                }
                $months[] = [
                    'month' => $m,
                    'bev_units' => $bev,
                    'phev_units' => $phev,
                    'hev_units' => $hev,
                    'ice_units' => $ice,
                    'fcev_units' => $fcev,
                    'market_total' => $officialMonths[$m] ?? ($bev + $phev + $hev + $ice + $fcev),
                ];
            }

            // Revisi 2, poin 7: forecast scope = powertrain (default BEV)
            $forecast = $this->forecastFor($months, $brand, $model, $powertrain);
            if ($forecast !== null) {
                $forecast['powertrain'] = $powertrain;
            }

            return [
                'year' => $year,
                'months' => $months,
                'forecast' => $forecast,
            ];
        });
    }

    /**
     * Pola musiman lintas tahun (mode year=all): per bulan kalender,
     * avg_share = rata-rata (units bulan / total tahun yang sama) — total
     * tahun diambil dari baris BULANAN agar share konsisten dgn pembilang.
     */
    protected function seasonalTrend(?string $brand, ?string $model, string $powertrain = 'BEV'): array
    {
        $rows = $this->monthlyRows($brand, $model)
            ->selectRaw('year, month, powertrain, SUM(units) as units')
            ->groupBy('year', 'month', 'powertrain')
            ->get();

        $yearlyTotals = [];
        foreach ($rows->groupBy('year') as $yearRows) {
            $yearlyTotals[$yearRows[0]->year] = (int) $yearRows->sum('units');
        }

        $months = [];
        foreach (range(1, 12) as $m) {
            $byMonth = $rows->where('month', $m);
            $bev = (int) $byMonth->where('powertrain', 'BEV')->sum('units');
            $phev = (int) $byMonth->where('powertrain', 'PHEV')->sum('units');
            $hev = (int) $byMonth->where('powertrain', 'HEV')->sum('units');
            $ice = (int) $byMonth->whereIn('powertrain', self::CONVENTIONAL_POWERTRAINS)->sum('units');
            $fcev = (int) $byMonth->whereIn('powertrain', self::FCEV_POWERTRAINS)->sum('units');

            // Satu (year, month) bisa punya beberapa baris powertrain —
            // agregasi per TAHUN dulu, baru share per tahun dirata-ratakan.
            $unitsByYear = [];
            foreach ($byMonth as $row) {
                $unitsByYear[$row->year] = ($unitsByYear[$row->year] ?? 0) + (int) $row->units;
            }
            $shares = [];
            $unitsSum = 0;
            foreach ($unitsByYear as $y => $units) {
                $total = $yearlyTotals[$y] ?? 0;
                if ($total <= 0) {
                    continue;
                }
                $shares[] = $units / $total;
                $unitsSum += $units;
            }

            $months[] = [
                'month' => $m,
                'bev_units' => $bev,
                'phev_units' => $phev,
                'hev_units' => $hev,
                'ice_units' => $ice,
                'fcev_units' => $fcev,
                'market_total' => $bev + $phev + $hev + $ice + $fcev,
                'avg_share' => $shares === [] ? null : round(array_sum($shares) / count($shares), 4),
                'avg_units' => $shares === [] ? null : (int) round($unitsSum / count($shares)),
                'years_counted' => count($shares),
            ];
        }

        return ['year' => null, 'months' => $months];
    }

    /**
     * Prediksi bulan sisa untuk tahun yang belum penuh — metode musiman
     * historis (bobot bulan dari tahun-tahun PENUH dalam scope sama),
     * fallback run-rate bila histori tak memadai/guard bobot gagal.
     * Null bila tahun penuh atau tidak ada data bulanan.
     *
     * Revisi 2, poin 7: scope forecast = powertrain (default BEV).
     *
     * @param array<int, array{month: int, bev_units: int, phev_units: int, hev_units: int, ice_units: int, fcev_units?: int, market_total: int}> $months
     */
    protected function forecastFor(array $months, ?string $brand = null, ?string $model = null, string $powertrain = 'BEV'): ?array
    {
        $dataMonths = [];
        $ytd = 0;
        foreach ($months as $m) {
            // Revisi 2, poin 7: hitung YTD berdasarkan powertrain scope.
            // Scope 'ICE' = bucket konvensional (ICE+G+D+CNG) karena angka
            // per bulan sudah diagregasi per bucket; scope granular G/D/CNG
            // tidak punya field bucket sendiri → jatuh ke default (BEV).
            $fcevUnits = $m['fcev_units'] ?? 0;
            $units = match ($powertrain) {
                'BEV' => $m['bev_units'],
                'PHEV' => $m['phev_units'],
                'HEV' => $m['hev_units'],
                'ICE' => $m['ice_units'],
                'FCEV' => $fcevUnits,
                'ALL' => $m['bev_units'] + $m['phev_units'] + $m['hev_units'] + $m['ice_units'] + $fcevUnits,
                'EV' => $m['bev_units'] + $m['phev_units'],
                default => $m['bev_units'],
            };
            if ($units > 0) {
                $dataMonths[$m['month']] = $units;
                $ytd += $units;
            }
        }
        if ($dataMonths === [] || $ytd <= 0) {
            return null;
        }
        $lastMonth = max(array_keys($dataMonths));
        if ($lastMonth >= 12) {
            return null; // tahun penuh — tidak ada yang perlu diprediksi
        }

        $weights = $this->seasonalWeights($brand, $model, $powertrain);
        if ($weights !== null) {
            $wYtd = 0.0;
            for ($m = 1; $m <= $lastMonth; $m++) {
                $wYtd += $weights[$m];
            }
            $wTotal = array_sum($weights);
            // Guard bobot waras: YTD tak boleh nyaris penuh (proyeksi meledak)
            // dan Σ share tahun penuh harus ≈ 1.
            if ($wYtd >= 0.05 && $wYtd <= 0.99 && $wTotal >= 0.95 && $wTotal <= 1.05) {
                $projected = (int) round($ytd / $wYtd);

                return $this->buildForecast('seasonal', $projected, $lastMonth,
                    fn (int $m, int $p) => (int) round($p * $weights[$m]));
            }
        }

        // Fallback run-rate: rata-rata bulanan YTD × 12, sisa bulan seragam.
        $projected = (int) round($ytd / $lastMonth * 12);

        return $this->buildForecast('runrate', $projected, $lastMonth,
            fn (int $m, int $p) => (int) round($p / 12));
    }

    /** @param callable(int, int): int $unitFor */
    protected function buildForecast(string $method, int $projected, int $lastMonth, callable $unitFor): array
    {
        $out = ['method' => $method, 'projected_total' => $projected, 'last_data_month' => $lastMonth, 'months' => []];
        for ($m = $lastMonth + 1; $m <= 12; $m++) {
            $out['months'][] = ['month' => $m, 'units' => $unitFor($m, $projected)];
        }

        return $out;
    }

    /**
     * Bobot musiman w_m (Σ ≈ 1) dari tahun-tahun PENUH (12 bulan berisi)
     * dalam scope filter yang sama; null bila tidak ada tahun penuh.
     * Revisi 2, poin 7: scope = powertrain (default BEV).
     * @return array<int, float>|null
     */
    protected function seasonalWeights(?string $brand = null, ?string $model = null, string $powertrain = 'BEV'): ?array
    {
        $rows = $this->monthlyRows($brand, $model)
            ->where('powertrain', $powertrain)
            ->selectRaw('year, month, SUM(units) as units')
            ->groupBy('year', 'month')
            ->get();

        $byYear = [];
        foreach ($rows as $row) {
            if ((int) $row->units <= 0) {
                continue;
            }
            $byYear[$row->year][(int) $row->month] = (int) $row->units;
        }

        $fullYears = array_filter($byYear, fn ($monthsArr) => count($monthsArr) >= 12);
        if ($fullYears === []) {
            return null;
        }

        $weights = array_fill(1, 12, 0.0);
        foreach ($fullYears as $monthsArr) {
            $total = array_sum($monthsArr);
            if ($total <= 0) {
                continue;
            }
            foreach ($monthsArr as $m => $units) {
                $weights[$m] += $units / $total;
            }
        }
        $n = count($fullYears);

        return array_map(fn ($w) => round($w / $n, 6), $weights);
    }

    /** Query bulanan ber-scope filter brand/model (tanpa filter tahun). */
    protected function monthlyRows(?string $brand, ?string $model)
    {
        $query = VehicleSalesStat::query()->latestImports()->monthly();
        if ($brand !== null) {
            $query->where('raw_brand', $brand);
        }
        if ($model !== null) {
            $query->where('raw_model', $model);
        }

        return $query;
    }

    /** Tahun terbaru yang punya baris bulanan, opsional terbatas brand/model. */
    protected function latestMonthlyYear(?string $brand, ?string $model): int
    {
        $query = VehicleSalesStat::query()->latestImports()->monthly();
        if ($brand !== null) {
            $query->where('raw_brand', $brand);
        }
        if ($model !== null) {
            $query->where('raw_model', $model);
        }

        return (int) ($query->max('year') ?: now()->year);
    }

    public function top(int|string|null $year = null, ?string $powertrain = null, ?string $brand = null, int $limit = 10): array
    {
        $isAll = $year === 'all';
        // Default: tahun terbaru yang punya baris LEVEL MODEL — tahun yang
        // hanya berisi rekap nasional menghasilkan ranking kosong.
        if (! $isAll) {
            $year ??= $this->latestModelYear($brand);
        }
        $powertrain = strtoupper($powertrain ?? 'BEV');
        $cacheYear = $isAll ? 'all' : $year;
        $key = "top:v5:{$cacheYear}:{$powertrain}:" . ($brand ?? '-') . ":{$limit}";

        return $this->cached($key, function () use ($year, $isAll, $powertrain, $brand, $limit) {
            $brandLogoMap = $this->rawBrandLogoMap();

            $base = VehicleSalesStat::query()
                ->latestImports()
                ->whereNull('month')
                ->powertrain($powertrain);
            if (! $isAll) {
                $base->where('year', $year);
            }
            if ($brand !== null) {
                $base->where('raw_brand', $brand);
            }

            $brands = (clone $base)
                ->selectRaw('raw_brand as brand, SUM(units) as units, COUNT(DISTINCT model_vehicle_id) as models')
                ->groupBy('raw_brand')
                ->orderByDesc('units')
                ->limit($limit)
                ->get()
                ->map(fn ($r) => [
                    'brand' => $r->brand,
                    'units' => (int) $r->units,
                    'models' => (int) $r->models,
                    'logo_url' => $brandLogoMap[mb_strtolower(trim((string) $r->brand))] ?? null,
                ])
                ->values();

            // Level keluarga model: baris ter-link digroup per katalog model
            // (nama keluarga, bukan varian/type). Belum ter-link → per raw.
            // Scope powertrain via scope bawaan (satu-satunya kolom powertrain
            // setelah join — kolom di model_vehicles sudah di-drop) sehingga
            // ALL/EV juga benar, bukan where `= 'ALL'` yang selalu kosong.
            $linkedQuery = VehicleSalesStat::query()
                ->latestImports()
                ->whereNull('month')
                ->powertrain($powertrain)
                ->whereNotNull('vehicle_sales_stats.model_vehicle_id')
                ->join('model_vehicles', 'model_vehicles.id', '=', 'vehicle_sales_stats.model_vehicle_id')
                ->join('brand_vehicles', 'brand_vehicles.id', '=', 'model_vehicles.brand_vehicle_id');
            if (! $isAll) {
                $linkedQuery->where('vehicle_sales_stats.year', $year);
            }
            $linkedModels = $linkedQuery
                ->selectRaw('model_vehicles.id as model_id, model_vehicles.name as model, brand_vehicles.name as brand, vehicle_sales_stats.powertrain as powertrain, SUM(vehicle_sales_stats.units) as units')
                ->groupBy('model_vehicles.id', 'model_vehicles.name', 'brand_vehicles.name', 'vehicle_sales_stats.powertrain')
                ->orderByDesc('units')
                ->limit($limit)
                ->get()
                ->map(fn ($r) => [
                    'brand' => $r->brand,
                    'model' => $r->model,
                    'model_vehicle_id' => $r->model_id,
                    'powertrain' => $r->powertrain,
                    'units' => (int) $r->units,
                ])
                ->values();

            $unlinkedModels = (clone $base)
                ->whereNull('model_vehicle_id')
                ->selectRaw('raw_brand as brand, raw_model as model, powertrain, SUM(units) as units')
                ->groupBy('raw_brand', 'raw_model', 'powertrain')
                ->orderByDesc('units')
                ->limit($limit)
                ->get()
                ->map(fn ($r) => [
                    'brand' => $r->brand,
                    'model' => $r->model,
                    'model_vehicle_id' => null,
                    'powertrain' => $r->powertrain,
                    'units' => (int) $r->units,
                ])
                ->values();

            $models = $linkedModels
                ->concat($unlinkedModels)
                ->sortByDesc('units')
                ->take($limit)
                ->map(fn ($m) => array_merge($m, [
                    'brand_logo_url' => $brandLogoMap[mb_strtolower(trim((string) $m['brand']))] ?? null,
                    'image_url' => $this->resolveModelImageUrl($m['model_vehicle_id'], $m['powertrain']),
                ]))
                ->values();

            // Klaster induk perusahaan: agregasi SEMUA brand (TANPA limit —
            // grup bisa kehilangan anggota di luar top-10) lalu map ke grup
            // via katalog. Brand tanpa grup katalog = grup mandiri atas
            // nama raw-nya sendiri (tidak pernah dibuang).
            $groupByName = $this->rawBrandGroupMap();

            $grouped = [];
            foreach (
                (clone $base)
                    ->selectRaw('raw_brand as brand, SUM(units) as units, COUNT(DISTINCT model_vehicle_id) as models')
                    ->groupBy('raw_brand')
                    ->get()
                as $row
            ) {
                $groupName = $groupByName[mb_strtolower(trim((string) $row->brand))] ?? null;
                $key = $groupName ?? (string) $row->brand;
                if (! isset($grouped[$key])) {
                    $grouped[$key] = ['group' => $key, 'units' => 0, 'models' => 0, 'brands' => []];
                }
                $grouped[$key]['units'] += (int) $row->units;
                $grouped[$key]['models'] += (int) $row->models;
                $grouped[$key]['brands'][] = [
                    'brand' => $row->brand,
                    'units' => (int) $row->units,
                    'logo_url' => $brandLogoMap[mb_strtolower(trim((string) $row->brand))] ?? null,
                ];
            }

            $groups = collect($grouped)
                ->sortByDesc('units')
                ->take($limit)
                ->map(fn ($g) => [
                    'group' => $g['group'],
                    'units' => $g['units'],
                    'models' => $g['models'],
                    'brands' => collect($g['brands'])->sortByDesc('units')->values()->all(),
                ])
                ->values()
                ->all();

            return [
                'year' => $isAll ? null : $year,
                'powertrain' => $powertrain,
                'brands' => $brands,
                'models' => $models,
                'groups' => $groups,
            ];
        });
    }

    /** Tahun terbaru yang punya baris level model; fallback tahun apa pun. */
    protected function latestModelYear(?string $brand): int
    {
        $query = VehicleSalesStat::query()
            ->latestImports()
            ->whereNull('month')
            ->whereNotNull('model_vehicle_id');
        if ($brand !== null) {
            $query->where('raw_brand', $brand);
        }
        $found = $query->max('year');

        return (int) ($found ?: VehicleSalesStat::query()->latestImports()->max('year'));
    }

    /**
     * Peta brand → model khusus kendaraan listrik (BEV / PHEV) untuk filter & katalog.
     * Brand diurutkan berdasarkan penjualan EV tahun sebelumnya (fallback tahun berjalan).
     * year=all → agregasi lintas tahun, urutkan by Σ units desc (abaikan prev_year).
     */
    public function catalog(int|string|null $year = null): array
    {
        $isAll = $year === 'all';
        if (! $isAll) {
            $year ??= (int) VehicleSalesStat::query()->latestImports()->whereNull('month')->max('year');
        }
        $cacheYear = $isAll ? 'all' : $year;
        $prevYear = $isAll ? null : $year - 1;

        return $this->cached("catalog:v8:{$cacheYear}", function () use ($year, $isAll, $prevYear) {
            $brandLogoMap = $this->rawBrandLogoMap();

            // Level KELUARGA MODEL (bukan varian/type): baris ter-link ke
            // katalog digroup per model_vehicle; yang belum ter-link tetap
            // ditampilkan per raw_model (agar data tidak hilang).
            $linkedQuery = VehicleSalesStat::query()
                ->latestImports()
                ->whereNull('month')
                ->whereIn('powertrain', ['BEV', 'PHEV'])
                ->whereNotNull('model_vehicle_id');
            $unlinkedQuery = VehicleSalesStat::query()
                ->latestImports()
                ->whereNull('month')
                ->whereIn('powertrain', ['BEV', 'PHEV'])
                ->whereNull('model_vehicle_id');
            if (! $isAll) {
                $linkedQuery->where('year', $year);
                $unlinkedQuery->where('year', $year);
            }
            $linked = $linkedQuery
                ->selectRaw('model_vehicle_id, powertrain, SUM(units) as units')
                ->groupBy('model_vehicle_id', 'powertrain')
                ->get();
            $unlinked = $unlinkedQuery
                ->selectRaw('raw_brand, raw_model, SUM(units) as units')
                ->groupBy('raw_brand', 'raw_model')
                ->get();

            // Lookup katalog: nama keluarga, brand, kategori, ukuran.
            $modelIds = $linked->pluck('model_vehicle_id')->filter()->unique()->values();
            $modelsById = \App\Models\ModelVehicle::query()
                ->whereIn('id', $modelIds)
                ->with('brandVehicle:id,name')
                ->get(['id', 'name', 'category', 'size_class', 'brand_vehicle_id'])
                ->keyBy('id');

            // Peta nama brand → nama grup induk (untuk badge katalog) —
            // termasuk raw alias via jalur link model.
            $groupByName = $this->rawBrandGroupMap();

            // Ambil penjualan tahun sebelumnya untuk pengurutan (skip saat all)
            $prevYearSales = [];
            if (! $isAll && $prevYear !== null) {
                $prevYearSales = VehicleSalesStat::query()
                    ->latestImports()
                    ->whereNull('month')
                    ->where('year', $prevYear)
                    ->whereIn('powertrain', ['BEV', 'PHEV'])
                    ->selectRaw('raw_brand, SUM(units) as units')
                    ->groupBy('raw_brand')
                    ->pluck('units', 'raw_brand')
                    ->map(fn ($u) => (int) $u)
                    ->all();
            }

            $brands = [];
            $addModel = function (string $brandName, string $modelName, int $units, ?string $category, ?string $sizeClass, ?string $powertrain, ?int $modelVehicleId) use (&$brands, &$prevYearSales, $groupByName, $brandLogoMap) {
                if (! isset($brands[$brandName])) {
                    $brands[$brandName] = [
                        'brand' => $brandName,
                        'units' => 0,
                        'group' => $groupByName[mb_strtolower(trim($brandName))] ?? null,
                        'logo_url' => $brandLogoMap[mb_strtolower(trim($brandName))] ?? null,
                        'prev_units' => $prevYearSales[$brandName] ?? 0,
                        'models' => [],
                    ];
                }
                $brands[$brandName]['units'] += $units;
                $brands[$brandName]['models'][] = [
                    'model' => $modelName,
                    'units' => $units,
                    'category' => $category,
                    'size_class' => $sizeClass,
                    'powertrain' => $powertrain,
                    'image_url' => $this->resolveModelImageUrl($modelVehicleId, $powertrain),
                ];
            };

            foreach ($linked as $row) {
                $model = $modelsById->get($row->model_vehicle_id);
                if ($model === null) {
                    continue;
                }
                $brandName = $model->brandVehicle?->name ?? $model->name;
                $addModel($brandName, $model->name, (int) $row->units, $model->category, $model->size_class, $row->powertrain, (int) $row->model_vehicle_id);
            }

            foreach ($unlinked as $row) {
                $addModel($row->raw_brand, $row->raw_model, (int) $row->units, null, null, $row->powertrain, null);
            }

            // Urutkan brand: saat all → Σ units desc; else prev_units desc fallback units
            if ($isAll) {
                $sortedBrands = collect($brands)
                    ->sort(fn ($a, $b) => $b['units'] <=> $a['units'])
                    ->values()
                    ->all();
            } else {
                $sortedBrands = collect($brands)
                    ->sort(function ($a, $b) {
                        if ($b['prev_units'] !== $a['prev_units']) {
                            return $b['prev_units'] <=> $a['prev_units'];
                        }
                        return $b['units'] <=> $a['units'];
                    })
                    ->values()
                    ->all();
            }

            return [
                'year' => $isAll ? null : $year,
                'brands' => $sortedBrands,
            ];
        });
    }

    /**
     * Komposisi penjualan per kategori kendaraan (level katalog model —
     * taksonomi App\Support\VehicleCategories). Baris tanpa link katalog
     * atau tanpa kategori dilaporkan terpisah (uncategorized_units) supaya
     * cakupan kategorisasi transparan, tidak diam-diam hilang.
     */
    public function categoryComposition(int|string|null $year = null, ?string $powertrain = null): array
    {
        $isAll = $year === 'all';
        if (! $isAll) {
            $year ??= (int) VehicleSalesStat::query()->latestImports()->whereNull('month')->max('year');
        }
        $powertrain = strtoupper($powertrain ?? 'ALL');
        $cacheYear = $isAll ? 'all' : $year;
        $key = "composition:v2:{$cacheYear}:{$powertrain}";

        return $this->cached($key, function () use ($year, $isAll, $powertrain) {
            $base = VehicleSalesStat::query()
                ->latestImports()
                ->whereNull('month')
                ->powertrain($powertrain);
            if (! $isAll) {
                $base->where('year', $year);
            }

            $totalUnits = (int) (clone $base)->sum('units');

            // LEFT JOIN kategori level model; NULL = tak ter-link / tanpa
            // kategori. COUNT DISTINCT hanya menghitung baris ter-link —
            // pasangan kategori↔jumlah model.
            $rows = (clone $base)
                ->leftJoin('model_vehicles', 'model_vehicles.id', '=', 'vehicle_sales_stats.model_vehicle_id')
                ->selectRaw('model_vehicles.category as category, SUM(vehicle_sales_stats.units) as units, COUNT(DISTINCT vehicle_sales_stats.model_vehicle_id) as models')
                ->groupBy('model_vehicles.category')
                ->get();

            $categories = [];
            $uncategorized = 0;
            foreach ($rows as $row) {
                if ($row->category === null || trim((string) $row->category) === '') {
                    $uncategorized += (int) $row->units;
                    continue;
                }
                $categories[] = [
                    'category' => $row->category,
                    'group' => \App\Support\VehicleCategories::groupOf($row->category),
                    'units' => (int) $row->units,
                    'models' => (int) $row->models,
                    'share' => $totalUnits > 0 ? round(((int) $row->units) / $totalUnits, 4) : 0.0,
                ];
            }
            usort($categories, fn ($a, $b) => $b['units'] <=> $a['units']);

            return [
                'year' => $isAll ? null : $year,
                'powertrain' => $powertrain,
                'total_units' => $totalUnits,
                'categories' => $categories,
                'uncategorized_units' => $uncategorized,
            ];
        });
    }

    /**
     * Histori penjualan per tahun untuk satu model kendaraan spesifik (brand + model),
     * khusus powertrain EV (BEV / PHEV).
     */
    public function modelHistory(string $brand, string $model): array
    {
        $key = 'model-history:v5:' . rawurlencode($brand) . ':' . rawurlencode($model);

        return $this->cached($key, function () use ($brand, $model) {
            $brandLogoMap = $this->rawBrandLogoMap();

            // Level keluarga: cocokkan ke katalog model (brand+nama model,
            // case-insensitive). Fallback: raw exact utk baris tak ter-link.
            $catalogModel = \App\Models\ModelVehicle::query()
                ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($model))])
                ->whereHas('brandVehicle', fn ($q) => $q->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($brand))]))
                ->first();

            $base = VehicleSalesStat::query()
                ->latestImports()
                ->whereNull('month')
                ->whereIn('powertrain', ['BEV', 'PHEV']);

            if ($catalogModel !== null) {
                $base->where('model_vehicle_id', $catalogModel->id);
            } else {
                $base->where('raw_brand', $brand)->where('raw_model', $model);
            }

            $rows = (clone $base)
                ->selectRaw('year, powertrain, SUM(units) as units')
                ->groupBy('year', 'powertrain')
                ->orderBy('year')
                ->get();

            $years = [];
            foreach ($rows as $row) {
                $years[] = [
                    'year' => (int) $row->year,
                    'powertrain' => $row->powertrain,
                    'units' => (int) $row->units,
                ];
            }

            // Revisi lanjutan: rincian BULANAN utk tahun terakhir yang punya
            // baris bulanan di scope model ini (chart "Bulanan" di detail).
            // Base terpisah — $base sudah whereNull(month) utk agregat tahunan.
            $monthlyBase = VehicleSalesStat::query()
                ->latestImports()
                ->whereIn('powertrain', ['BEV', 'PHEV']);
            if ($catalogModel !== null) {
                $monthlyBase->where('model_vehicle_id', $catalogModel->id);
            } else {
                $monthlyBase->where('raw_brand', $brand)->where('raw_model', $model);
            }

            $monthlyYear = (int) ((clone $monthlyBase)
                ->whereNotNull('month')
                ->max('year') ?: 0);
            $months = [];
            if ($monthlyYear > 0) {
                $monthRows = (clone $monthlyBase)
                    ->whereNotNull('month')
                    ->where('year', $monthlyYear)
                    ->selectRaw('month, powertrain, SUM(units) as units')
                    ->groupBy('month', 'powertrain')
                    ->orderBy('month')
                    ->get();
                foreach ($monthRows as $row) {
                    $months[] = [
                        'month' => (int) $row->month,
                        'powertrain' => $row->powertrain,
                        'units' => (int) $row->units,
                    ];
                }
            }

            // Daftar TYPE milik model ini ("tipe ada di detail") + total unit.
            $types = [];
            if ($catalogModel !== null) {
                $typeUnits = (clone $base)
                    ->whereNotNull('type_vehicle_id')
                    ->selectRaw('type_vehicle_id, SUM(units) as units')
                    ->groupBy('type_vehicle_id')
                    ->pluck('units', 'type_vehicle_id');

                $types = TypeVehicle::query()
                    ->where('model_vehicle_id', $catalogModel->id)
                    ->orderBy('name')
                    ->get(['id', 'name', 'battery_capacity', 'image'])
                    ->map(fn ($t) => [
                        'name' => $t->name,
                        'battery_capacity' => $t->battery_capacity,
                        'units' => (int) ($typeUnits[$t->id] ?? 0),
                        'image_url' => ! empty($t->image)
                            ? (str_starts_with($t->image, 'http') || str_starts_with($t->image, '//')
                                ? $t->image
                                : '/storage/' . ltrim($t->image, '/'))
                            : null,
                    ])
                    ->values()
                    ->all();
            }

            return [
                'brand' => $brand,
                'model' => $model,
                'model_vehicle_id' => $catalogModel?->id,
                'brand_logo_url' => $brandLogoMap[mb_strtolower(trim($brand))] ?? null,
                'total_units' => (int) $rows->sum('units'),
                'years' => $years,
                'monthly_year' => $monthlyYear > 0 ? $monthlyYear : null,
                'months' => $months,
                'types' => $types,
            ];
        });
    }

    /**
     * Peta raw_brand / nama brand katalog → URL logo publik (/storage/images/brand/...).
     * Mendukung alias raw_brand via join model_vehicles bila nama raw berbeda dari nama katalog.
     *
     * @return array<string, string> key = strtolower(trim(nama brand))
     */
    protected function rawBrandLogoMap(): array
    {
        $idToLogo = [];
        $map = [];

        foreach (
            \App\Models\BrandVehicle::query()
                ->whereNotNull('image')
                ->where('image', '!=', '')
                ->get(['id', 'name', 'image'])
            as $brand
        ) {
            $logoUrl = $brand->logo_url;
            if ($logoUrl === null) {
                continue;
            }
            $map[mb_strtolower(trim($brand->name))] = $logoUrl;
            $idToLogo[$brand->id] = $logoUrl;
        }

        if ($idToLogo === []) {
            return $map;
        }

        // Jalur raw alias via model_vehicles
        $votes = VehicleSalesStat::query()
            ->latestImports()
            ->whereNull('month')
            ->join('model_vehicles', 'model_vehicles.id', '=', 'vehicle_sales_stats.model_vehicle_id')
            ->whereIn('model_vehicles.brand_vehicle_id', array_keys($idToLogo))
            ->selectRaw('vehicle_sales_stats.raw_brand as raw_brand, model_vehicles.brand_vehicle_id as brand_id, SUM(vehicle_sales_stats.units) as units')
            ->groupBy('vehicle_sales_stats.raw_brand', 'model_vehicles.brand_vehicle_id')
            ->orderByDesc('units')
            ->get();

        foreach ($votes as $vote) {
            $key = mb_strtolower(trim((string) $vote->raw_brand));
            if (! isset($map[$key])) {
                $map[$key] = $idToLogo[$vote->brand_id];
            }
        }

        return $map;
    }

    /** Naikkan versi cache — dipanggil importer setelah import baru. */
    public function flush(): void
    {
        Cache::increment($this->versionKey()) || Cache::put($this->versionKey(), (int) (Cache::get($this->versionKey()) ?: 1) + 1, now()->addYear());
    }

    /**
     * Resolusi image_url untuk model+powertrain: type_vehicles dengan unit
     * terbanyak dalam scope → fallback model_vehicles.image → null.
     * URL absolut via accessor (pola Provider::image).
     *
     * Revisi 2, poin 1.
     */
    protected function resolveModelImageUrl(?int $modelVehicleId, ?string $powertrain): ?string
    {
        if ($modelVehicleId === null || $powertrain === null) {
            return null;
        }

        // Cari type_vehicle dengan unit terbanyak untuk model+powertrain ini
        $bestType = VehicleSalesStat::query()
            ->latestImports()
            ->whereNull('month')
            ->where('vehicle_sales_stats.model_vehicle_id', $modelVehicleId)
            ->where('vehicle_sales_stats.powertrain', $powertrain)
            ->whereNotNull('vehicle_sales_stats.type_vehicle_id')
            ->join('type_vehicles', 'type_vehicles.id', '=', 'vehicle_sales_stats.type_vehicle_id')
            ->selectRaw('type_vehicles.id, type_vehicles.image, SUM(vehicle_sales_stats.units) as units')
            ->groupBy('type_vehicles.id', 'type_vehicles.image')
            ->orderByDesc('units')
            ->first();

        if ($bestType !== null && ! empty($bestType->image)) {
            $path = $bestType->image;
            if (str_starts_with($path, 'http') || str_starts_with($path, '//')) {
                return $path;
            }

            return '/storage/' . ltrim($path, '/');
        }

        // Fallback: model_vehicles.image
        $model = ModelVehicle::query()->find($modelVehicleId);
        if ($model !== null && ! empty($model->image)) {
            $path = $model->image;
            if (str_starts_with($path, 'http') || str_starts_with($path, '//')) {
                return $path;
            }

            return '/storage/' . ltrim($path, '/');
        }

        return null;
    }

    /**
     * Peta raw_brand / nama brand katalog → nama grup induk, dua jalur:
     * (1) nama brand katalog yang punya grup; (2) raw alias yang tidak
     * cocok nama katalog (MORRIS GARAGE → MG, MITSUBUSHI → MITSUBISHI)
     * diikuti lewat brand katalog tempat mayoritas unitnya ter-link model.
     *
     * @return array<string, string> key = strtolower(trim(nama raw))
     */
    protected function rawBrandGroupMap(): array
    {
        $idToGroup = [];
        $map = [];
        foreach (
            \App\Models\BrandVehicle::query()
                ->whereNotNull('brand_group_id')
                ->with('brandGroup:id,name')
                ->get(['id', 'name', 'brand_group_id'])
            as $brand
        ) {
            $group = $brand->brandGroup?->name;
            if ($group === null) {
                continue;
            }
            $map[mb_strtolower(trim($brand->name))] = $group;
            $idToGroup[$brand->id] = $group;
        }

        if ($idToGroup === []) {
            return $map;
        }

        // Jalur (2): raw_brand → brand katalog dominan menurut Σ unit
        // baris ter-link (urut desc → entri pertama yang menang).
        $votes = VehicleSalesStat::query()
            ->latestImports()
            ->whereNull('month')
            ->join('model_vehicles', 'model_vehicles.id', '=', 'vehicle_sales_stats.model_vehicle_id')
            ->whereIn('model_vehicles.brand_vehicle_id', array_keys($idToGroup))
            ->selectRaw('vehicle_sales_stats.raw_brand as raw_brand, model_vehicles.brand_vehicle_id as brand_id, SUM(vehicle_sales_stats.units) as units')
            ->groupBy('vehicle_sales_stats.raw_brand', 'model_vehicles.brand_vehicle_id')
            ->orderByDesc('units')
            ->get();

        foreach ($votes as $vote) {
            $key = mb_strtolower(trim((string) $vote->raw_brand));
            if (! isset($map[$key])) {
                $map[$key] = $idToGroup[$vote->brand_id];
            }
        }

        return $map;
    }

    /** @return array<int, array{total: int, months: array<int, int>}> */
    protected function officialTotalsByYear(): array
    {
        $out = [];
        SalesImport::query()
            ->whereIn('id', function ($q) {
                $q->selectRaw('MAX(id)')->from('sales_imports')->groupBy('year');
            })
            ->get()
            ->each(function (SalesImport $import) use (&$out) {
                $meta = $import->meta ?? [];
                $grand = $meta['official']['grand'] ?? null;
                if ($grand === null) {
                    return;
                }
                $out[$import->year] = [
                    'total' => (int) ($grand['total'] ?? 0),
                    'months' => collect($grand['months'] ?? [])
                        ->map(fn ($u) => (int) $u)
                        ->reject(fn ($u) => $u === 0)
                        ->all(),
                ];
            });

        return $out;
    }

    /** @return array<int, int> jumlah bulan berisi data per tahun */
    protected function monthCoverageByYear(): array
    {
        return VehicleSalesStat::query()
            ->latestImports()
            ->monthly()
            ->selectRaw('year, COUNT(DISTINCT month) as months')
            ->where('units', '>', 0)
            ->groupBy('year')
            ->pluck('months', 'year')
            ->map(fn ($m) => (int) $m)
            ->all();
    }

    protected function cached(string $key, \Closure $fn): array
    {
        $version = Cache::rememberForever($this->versionKey(), fn () => 1);

        return Cache::remember("vehicle-market:v{$version}:{$key}", self::TTL, $fn);
    }

    protected function versionKey(): string
    {
        return 'vehicle-market:version';
    }
}
