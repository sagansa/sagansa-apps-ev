<?php

namespace App\Services;

use App\Models\BrandVehicle;
use App\Models\ModelVehicle;

/**
 * Memecah string "TYPE MODEL" GAIKINDO menjadi (MODEL keluarga, TYPE varian)
 * sesuai aturan pemilik data:
 *
 *  - MODEL dikelompokkan sesedikit mungkin: "Agya 1.2 G AT" → "Agya".
 *  - TYPE adalah string varian penuh: "All New Avanza 1.5 G AT" → MODEL
 *    "Avanza", awalan marketing tetap bagian TYPE.
 *  - Katalog didahulukan: bila model sudah ada di model_vehicles, pakai itu.
 *  - POWERTRAIN dari kolom FUEL → powertrain model katalog → sinyal nama →
 *    'ICE' (dengan flag agar direview).
 *
 * Service ini MURNI derivasi (tidak menulis DB); dipakai preview command dan
 * importer penjualan.
 */
class VehicleNameSplitter
{
    /** Awalan marketing: bukan bagian MODEL, tetap di dalam TYPE. */
    protected const MARKETING_WORDS = ['THE', 'ALL', 'NEW'];

    /** Token transmisi = tanda spesifikasi, batas akhir keluarga. */
    protected const TRANSMISSIONS = ['AT', 'MT', 'CVT', 'DCT', 'AMT', 'IVT'];

    /** Token bahan bakar/powertrain = batas akhir keluarga. */
    protected const FUEL_TOKENS = ['BEV', 'EV', 'HEV', 'PHEV', 'HV', 'HYBRID', 'HYBRI', 'MHEV', 'REEV', 'FCEV'];

    /** Kata trim/varian = batas akhir keluarga. */
    protected const TRIM_WORDS = [
        'PREMIUM', 'STANDARD', 'EXCLUSIVE', 'LUXURY', 'PRIME', 'SIGNATURE',
        'DYNAMIC', 'STYLE', 'ELEGANCE', 'COMFORT', 'TREND', 'IGNITE', 'MAGNIFY',
        'EXCITE', 'CALLIGRAPHY', 'LAUNCH', 'INNOVATIVE',
    ];

    /** Token teknis lain = batas akhir keluarga. */
    protected const OTHER_SPEC = ['CKD', 'CBU', 'RHD', 'LHD', 'FWD', 'AWD', 'RWD', '4X2', '4X4'];

    /** Pemetaan kolom FUEL GAIKINDO → powertrain kanonis (BEV|PHEV|HEV|ICE). */
    protected const FUEL_MAP = [
        'G' => 'ICE', 'BENSIN' => 'ICE', 'PETROL' => 'ICE', 'GASOLINE' => 'ICE',
        'D' => 'ICE', 'DIESEL' => 'ICE', 'CNG' => 'ICE',
        'BEV' => 'BEV', 'EV' => 'BEV', 'ELECTRIC' => 'BEV', 'LISTRIK' => 'BEV',
        'HEV' => 'HEV', 'HYBRID' => 'HEV', 'HYBRID+' => 'HEV', 'HYBRI' => 'HEV', 'MHEV' => 'HEV',
        'PHEV' => 'PHEV', 'REEV' => 'PHEV',
    ];

    /**
     * FUEL laporan GAIKINDO → bucket powertrain (G/D/CNG → ICE, Hybrid/HEV
     * → HEV, PHEV → PHEV, BEV/EV → BEV). Dipakai preview & worklist pending
     * sebagai referensi prefill — kebijakan admin 2026-10-30.
     */
    public static function fuelToPowertrain(?string $fuel): ?string
    {
        $key = strtoupper(trim((string) $fuel));

        return $key === '' || $key === '-' ? null : (self::FUEL_MAP[$key] ?? null);
    }

    /** Sinyal nama model listrik (dipakai bila kolom FUEL tidak ada, mis. 2026). */
    protected const BEV_NAME_PATTERNS = [
        '/\bI[3-8]\b/i',            // BMW i3..i8
        '/\bSEAL(U| \d| )?/i',      // BYD Seal*
        '/\bATTO\b/i',              // BYD Atto*
        '/\bDOLPHIN\b/i',           // BYD Dolphin
        '/\bBINGUO/i',              // Wuling Bingo
        '/\bAIR\s*EV\b/i',          // Wuling Air EV
        '/\bZEEKR\b/i',
        '/\bDENZA\b/i',
        '/\bSTARGAZER\b/i',         // Hyundai Stargazer X? — jangan; hapus kalau salah
    ];

