<?php

namespace App\Services;

use App\Models\BrandVehicle;
use App\Models\ModelVehicle;
use App\Models\TypeVehicle;
use App\Models\VehicleConnecting;
use App\Models\VehicleNameMapping;
use App\Support\VehicleNameNormalizer;

/**
 * Matcher raw_brand/raw_model dari file GAIKINDO ke katalog brand_vehicles /
 * model_vehicles. READ-ONLY terhadap katalog existing (tidak pernah mengubah/
 * menghapus); brand/model baru di-auto-create mengikuti struktur existing.
 * Model BEV baru dengan kWh diketahui mendapat satu type_vehicles default
 * agar auto-fill "Baterai kWh" di form kendaraan mobile langsung berfungsi.
 */
class VehicleSalesMatcher
{
    /** @var array<string, int> cache nama ternormalisasi brand → id */
    protected array $brandCache = [];

    /** @var array<string, ?VehicleNameMapping> cache lookup mapping eksplisit */
    protected array $mappingCache = [];

    /** @var array<int, array<string, array{model: ModelVehicle, norm: string, nospace: string, nobrand: string}>> */
    protected array $modelCacheByBrand = [];

    /** @var array<string, string> alias nama brand hasil normalisasi → nama kanonik ternormalisasi */
    public const BRAND_ALIASES = [
        'HYUNDAI HMID' => 'HYUNDAI',
        'HYUNDAI HMID INDONESIA' => 'HYUNDAI',
        'MORRIS GARAGE' => 'MG',
        'MG MOTOR' => 'MG',
        'MG MOTOR INDONESIA' => 'MG',
        'MERCEDES BENZ PC' => 'MERCEDES BENZ',
        'MERCEDES BENZ CV' => 'MERCEDES BENZ',
        'MITSUBISHI MOTORS' => 'MITSUBISHI',
        'MITSUBUSHI' => 'MITSUBISHI', 'MITSUBISHI FUSO' => 'MITSUBISHI',
        'MITSUBISHI MOTORS INDONESIA' => 'MITSUBISHI',
        'SUZUKI INDOMOBIL' => 'SUZUKI',
        'SUZUKI INDOMOBIL SALES' => 'SUZUKI',
        'TOYOTA TOYOTA' => 'TOYOTA',
        'PT TOYOTA' => 'TOYOTA',
        'VOLVO CARS' => 'VOLVO',
        // Varian suffix/prefix keluarga di CONNECTING & laporan tahunan
        'BMW CBU' => 'BMW', 'BMW XM' => 'BMW',
        'CHERY GT' => 'CHERY', 'CHERY OMDA' => 'CHERY', 'CHERY TIGGO' => 'CHERY',
        'HINO A' => 'HINO', 'HINO FB' => 'HINO', 'HINO FC' => 'HINO', 'HINO FG' => 'HINO',
        'HINO FL' => 'HINO', 'HINO FLX' => 'HINO', 'HINO FM' => 'HINO', 'HINO FMX' => 'HINO',
        'HINO GY' => 'HINO', 'HINO R' => 'HINO', 'HINO RN' => 'HINO', 'HINO SG' => 'HINO',
        'HINO ZY' => 'HINO',
        'ISUZU CYZ' => 'ISUZU', 'ISUZU FVR' => 'ISUZU', 'ISUZU GVR' => 'ISUZU',
        'ISUZU GVZ' => 'ISUZU', 'ISUZU GXZ' => 'ISUZU', 'ISUZU NPS' => 'ISUZU', 'ISUZU PHR' => 'ISUZU',
        'TOYOTA GR' => 'TOYOTA', 'TOYOTA LAND' => 'TOYOTA', 'TOYOTA PRIUS' => 'TOYOTA',
        'TOYOTA RAIZE' => 'TOYOTA', 'TOYOTA RAV' => 'TOYOTA', 'TOYOTA VIOS' => 'TOYOTA',
        'LEXUS ES' => 'LEXUS', 'LEXUS LBX' => 'LEXUS', 'LEXUS LC' => 'LEXUS', 'LEXUS LM' => 'LEXUS',
        'LEXUS LS' => 'LEXUS', 'LEXUS LX' => 'LEXUS', 'LEXUS NX' => 'LEXUS', 'LEXUS RX' => 'LEXUS',
        'LEXUS RZ' => 'LEXUS', 'LEXUS UX' => 'LEXUS',
        'MAXUS MIFA' => 'MAXUS', 'FARIZON SV' => 'FARIZON',
        'DFSK GELORA' => 'DFSK', 'DFSK GLORY' => 'DFSK',
        'CHANGAN DEEPAL' => 'CHANGAN', 'CHANGAN LUMIN' => 'CHANGAN',
        'GWM HAVAL' => 'GWM', 'GWM ORA' => 'GWM', 'GWM TANK' => 'GWM', 'TANK GWM' => 'GWM',
        'TANK' => 'GWM', 'HAVAL' => 'GWM', 'TATA MOTORS' => 'TATA',
        'GEELY STARRAY' => 'GEELY', 'HYUNDAI - HMID' => 'HYUNDAI',
        'VINFAST VF' => 'VINFAST', 'AION HYPTEC' => 'AION', 'BYD AION' => 'BYD',
        'SUZUKI ALL' => 'SUZUKI', 'SUZUKI APV' => 'SUZUKI', 'SUZUKI S' => 'SUZUKI',
        'HONDA CITY' => 'HONDA', 'HONDA NEW' => 'HONDA', 'HONDA STEP' => 'HONDA', 'HONDA WRV' => 'HONDA',
    ];

