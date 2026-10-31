<?php

namespace App\Services;

use App\Models\OcmSyncState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Bootstrap POI OpenChargeMap seluruh dunia via quadtree bbox tiling.
 *
 * Paginasi offset OCM rusak (halaman-2 mengulang halaman-1 persis, bahkan
 * dalam query bbox), sehingga satu query tak pernah melebihi ~1000 POI —
 * London saja berlipat dari itu. Satu-satunya jalur andal: query spasial
 * per kotak dengan format `(lat1,lon1),(lat2,lon2)` (CSV polos dibungkam
 * senyap oleh API). Tile yang "penuh" (hasil == tileMaxResults → kemungkinan
 * terpotong) dipecah jadi 4 anak sampai tiap tile di bawah cap — duplikat
 * antar tile aman karena upsert by ocm_id.
 *
 * Negara tiap POI diturunkan dari AddressInfo.CountryID via katalog
 * ocm_reference_data (scope countries, diisi ocm:import-operators); POI
 * tanpa padanan negara DILEWATI agar tidak salah default. Setelah selesai,
 * ocm_sync_state ditulis per negara sehingga incremental harian biasa
 * (ocm:sync tanpa --tile) bisa lanjut merawat data negara itu.
 */
class OcmTileSyncService
{
    public function __construct(
        private readonly float $latStep = 10.0,
        private readonly float $lonStep = 20.0,
        private readonly int $maxDepth = 8,
        private readonly int $tileMaxResults = 1000,
        private readonly float $requestDelaySec = 1.5,
        private readonly int $backoffInitialSec = 30,
        private readonly int $backoffMaxSec = 300,
        private readonly float $minLat = -60.0,
        private readonly float $maxLat = 80.0,
    ) {}

    /**
     * Jalankan bootstrap world tiling.
     *
     * @param  callable|null  $onTile  fn(array{depth: int, batch: int, unique: int, tiles: int}): void
     * @return array{requests: int, tiles: int, subdivided: int, fetched: int, unique: int, inserted: int, updated: int, skipped_unknown_country: int, countries: array<string, int>, error: string|null}
     */
    public function bootstrapWorld(?callable $onTile = null): array
    {
        $key = config('services.openchargemap.key');
        if (empty($key)) {
            return $this->blank('OCM_API_KEY belum diisi (config services.openchargemap.key)');
        }

        $baseUrl = rtrim((string) config('services.openchargemap.base_url'), '/');
        $reference = app(OcmReferenceDataService::class);
        $harvester = app(OcmHarvestService::class);

        $isoByCountryId = $reference->countryIsoMap();

        $stats = $this->blank();
        $seenIds = [];
        $uniqueByCountry = [];
        $startUtc = Carbon::now('UTC')->format('Y-m-d H:i:s');
        $backoff = $this->backoffInitialSec;

        // Antrian tile awal: grid jendela dunia (charger nyata berada di
        // rentang lat ini; kutub diperkecil utk hemat ratusan tile kosong).
        $queue = [];
        for ($lat = $this->minLat; $lat < $this->maxLat; $lat += $this->latStep) {
            for ($lon = -180.0; $lon < 180.0; $lon += $this->lonStep) {
                $queue[] = [
                    'latMin' => $lat,
                    'lonMin' => $lon,
                    'latMax' => min($lat + $this->latStep, $this->maxLat),
                    'lonMax' => min($lon + $this->lonStep, 180.0),
                    'depth' => 0,
                ];
            }
        }

        while ($queue !== []) {
            $tile = array_shift($queue);

            $params = [
                'key' => $key,
                'output' => 'json',
                // Format wajib berkurung — terverifikasi live: CSV polos → 0 hasil.
                'boundingbox' => sprintf(
                    '(%s,%s),(%s,%s)',
                    $tile['latMin'],
                    $tile['lonMin'],
                    $tile['latMax'],
                    $tile['lonMax']
                ),
                'maxresults' => $this->tileMaxResults,
                'compact' => 'true',
                'verbose' => 'false',
                'includecomments' => 'false',
            ];

            while (true) {
                $response = Http::timeout(90)
                    ->withHeaders(['User-Agent' => 'ev-app-data-sync/1.0'])
                    ->get($baseUrl, $params);

                if ($response->status() !== 429) {
                    break;
                }

                $this->sleep($backoff);
                // 0 = mode test tanpa sleep; naikkan utk 429 berikutnya.
                $backoff = $this->backoffMaxSec > 0
                    ? min($backoff * 2, $this->backoffMaxSec)
                    : 0;
            }

            if (! $response->successful()) {
                $stats['error'] = 'API request failed (HTTP '.$response->status().')';

                return $stats;
            }

            $batch = $response->json();
            if (! is_array($batch)) {
                $stats['error'] = 'Invalid response (bukan array JSON)';

                return $stats;
            }

            $stats['requests']++;
            $stats['tiles']++;
            $stats['fetched'] += count($batch);

            foreach ($batch as $point) {
                $id = (int) ($point['ID'] ?? $point['id'] ?? $point['external_id'] ?? 0);
                if ($id === 0 || isset($seenIds[$id])) {
                    continue; // duplikat antar tile (parent ↔ anak / sambungan)
                }
                $seenIds[$id] = true;

                $countryId = (int) (data_get($point, 'AddressInfo.CountryID')
                    ?? data_get($point, 'AddressInfo.Country.ID')
                    ?? 0);
                $iso = $isoByCountryId[$countryId] ?? null;
                if ($iso === null) {
                    // Tanpa negara yang pasti → jangan simpan (hydrate akan
                    // salah default ke ID bila country_code null).
                    $stats['skipped_unknown_country']++;

                    continue;
                }

                $stats['unique']++;
                $uniqueByCountry[$iso] = ($uniqueByCountry[$iso] ?? 0) + 1;

                if ($harvester->upsertPoi($point, $iso)) {
                    $stats['inserted']++;
                } else {
                    $stats['updated']++;
                }
            }

            if ($onTile !== null) {
                $onTile([
                    'depth' => $tile['depth'],
                    'batch' => count($batch),
                    'unique' => $stats['unique'],
                    'tiles' => $stats['tiles'],
                ]);
            }

            // Tile penuh = kemungkinan terpotong (offset tak bisa dipakai) →
            // pecah jadi 4 anak, kecuali sudah di kedalaman maksimum.
            if (count($batch) >= $this->tileMaxResults && $tile['depth'] < $this->maxDepth) {
                $stats['subdivided']++;
                foreach ($this->children($tile) as $child) {
                    $queue[] = $child;
                }
            }

            $this->sleep($this->requestDelaySec);
        }

        // State per negara → incremental harian ocm:sync siap merawat data.
        foreach ($uniqueByCountry as $iso => $count) {
            OcmSyncState::query()->updateOrCreate(
                ['country' => $iso],
                [
                    'last_sync_utc' => $startUtc,
                    'poi_count' => $count,
                    'mode' => 'penuh',
                ]
            );
        }
        $stats['countries'] = $uniqueByCountry;

        return $stats;
    }