    protected array $brandIdCache = [];

    protected array $modelsByBrand = [];

    /**
     * @return array{model: string, type: string, powertrain: string,
     *               confidence: string, flag: string|null,
     *               family_source: string, brand: string}
     */
    public function split(string $brand, string $typeModel, ?string $fuel = null): array
    {
        $brand = $this->clean($brand);
        $typeModel = $this->clean($typeModel);

        if (in_array($this->norm($typeModel), ['TOTAL', 'CUMULATIVE', ''], true)
            || in_array($this->norm($brand), ['TOTAL', 'CUMULATIVE', ''], true)) {
            return $this->result('', '', 'ICE', 'low', 'junk', 'junk', $brand);
        }

        $tokens = $this->tokens($typeModel);
        $tokens = $this->stripBrandTokens($brand, $tokens);
        [$tokens, $strippedMarketing] = $this->stripMarketingTokens($tokens);

        $remainderNorm = $this->norm(implode(' ', $tokens));

        // Nama model katalog sering memuat brand ("AION UT") — coba string
        // penuh dulu, baru sisa setelah brand dibuang.
        $catalogModel = $this->findCatalogModel($brand, $this->norm($typeModel))
            ?? $this->findCatalogModel($brand, $remainderNorm);

        if ($catalogModel !== null) {
            return $this->result(
                $catalogModel->name,
                $typeModel,
                $this->resolvePowertrain($fuel, null, $typeModel, $confidence),
                'high',
                null,
                'catalog',
                $brand,
            );
        }

        [$familyTokens, $familyFlag] = $this->deriveFamily($tokens);

        $powertrain = $this->resolvePowertrain($fuel, null, $typeModel, $ptFlag);

        return $this->result(
            implode(' ', $familyTokens),
            $typeModel,
            $powertrain,
            $familyFlag !== null || $ptFlag !== null ? 'low' : 'medium',
            $familyFlag ?? $ptFlag,
            'derived',
            $brand,
        );
    }

    protected function result(
        string $model,
        string $type,
        string $powertrain,
        string $confidence,
        ?string $flag,
        string $familySource,
        string $brand,
    ): array {
        return [
            'model' => $model,
            'type' => $type,
            'powertrain' => $powertrain,
            'confidence' => $confidence,
            'flag' => $flag,
            'family_source' => $familySource,
            'brand' => $brand,
        ];
    }

    /** Token keluarga: kumpulkan sampai token spesifikasi pertama. */
    protected function deriveFamily(array $tokens): array
    {
        $family = [];

        foreach ($tokens as $token) {
            if ($this->isSpecToken($this->norm($token))) {
                break;
            }

            $family[] = $token;
        }

        if ($family === []) {
            // Semua token adalah spec (mis. "CBU i4 ...") → ambil token pertama.
            return [[$tokens[0] ?? ''], 'family-fallback-first-token'];
        }

        return [$family, null];
    }

    protected function isSpecToken(string $upper): bool
    {
        if (in_array($upper, self::TRANSMISSIONS, true)
            || in_array($upper, self::FUEL_TOKENS, true)
            || in_array($upper, self::TRIM_WORDS, true)
            || in_array($upper, self::OTHER_SPEC, true)) {
            return true;
        }

        // 1.5, 1.5L, 1500, 1500CC
        if (preg_match('/^\d+[.,]\d+(L|CC)?$/', $upper) || preg_match('/^\d{3,4}CC?$/', $upper)) {
            return true;
        }

        // kode bodi: (C118), F74, X243
        if (str_starts_with($upper, '(') || preg_match('/^[A-Z]\d{2,}$/', $upper)) {
            return true;
        }

        return str_ends_with($upper, '*');
    }

    protected function stripBrandTokens(string $brand, array $tokens): array
    {
        $brandTokens = explode(' ', $this->norm($brand));
        $count = count($brandTokens);
        $head = array_slice(array_map([$this, 'norm'], $tokens), 0, $count);

        return $head === $brandTokens ? array_slice($tokens, $count) : $tokens;
    }

    protected function stripMarketingTokens(array $tokens): array
    {
        $stripped = [];

        while ($tokens !== [] && in_array($this->norm($tokens[0]), self::MARKETING_WORDS, true)) {
            $stripped[] = array_shift($tokens);
        }

        return [$tokens, $stripped];
    }

