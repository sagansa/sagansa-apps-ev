<?php

namespace App\Services;

use App\Models\ChargingStation;
use App\Models\OpenchargemapPoi;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Perbandingan 3 sumber titik SPKLU: ESDM vs PLN Master vs OpenChargeMap.
 *
 * Kedua sumber pertama diambil dari canonical `charging_stations`
 * (source='esdm' / 'pln' — hasil hydrate CanonicalStationHydrateService),
 * OCM dari `openchargemap_pois` (ocm:import / OcmHarvestService).
 *
 * Matching deterministik 3 tingkat per pasangan sumber (sisi B dicocokkan
 * ke sisi A — konsisten dengan arah audit Python):
 *  1. Grid halus (0.004°, tetangga 3×3) → match_exact (≤50 m) / match_near (≤150 m)
 *  2. Grid kasar (0.02°, tetangga 3×3, hanya item yang lolos tingkat 1)
 *     → candidate (nama serupa, 150 m–2 km)
 *  3. Indeks token nama (item tersisa) → conflict_far (nama serupa, >2 km)
 *  Sisanya → unique.
 *
 * Hasil lengkap di-cache sekali (tanpa filter); filter/paginasi diterapkan
 * setelahnya agar tiap kombinasi query tidak menghitung ulang.
 */
class SpkluSourceComparisonService
{
    public const SOURCE_ESDM = 'esdm';

    public const SOURCE_PLN = 'pln';

    public const SOURCE_OCM = 'ocm';

    public const MATCH_EXACT = 'match_exact';

    public const MATCH_NEAR = 'match_near';

    public const CANDIDATE = 'candidate';

    public const CONFLICT_FAR = 'conflict_far';

    public const UNIQUE = 'unique';

    public const PAIRS = ['esdm_pln', 'esdm_ocm', 'pln_ocm'];

    public const CATEGORIES = [
        self::MATCH_EXACT,
        self::MATCH_NEAR,
        self::CANDIDATE,
        self::CONFLICT_FAR,
        self::UNIQUE,
    ];

    private const CELL_FINE_DEG = 0.004;

    private const CELL_COARSE_DEG = 0.02;

    private const MATCH_EXACT_M = 50;

    private const MATCH_NEAR_M = 150;

    private const CANDIDATE_MAX_M = 2000;

    private const NAME_SIM_THRESHOLD = 60.0;

    private const CACHE_KEY = 'spklu-source-comparison:v1';

    private const CACHE_TTL = 86400;

    /** Token generik yang diabaikan saat mengindeks nama (tingkat 3). */
    private const STOP_TOKENS = [
        'SPKLU', 'SPBU', 'PLN', 'ULP', 'UP3', 'UPI', 'KJ', 'KANTOR', 'JAGA',
        'MALL', 'HOTEL', 'THE', 'AND', 'PANTAI', 'KOTA', 'AREA', 'REST',
    ];

    /** @var array<string, array<int, array>> peta sumber → daftar titik */
    private array $sources = [];

    /** @var array<string, array<string, array<int, int>>> grid: sumber → cellKey → index titik */
    private array $grids = [];

    /** @var array<string, array<string, array<int, int>>> token → index titik per sumber */
    private array $tokenIndex = [];