    /** Pecah tile jadi 4 anak (quadtree). */
    private function children(array $tile): array
    {
        $latMid = ($tile['latMin'] + $tile['latMax']) / 2;
        $lonMid = ($tile['lonMin'] + $tile['lonMax']) / 2;
        $depth = $tile['depth'] + 1;

        return [
            ['latMin' => $tile['latMin'], 'lonMin' => $tile['lonMin'], 'latMax' => $latMid, 'lonMax' => $lonMid, 'depth' => $depth],
            ['latMin' => $tile['latMin'], 'lonMin' => $lonMid, 'latMax' => $latMid, 'lonMax' => $tile['lonMax'], 'depth' => $depth],
            ['latMin' => $latMid, 'lonMin' => $tile['lonMin'], 'latMax' => $tile['latMax'], 'lonMax' => $lonMid, 'depth' => $depth],
            ['latMin' => $latMid, 'lonMin' => $lonMid, 'latMax' => $tile['latMax'], 'lonMax' => $tile['lonMax'], 'depth' => $depth],
        ];
    }

    /** Tidur tanpa memblokir bila durasi 0 (mode test). */
    private function sleep(int|float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) round($seconds * 1_000_000));
        }
    }

    /**
     * @return array{requests: int, tiles: int, subdivided: int, fetched: int, unique: int, inserted: int, updated: int, skipped_unknown_country: int, countries: array<string, int>, error: string|null}
     */
    private function blank(?string $error = null): array
    {
        return [
            'requests' => 0,
            'tiles' => 0,
            'subdivided' => 0,
            'fetched' => 0,
            'unique' => 0,
            'inserted' => 0,
            'updated' => 0,
            'skipped_unknown_country' => 0,
            'countries' => [],
            'error' => $error,
        ];
    }
}