    protected function findCatalogModel(string $brand, string $remainderNorm): ?ModelVehicle
    {
        if ($remainderNorm === '') {
            return null;
        }

        foreach ($this->catalogModelsFor($brand) as $entry) {
            if ($entry['norm'] === $remainderNorm
                || str_starts_with($remainderNorm, $entry['norm'].' ')
                || ($entry['nospace'] !== '' && str_starts_with($this->nospace($remainderNorm), $entry['nospace']))) {
                return $entry['model'];
            }
        }

        return null;
    }

    /** @return array<int, array{model: ModelVehicle, norm: string, nospace: string}> */
    protected function catalogModelsFor(string $brand): array
    {
        $brandId = $this->brandId($brand);

        if ($brandId === null) {
            return [];
        }

        if (isset($this->modelsByBrand[$brandId])) {
            return $this->modelsByBrand[$brandId];
        }

        $this->modelsByBrand[$brandId] = ModelVehicle::query()
            ->where('brand_vehicle_id', $brandId)
            ->get()
            ->map(fn (ModelVehicle $model) => [
                'model' => $model,
                'norm' => $this->norm($model->name),
                'nospace' => $this->nospace($this->norm($model->name)),
            ])
            ->sortByDesc(fn (array $entry) => mb_strlen($entry['norm']))
            ->values()
            ->all();

        return $this->modelsByBrand[$brandId];
    }

    protected function brandId(string $brand): ?int
    {
        $key = $this->clean($brand);

        if ($key === '') {
            return null;
        }

        if (array_key_exists($key, $this->brandIdCache)) {
            return $this->brandIdCache[$key] ?: null;
        }

        $norm = $this->norm($key);
        $norm = $this->canonicalBrandKey($norm);
        $id = BrandVehicle::query()->get()
            ->first(fn (BrandVehicle $b) => $this->canonicalBrandKey($this->norm($b->name)) === $norm)
            ?->id;

        $this->brandIdCache[$key] = $id ?? 0;

        return $id;
    }

    protected function canonicalBrandKey(string $norm): string
    {
        foreach (VehicleSalesMatcher::BRAND_ALIASES as $needle => $canonical) {
            if ($norm === $needle) {
                return $this->norm($canonical);
            }
        }

        foreach (VehicleSalesMatcher::BRAND_CONTAINS_ALIASES as $needle => $canonical) {
            if (str_contains($norm, $needle)) {
                return $this->norm($canonical);
            }
        }

        return $norm;
    }

    protected function resolvePowertrain(?string $fuel, ?string $catalogPowertrain, string $typeModel, &$flag): string
    {
        $flag = null;
        $fuelNorm = $fuel === null ? '' : $this->norm($fuel);

        if ($fuelNorm !== '' && $fuelNorm !== '-') {
            if (isset(self::FUEL_MAP[$fuelNorm])) {
                return self::FUEL_MAP[$fuelNorm];
            }

            $flag = 'fuel-unknown:'.$fuelNorm;

            return 'ICE';
        }

        // Sinyal NAMA lebih spesifik daripada powertrain keluarga di katalog:
        // GAIKINDO menulis varian hybrid eksplisit ("Zenix G Hev", "Hybrid AT")
        // — file 2025/2026 tanpa kolom FUEL hanya bisa diklasifikasi dari sini.
        if (preg_match('/PHEV|REEV/i', $typeModel)) {
            return 'PHEV';
        }

        if (preg_match('/\bHEV\b|HYBRI/i', $typeModel)) {
            return 'HEV';
        }

        foreach (self::BEV_NAME_PATTERNS as $pattern) {
            if (preg_match($pattern, $typeModel)) {
                return 'BEV';
            }
        }

        if ($catalogPowertrain !== null && $catalogPowertrain !== '') {
            return $catalogPowertrain;
        }

        $flag = 'powertrain-guess';

        return 'ICE';
    }

    protected function clean(string $value): string
    {
        $value = preg_replace('/[\r\n]+/', ' ', trim($value)) ?? '';

        return preg_replace('/\s+/', ' ', $value) ?? '';
    }

    protected function tokens(string $value): array
    {
        return $this->clean($value) === '' ? [] : explode(' ', $this->clean($value));
    }

    protected function norm(string $value): string
    {
        return mb_strtoupper($this->clean($value));
    }

    protected function nospace(string $value): string
    {
        return preg_replace('/\s+/', '', $value) ?? '';
    }
}