    /**
     * Alias berbasis "mengandung" (urutan = spesifik dulu) untuk varian nama
     * brand GAIKINDO yang tidak tercakup exact alias, mis. "MERCEDES BENZ PC (KPC)".
     */
    public const BRAND_CONTAINS_ALIASES = [
        'MITSUBISHI FUSO' => 'MITSUBISHI FUSO',
        'M FUSO' => 'MITSUBISHI FUSO',
        'MORRIS GARAGE' => 'MG',
        'MERCEDES' => 'MERCEDES BENZ',
        'HYUNDAI' => 'HYUNDAI',
        'VOLVO' => 'VOLVO',
        'MITSUBISHI' => 'MITSUBISHI',
        'SUZUKI' => 'SUZUKI',
        'TOYOTA' => 'TOYOTA',
    ];

    /** Alias → nama tampilan kanonik saat brand baru harus dibuat. */
    protected const CANONICAL_DISPLAY = [
        'HYUNDAI' => 'Hyundai',
        'MG' => 'MG',
        'MERCEDES BENZ' => 'Mercedes Benz',
        'MITSUBISHI' => 'Mitsubishi',
        'MITSUBISHI FUSO' => 'Mitsubishi Fuso',
        'SUZUKI' => 'Suzuki',
        'TOYOTA' => 'Toyota',
        'VOLVO' => 'Volvo',
        'BMW' => 'BMW',
        'GWM' => 'GWM',
        'BAIC' => 'BAIC',
        'UD TRUCKS' => 'UD Trucks',
        'DFSK' => 'DFSK',
        'MAXUS' => 'MAXUS',
        'AION' => 'AION',
        'NETA' => 'NETA',
        'XPENG' => 'XPENG',
    ];

    /** Akronim yang tetap huruf besar saat title-case nama baru. */
    protected const UPPER_WORDS = ['EV', 'BEV', 'PHEV', 'HEV', 'SUV', 'MPV', 'GT', 'DC', 'AC', 'PRO', 'MAX', 'MG', 'BYD', 'DNA', 'UT', 'PK', 'HD', 'X', 'S', 'E', 'V'];

    protected int $createdBrands = 0;
    protected int $createdModels = 0;
    protected int $createdTypes = 0;

    /**
     * LAPISAN 0 — lookup langsung ke tabel CONNECTING (master "BRAND MODEL
     * TYPE"): identitas squash dari teks mentah utuh (brand + type model).
     * Ini sumber kebenaran utama; splitter/fuzzy hanya fallback utk baris
     * yang belum terdaftar di CONNECTING.
     *
     * @return array{brand_vehicle_id: int, model_vehicle_id: int, type_vehicle_id: int|null,
     *               type_name: ?string, powertrain: ?string, brand_name: string, model_name: string}|null
     */
    public function connectingHit(string $rawBrand, ?string $fullRawModel): ?array
    {
        $gabungan = trim(preg_replace('/\s+/', ' ', trim($rawBrand).' '.trim((string) $fullRawModel)));

        if ($gabungan === '' || $gabungan === ' ') {
            return null;
        }

        return $this->connectingHitRaw($gabungan);
    }