    public function compare(
        ?string $pair = null,
        ?string $category = null,
        ?string $source = null,
        ?string $q = null,
        ?int $minDistanceM = null,
        int $page = 1,
        int $perPage = 50,
        bool $refresh = false,
    ): array {
        $result = $this->cached($refresh);

        $pairs = in_array($pair, self::PAIRS, true) ? [$pair] : self::PAIRS;
        $summary = [];
        foreach ($pairs as $p) {
            $summary[$p] = $result['summary'][$p];
        }
        $summary['totals'] = $result['summary']['totals'] ?? null;

        $items = [];
        foreach ($pairs as $p) {
            foreach ($result['items'][$p] as $item) {
                if ($category !== null && $item['category'] !== $category) {
                    continue;
                }
                if ($source !== null && ! str_contains($item['pair'], $source)) {
                    continue;
                }
                if ($q !== null && $q !== '') {
                    $needle = mb_strtolower($q);
                    $hayA = mb_strtolower((string) ($item['a']['name'] ?? ''));
                    $hayB = mb_strtolower((string) ($item['b']['name'] ?? ''));
                    if (! str_contains($hayA, $needle) && ! str_contains($hayB, $needle)) {
                        continue;
                    }
                }
                if ($minDistanceM !== null && ($item['distance_m'] ?? PHP_INT_MAX) < $minDistanceM) {
                    continue;
                }
                $items[] = $item;
            }
        }

        $total = count($items);
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 500));
        $slice = array_slice($items, ($page - 1) * $perPage, $perPage);

        return [
            'summary' => $summary,
            'items' => $slice,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => (int) ceil($total / $perPage),
            'computed_at' => $result['computed_at'] ?? null,
        ];
    }

    /**
     * Hitung (atau ambil dari cache) perbandingan penuh:
     * {summary: {pair: {kategori: n}}, items: {pair: [item, ...]}}.
     */
    public function cached(bool $refresh = false): array
    {
        if (! $refresh) {
            $cached = Cache::get(self::CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $result = $this->compute();
        Cache::put(self::CACHE_KEY, $result, self::CACHE_TTL);

        return $result;
    }

    private function compute(): array
    {
        $this->sources = [
            self::SOURCE_ESDM => $this->loadChargingStations(self::SOURCE_ESDM),
            self::SOURCE_PLN => $this->loadChargingStations(self::SOURCE_PLN),
            self::SOURCE_OCM => $this->loadOcm(),
        ];
        $this->buildGrids();
        $this->buildTokenIndex();

        $summary = [];
        $items = [];
        foreach (self::PAIRS as $pair) {
            [$aSource, $bSource] = explode('_', $pair);
            $matched = $this->matchPair($pair, $aSource, $bSource);
            $summary[$pair] = $this->summarizePair($matched);
            $items[$pair] = $matched['items'];
        }

        $summary['totals'] = [
            'esdm' => count($this->sources[self::SOURCE_ESDM]),
            'pln' => count($this->sources[self::SOURCE_PLN]),
            'ocm' => count($this->sources[self::SOURCE_OCM]),
        ];

        return ['summary' => $summary, 'items' => $items, 'computed_at' => now()->toIso8601String()];
    }

    // ── Pemuatan sumber ──────────────────────────────────────────────────────

    private function loadChargingStations(string $source): array
    {
        return ChargingStation::query()
            ->where('source', $source)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('id')
            ->get()
            ->map(fn (ChargingStation $s) => [
                'sid' => (int) ($s->source_station_id ?? $s->id),
                'name' => (string) $s->nama_lokasi,
                'lat' => (float) $s->latitude,
                'lng' => (float) $s->longitude,
                'operator' => $s->provider_name ?? $s->nama_badan_usaha,
            ])
            ->all();
    }

    private function loadOcm(): array
    {
        return OpenchargemapPoi::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('id')
            ->get()
            ->map(fn (OpenchargemapPoi $p) => [
                'sid' => $p->ocm_id,
                'name' => (string) ($p->title ?? ''),
                'lat' => (float) $p->latitude,
                'lng' => (float) $p->longitude,
                'operator' => $p->operator,
            ])
            ->all();
    }

    private function buildGrids(): void
    {
        foreach ([self::SOURCE_ESDM, self::SOURCE_PLN, self::SOURCE_OCM] as $src) {
            $this->grids[$src] = ['fine' => [], 'coarse' => []];
            foreach ($this->sources[$src] as $i => $pt) {
                $this->grids[$src]['fine'][$this->cellKey($pt['lat'], $pt['lng'], self::CELL_FINE_DEG)][] = $i;
                $this->grids[$src]['coarse'][$this->cellKey($pt['lat'], $pt['lng'], self::CELL_COARSE_DEG)][] = $i;
            }
        }
    }

    private function buildTokenIndex(): void
    {
        foreach ([self::SOURCE_ESDM, self::SOURCE_PLN, self::SOURCE_OCM] as $src) {
            $this->tokenIndex[$src] = [];
            foreach ($this->sources[$src] as $i => $pt) {
                foreach ($this->meaningfulTokens($pt['name']) as $token) {
                    $this->tokenIndex[$src][$token][] = $i;
                }
            }
        }
    }

    // ── Matching per pasangan ────────────────────────────────────────────────

    /**
     * @return array{items: array, categories: array<int, string>}
     */
    private function matchPair(string $pair, string $aSource, string $bSource): array
    {
        $a = $this->sources[$aSource];
        $b = $this->sources[$bSource];
        $items = [];
        $categories = [];

        foreach ($b as $bi => $ptB) {
            $category = self::UNIQUE;
            $bestA = null;
            $bestDist = null;
            $bestSim = 0;

            // Tingkat 1: grid halus — tetangga 3×3 (±~440 m).
            $near = $this->gridNeighbors($aSource, 'fine', $ptB['lat'], $ptB['lng'], self::CELL_FINE_DEG);
            $best = $this->bestByDistance($a, $near, $ptB);
            if ($best !== null && $best['distance'] <= self::MATCH_NEAR_M) {
                $category = $best['distance'] <= self::MATCH_EXACT_M ? self::MATCH_EXACT : self::MATCH_NEAR;
                $bestA = $best;
                $bestDist = (int) round($best['distance']);
                $bestSim = (int) round($best['similarity']);
            }

            // Tingkat 2: grid kasar — tetangga 3×3 (±~4.4 km), kandidat bernama.
            if ($category === self::UNIQUE) {
                $near = $this->gridNeighbors($aSource, 'coarse', $ptB['lat'], $ptB['lng'], self::CELL_COARSE_DEG);
                $best = $this->bestByDistance($a, $near, $ptB, self::NAME_SIM_THRESHOLD);
                if ($best !== null && $best['distance'] <= self::CANDIDATE_MAX_M
                    && $best['similarity'] >= self::NAME_SIM_THRESHOLD) {
                    $category = self::CANDIDATE;
                    $bestA = $best;
                    $bestDist = (int) round($best['distance']);
                    $bestSim = (int) round($best['similarity']);
                }
            }

            // Tingkat 3: indeks token nama — konflik jarak jauh (>2 km).
            if ($category === self::UNIQUE) {
                $best = $this->bestByName($aSource, $ptB);
                if ($best !== null && $best['similarity'] >= self::NAME_SIM_THRESHOLD) {
                    $category = self::CONFLICT_FAR;
                    $bestA = $best;
                    $bestDist = (int) round($best['distance']);
                    $bestSim = (int) round($best['similarity']);
                }
            }

            $categories[$bi] = $category;

            if ($category === self::UNIQUE) {
                $items[] = [
                    'pair' => $pair,
                    'category' => self::UNIQUE,
                    'a' => null,
                    'b' => $this->pointMeta($bSource, $ptB),
                    'distance_m' => null,
                    'similarity_pct' => 0,
                ];

                continue;
            }

            $items[] = [
                'pair' => $pair,
                'category' => $category,
                'a' => $this->pointMeta($aSource, $a[$bestA['index']]),
                'b' => $this->pointMeta($bSource, $ptB),
                'distance_m' => $bestDist,
                'similarity_pct' => $bestSim,
            ];
        }

        return ['items' => $items, 'categories' => $categories];
    }

    /**
     * Kandidat terdekat dari sekumpulan index titik A.
     *
     * @param  array<int, int>  $indices
     * @return array{index: int, distance: float, similarity: float}|null
     */
    private function bestByDistance(array $a, array $indices, array $ptB, ?float $minSim = null): ?array
    {
        $best = null;
        foreach ($indices as $ai) {
            $ptA = $a[$ai];
            $sim = $this->nameSimilarity($ptB['name'], $ptA['name']);
            if ($minSim !== null && $sim < $minSim) {
                continue;
            }
            $dist = $this->haversineM($ptB['lat'], $ptB['lng'], $ptA['lat'], $ptA['lng']);
            if ($best === null || $dist < $best['distance']) {
                $best = ['index' => $ai, 'distance' => $dist, 'similarity' => $sim];
            }
        }

        return $best;
    }

    /**
     * Pencarian berbasis token nama (tingkat 3): gabung kandidat yang berbagi
     * token bermakna dengan titik B, batasi 200 kandidat agar tetap cepat.
     *
     * @return array{index: int, distance: float, similarity: float}|null
     */
    private function bestByName(string $aSource, array $ptB): ?array
    {
        $tokens = $this->meaningfulTokens($ptB['name']);
        if ($tokens === []) {
            return null;
        }

        $candidates = [];
        foreach ($tokens as $token) {
            foreach ($this->tokenIndex[$aSource][$token] ?? [] as $ai) {
                $candidates[$ai] = true;
                if (count($candidates) >= 200) {
                    break 2;
                }
            }
        }
        if ($candidates === []) {
            return null;
        }

        $a = $this->sources[$aSource];
        $best = null;
        foreach (array_keys($candidates) as $ai) {
            $ptA = $a[$ai];
            $sim = $this->nameSimilarity($ptB['name'], $ptA['name']);
            if ($sim < self::NAME_SIM_THRESHOLD) {
                continue;
            }
            $dist = $this->haversineM($ptB['lat'], $ptB['lng'], $ptA['lat'], $ptA['lng']);
            if ($dist <= self::CANDIDATE_MAX_M) {
                continue; // jarak dekat sudah ditangani tingkat 1–2
            }
            // Pilih kemiripan tertinggi; seri → jarak terdekat.
            if ($best === null || $sim > $best['similarity']
                || ($sim === $best['similarity'] && $dist < $best['distance'])) {
                $best = ['index' => $ai, 'distance' => $dist, 'similarity' => $sim];
            }
        }

        return $best;
    }

    /** @return array<int, int> index titik A di cell titik B + 8 tetangganya */
    private function gridNeighbors(string $aSource, string $tier, float $lat, float $lng, float $cellDeg): array
    {
        [$cx, $cy] = $this->cellCoords($lat, $lng, $cellDeg);
        $out = [];
        for ($dx = -1; $dx <= 1; $dx++) {
            for ($dy = -1; $dy <= 1; $dy++) {
                foreach ($this->grids[$aSource][$tier][($cx + $dx).':'.($cy + $dy)] ?? [] as $i) {
                    $out[] = $i;
                }
            }
        }

        return $out;
    }

    private function cellCoords(float $lat, float $lng, float $cellDeg): array
    {
        return [(int) floor($lat / $cellDeg), (int) floor($lng / $cellDeg)];
    }

    private function cellKey(float $lat, float $lng, float $cellDeg): string
    {
        [$x, $y] = $this->cellCoords($lat, $lng, $cellDeg);

        return $x.':'.$y;
    }

    // ── Ringkasan & util ─────────────────────────────────────────────────────

    /**
     * Hitung jumlah per kategori dari kategori yang sudah dihitung matchPair()
     * (tidak matching ulang).
     *
     * @param  array  $matched  hasil matchPair
     */
    private function summarizePair(array $matched): array
    {
        $counts = array_fill_keys(self::CATEGORIES, 0);
        foreach ($matched['categories'] as $cat) {
            $counts[$cat]++;
        }

        return $counts;
    }

    private function pointMeta(string $source, array $pt): array
    {
        return [
            'source' => $source,
            'id' => $pt['sid'],
            'name' => $pt['name'],
            'lat' => $pt['lat'],
            'lng' => $pt['lng'],
            'operator' => $pt['operator'],
        ];
    }

    private function haversineM(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dp = $p2 - $p1;
        $dl = deg2rad($lng2 - $lng1);
        $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;

        return 6371000 * 2 * asin(min(1.0, sqrt($a)));
    }

    /** @return string[] token bermakna (≥3 huruf, bukan stop token) */
    private function meaningfulTokens(string $name): array
    {
        $clean = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', ' ', $name));
        $out = [];
        foreach (preg_split('/\s+/', $clean) ?: [] as $token) {
            if (mb_strlen($token) >= 3 && ! in_array($token, self::STOP_TOKENS, true)) {
                $out[] = $token;
            }
        }

        return $out;
    }

    private function nameSimilarity(string $a, string $b): float
    {
        $an = $this->normalizeName($a);
        $bn = $this->normalizeName($b);
        if ($an === '' || $bn === '') {
            return 0.0;
        }
        if ($an === $bn) {
            return 100.0;
        }

        similar_text($an, $bn, $percent);

        $ta = array_fill_keys(explode(' ', $an), true);
        $tb = array_fill_keys(explode(' ', $bn), true);
        $inter = count(array_intersect_key($ta, $tb));
        $union = count($ta) + count($tb) - $inter;
        $jaccard = $union > 0 ? ($inter / $union) * 100 : 0.0;

        return max($percent, $jaccard);
    }

    private function normalizeName(string $name): string
    {
        $s = strtoupper(trim($name));
        $s = preg_replace('/^\([^)]*\)\s*/', '', $s) ?? $s;
        $s = preg_replace('/^(SPKLU|SPBU)\b\s*/i', '', $s) ?? $s;
        $s = preg_replace('/\s+\d+(?:[.,]\d+)?\s*KW(\s*\(\d+\))?\s*$/i', '', $s) ?? $s;
        $s = preg_replace('/[^A-Z0-9]+/', ' ', $s) ?? '';

        return trim((string) preg_replace('/\s+/', ' ', $s));
    }
}