    /**
     * Lookup CONNECTING dari teks gabungan utuh (sudah dirapikan) — bentuk
     * paling murni: tidak ada pemecah nama, tidak ada tebakan.
     *
     * @return array{brand_vehicle_id: int, model_vehicle_id: int, type_vehicle_id: int|null,
     *               type_name: ?string, powertrain: ?string, brand_name: string, model_name: string}|null
     */
    public function connectingHitRaw(string $gabungan): ?array
    {
        $key = preg_replace('/[^A-Z0-9]/u', '', mb_strtoupper($gabungan));

        if ($key === '' || $key === null) {
            return null;
        }

        // Jalur utama: raw_gabungan_key. Fallback: bandingkan raw_gabungan
        // tanpa spasi (case-insensitive) — baris yang diimpor lewat jalur
        // lama/GUI bisa belum punya raw_gabungan_key terisi.
        $compact = mb_strtoupper(str_replace(' ', '', trim((string) $gabungan)));
        $row = VehicleConnecting::query()
            ->where(function ($q) use ($key, $compact) {
                $q->where('raw_gabungan_key', $key)
                    ->orWhereRaw('UPPER(REPLACE(raw_gabungan, \' \', \'\')) = ?', [$compact]);
            })
            ->first();

        // Baris CONNECTING yang belum ter-link katalog tidak dihitung hit —
        // biarkan jalur fallback yang menentukan.
        if ($row === null || $row->brand_vehicle_id === null || $row->model_vehicle_id === null) {
            return null;
        }

        return [
            'brand_vehicle_id' => $row->brand_vehicle_id,
            'model_vehicle_id' => $row->model_vehicle_id,
            'type_vehicle_id' => $row->type_vehicle_id,
            'type_name' => $row->type_name,
            'powertrain' => $row->powertrain,
            'brand_name' => $row->brandVehicle?->name,
            'model_name' => $row->modelVehicle?->name,
        ];
    }

    /**
     * @return array{brand_vehicle_id: int|null, model_vehicle_id: int|null,
     *               brand_created: bool, model_created: bool, battery_kwh: float|null}
     */
    public function match(string $rawBrand, string $rawModel, ?float $batteryKwh = null, ?string $fullRawModel = null): array
    {
        // LAPISAN 0: CONNECTING (master raw_gabungan) — cocok persis tanpa
        // pemecah nama maupun tebakan fuzzy.
        $hit = $this->connectingHit($rawBrand, $fullRawModel ?? $rawModel);

        if ($hit !== null) {
            return [
                'brand_vehicle_id' => $hit['brand_vehicle_id'],
                'model_vehicle_id' => $hit['model_vehicle_id'],
                'brand_created' => false,
                'model_created' => false,
                'battery_kwh' => $batteryKwh,
                'mapping_used' => false,
                'connecting_used' => true,
                'type_vehicle_id' => $hit['type_vehicle_id'],
                'type_name' => $hit['type_name'],
                'connecting_powertrain' => $hit['powertrain'],
            ];
        }

        // LAPISAN 1: mapping eksplisit (keputusan manusia di tabel
        // vehicle_name_mappings) — menang atas alias/fuzzy/auto-create.
        $mapping = $this->lookupMapping($rawBrand, $rawModel, $fullRawModel);

        if ($mapping !== null) {
            return [
                'brand_vehicle_id' => $mapping->brand_vehicle_id,
                'model_vehicle_id' => $mapping->model_vehicle_id,
                'brand_created' => false,
                'model_created' => false,
                'battery_kwh' => $batteryKwh,
                'mapping_used' => true,
            ];
        }

        $brandCreated = false;
        $modelCreated = false;

        $brandId = $this->resolveBrand($rawBrand, $brandCreated);
        $modelId = null;

        if ($brandId !== null) {
            $modelId = $this->resolveModel($brandId, $rawModel, $batteryKwh, $modelCreated);
        }

        return [
            'brand_vehicle_id' => $brandId,
            'model_vehicle_id' => $modelId,
            'brand_created' => $brandCreated,
            'model_created' => $modelCreated,
            'battery_kwh' => $batteryKwh,
            'mapping_used' => false,
        ];
    }

    /**
     * Versi READ-ONLY dari match() untuk preview/dry-run: TIDAK PERNAH
     * membuat brand/model — yang belum ada di katalog dilaporkan sebagai
     * `brand_new`/`model_new` agar bisa direview manusia dulu.
     *
     * @return array{brand_vehicle_id: ?int, brand_name: ?string, model_vehicle_id: ?int,
     *               model_name: ?string, match_score: int, brand_new: bool, model_new: bool}
     */
    public function preview(string $rawBrand, string $rawModel, ?string $fullRawModel = null): array
    {
        // LAPISAN 0: CONNECTING — cocok persis, ter-match tanpa tebakan.
        $hit = $this->connectingHit($rawBrand, $fullRawModel ?? $rawModel);

        if ($hit !== null) {
            return [
                'brand_vehicle_id' => $hit['brand_vehicle_id'],
                'brand_name' => $hit['brand_name'],
                'model_vehicle_id' => $hit['model_vehicle_id'],
                'model_name' => $hit['model_name'],
                'match_score' => 100,
                'brand_new' => false,
                'model_new' => false,
                'mapping_used' => false,
                'connecting_used' => true,
            ];
        }

        // LAPISAN 1: mapping eksplisit — ter-match tanpa tebakan.
        $mapping = $this->lookupMapping($rawBrand, $rawModel, $fullRawModel);

        if ($mapping !== null) {
            return [
                'brand_vehicle_id' => $mapping->brand_vehicle_id,
                'brand_name' => $mapping->brandVehicle?->name,
                'model_vehicle_id' => $mapping->model_vehicle_id,
                'model_name' => $mapping->modelVehicle?->name,
                'match_score' => 100,
                'brand_new' => false,
                'model_new' => false,
                'mapping_used' => true,
            ];
        }

        $brandId = $this->lookupBrand($rawBrand);
        $brandNew = $brandId === null;
        $brandName = $brandId !== null ? BrandVehicle::find($brandId)?->name : null;

        $modelId = null;
        $modelName = null;
        $score = 0;
        $modelNew = false;

        if ($brandId !== null) {
            $norm = $this->normalize($rawModel);

            if ($norm !== '') {
                $best = $this->bestModelMatch($brandId, $norm);

                if ($best !== null) {
                    $modelId = $best['model']->id;
                    $modelName = $best['model']->name;
                    $score = $best['score'];
                } else {
                    $modelNew = true;
                }
            } else {
                $modelNew = true;
            }
        }

        return [
            'brand_vehicle_id' => $brandId,
            'brand_name' => $brandName,
            'model_vehicle_id' => $modelId,
            'model_name' => $modelName,
            'match_score' => $score,
            'brand_new' => $brandNew,
            'model_new' => $modelNew,
            'mapping_used' => false,
        ];
    }

    /** Mapping eksplisit utk pasangan raw (model keluarga ATAU raw penuh). */
    protected function lookupMapping(string $rawBrand, string $rawModel, ?string $fullRawModel = null): ?VehicleNameMapping
    {
        $brandNorm = $this->normalize($rawBrand);
        $modelNorms = array_values(array_filter([
            $this->normalize($rawModel),
            $fullRawModel !== null ? $this->normalize($fullRawModel) : null,
        ], fn ($v) => $v !== null && $v !== ''));

        if ($brandNorm === '' || $modelNorms === []) {
            return null;
        }

        $cacheKey = $brandNorm.'|'.implode('/', $modelNorms);

        if (! array_key_exists($cacheKey, $this->mappingCache)) {
            $this->mappingCache[$cacheKey] = VehicleNameMapping::query()
                ->where('raw_brand_norm', $brandNorm)
                ->whereIn('raw_model_norm', $modelNorms)
                ->first();
        }

        return $this->mappingCache[$cacheKey];
    }

    public function summary(): array
    {
        return [
            'created_brands' => $this->createdBrands,
            'created_models' => $this->createdModels,
            'created_types' => $this->createdTypes,
        ];
    }

    /** Normalisasi nama brand ke kunci kanonik (exact alias, lalu contains alias). */
    protected function canonicalBrandKey(string $norm): string
    {
        if (isset(self::BRAND_ALIASES[$norm])) {
            return self::BRAND_ALIASES[$norm];
        }
        foreach (self::BRAND_CONTAINS_ALIASES as $needle => $canonical) {
            if (str_contains($norm, $needle)) {
                return $canonical;
            }
        }

        return $norm;
    }

    /**
     * Nama brand kanonik utk raw brand laporan (alias exact → alias contains
     * → nama brand existing di DB → pretty name). Dipakai CONNECTING dan
     * konsolidasi katalog agar satu brand tidak duplikat banyak nama.
     */
    public function canonicalBrandName(string $rawBrand): string
    {
        $norm = $this->normalize($rawBrand);
        if ($norm === '') {
            return trim($rawBrand);
        }

        $key = $this->canonicalBrandKey($norm);

        if (isset(self::CANONICAL_DISPLAY[$key])) {
            return self::CANONICAL_DISPLAY[$key];
        }

        // Preferensi: brand dgn nama persis sama (ternormalisasi) → brand
        // lain yang kanonik-key-nya sama (bisa non-kanonik, jadi terakhir).
        $byKey = null;
        $exact = null;
        foreach (BrandVehicle::query()->get() as $brand) {
            if ($this->normalize($brand->name) === $key) { $exact = $brand; break; }
            if ($byKey === null && $this->canonicalBrandKey($this->normalize($brand->name)) === $key) {
                $byKey = $brand;
            }
        }

        return $exact?->name ?? $byKey?->name ?? $this->prettyName($rawBrand);
    }

    /** Cari brand existing (read-only, tanpa membuat) — dipakai preview(). */
    protected function lookupBrand(string $rawBrand): ?int
    {
        $norm = $this->normalize($rawBrand);
        if ($norm === '') {
            return null;
        }

        $norm = $this->canonicalBrandKey($norm);

        if ($this->brandCache === []) {
            $this->brandCache = ['__loaded__' => 0];
            BrandVehicle::query()->chunk(500, function ($brands) {
                foreach ($brands as $brand) {
                    $key = $this->canonicalBrandKey($this->normalize($brand->name));
                    $this->brandCache[$key] ??= $brand->id;
                }
            });
            unset($this->brandCache['__loaded__']);
        }

        return $this->brandCache[$norm] ?? null;
    }

    protected function resolveBrand(string $rawBrand, bool &$created): ?int
    {
        $norm = $this->normalize($rawBrand);
        if ($norm === '') {
            return null;
        }

        $norm = $this->canonicalBrandKey($norm);

        $found = $this->lookupBrand($rawBrand);
        if ($found !== null) {
            return $found;
        }

        $brand = BrandVehicle::create([
            'name' => self::CANONICAL_DISPLAY[$norm] ?? $this->prettyName($rawBrand),
        ]);
        $this->brandCache[$norm] = $brand->id;
        $this->createdBrands++;

        $created = true;

        return $brand->id;
    }

    /** Muat cache model milik satu brand. */
    protected function loadModelsForBrand(int $brandId): void
    {
        $this->modelCacheByBrand[$brandId] = [];

        $brandNorm = $this->normalize((string) BrandVehicle::find($brandId)?->name);

        ModelVehicle::where('brand_vehicle_id', $brandId)
            ->chunk(500, function ($models) use ($brandId, $brandNorm) {
                foreach ($models as $model) {
                    $mNorm = $this->normalize($model->name);
                    $this->modelCacheByBrand[$brandId][] = [
                        'model' => $model,
                        'norm' => $mNorm,
                        'nospace' => preg_replace('/\s+/', '', $mNorm),
                        'nobrand' => VehicleNameNormalizer::stripBrandTokens($mNorm, $brandNorm),
                    ];
                }
            });
    }

    /**
     * Kandidat model terbaik utk nama ternormalisasi (read-only) —
     * @return array{model: ModelVehicle, score: int}|null
     */
    protected function bestModelMatch(int $brandId, string $norm): ?array
    {
        if (! isset($this->modelCacheByBrand[$brandId])) {
            $this->loadModelsForBrand($brandId);
        }

        $best = null;
        $bestScore = 0;
        $nospace = preg_replace('/\s+/', '', $norm);

        foreach ($this->modelCacheByBrand[$brandId] as $entry) {
            $score = 0;
            if ($entry['norm'] === $norm) {
                $score = 100;
            } elseif (($entry['nobrand'] ?? '') !== '' && $entry['nobrand'] === $norm) {
                // Katalog ber-prefix brand ("MG S5 EV") vs raw tanpa prefix
                // ("S5 EV") — di bawah exact, di atas varian/prefix parsial.
                $score = 95;
            } elseif ($entry['nospace'] === $nospace && $nospace !== '') {
                $score = 90;
            } elseif (str_starts_with($norm, $entry['norm'] . ' ') || str_starts_with($entry['norm'], $norm . ' ')) {
                // "ATTO 1 DYNAMIC" vs "ATTO 1" — raw GAIKINDO memuat varian.
                $score = min(mb_strlen($entry['norm']), mb_strlen($norm)) >= 4 ? 70 : 0;
            } elseif (str_contains($nospace, $entry['nospace']) || str_contains($entry['nospace'], $nospace)) {
                // sekunder: "BINGUOEV" vs "BINGUO EV"
                $score = min(mb_strlen($entry['nospace']), mb_strlen($nospace)) >= 5 ? 50 : 0;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $entry;
            }
        }

        return $best !== null ? ['model' => $best['model'], 'score' => $bestScore] : null;
    }

    protected function resolveModel(int $brandId, string $rawModel, ?float $batteryKwh, bool &$created): ?int
    {
        $norm = $this->normalize($rawModel);
        if ($norm === '') {
            return null;
        }

        $nospace = preg_replace('/\s+/', '', $norm);

        if (! isset($this->modelCacheByBrand[$brandId])) {
            $this->loadModelsForBrand($brandId);
        }

        $best = $this->bestModelMatch($brandId, $norm);

        if ($best !== null) {
            return $best['model']->id;
        }

        $model = ModelVehicle::create([
            'brand_vehicle_id' => $brandId,
            'name' => $this->prettyName($rawModel),
        ]);

        // Entry cache harus berbentuk sama dengan entry loadModelsForBrand():
        // satu instance matcher dipakai sepanjang satu file import, dan baris
        // berikutnya di brand yang sama mengiterasi entry auto-create ini.
        $brandNorm = $this->normalize((string) BrandVehicle::find($brandId)?->name);

        $this->modelCacheByBrand[$brandId][] = [
            'model' => $model,
            'norm' => $norm,
            'nospace' => $nospace,
            'nobrand' => VehicleNameNormalizer::stripBrandTokens($norm, $brandNorm),
        ];
        $this->createdModels++;
        $created = true;

        // Satu type default agar auto-fill baterai mobile berfungsi; hanya untuk
        // model baru — katalog existing tidak disentuh.
        if ($batteryKwh !== null && $batteryKwh > 0) {
            TypeVehicle::create([
                'model_vehicle_id' => $model->id,
                'name' => 'Standar',
                'type_charger' => [], // json NOT NULL di skema existing
                'battery_capacity' => $batteryKwh,
            ]);
            $this->createdTypes++;
        }

        return $model->id;
    }

    /** Upper-case, buang newline, collapse spasi. */
    public function normalize(string $value): string
    {
        $value = preg_replace('/[\r\n]+/', ' ', trim($value)) ?? '';
        $value = preg_replace('/\s+/', ' ', $value) ?? '';

        return mb_strtoupper($value);
    }

    /** Title-case untuk nama katalog baru, akronim tetap besar. */
    public function prettyName(string $raw): string
    {
        $clean = preg_replace('/[\r\n]+/', ' ', trim($raw)) ?? '';
        $clean = preg_replace('/\s+/', ' ', $clean) ?? '';

        $words = explode(' ', $clean);
        $out = [];
        foreach ($words as $word) {
            $upper = mb_strtoupper($word);
            $out[] = in_array($upper, self::UPPER_WORDS, true) ? $upper : mb_convert_case($word, MB_CASE_TITLE, 'UTF-8');
        }

        return implode(' ', $out);
    }

    /** Ekstraksi kWh dari kolom TANK, mis. "51.8 kWh" → 51.8. */
    public static function extractKwh(?string $tank): ?float
    {
        if ($tank === null || $tank === '') {
            return null;
        }

        if (preg_match('/(\d+(?:[.,]\d+)?)\s*kwh/i', $tank, $m)) {
            $kwh = (float) str_replace(',', '.', $m[1]);

            return $kwh > 0 && $kwh <= 300 ? $kwh : null;
        }

        return null;
    }
}
