<?php

namespace App\Services;

use App\Models\OpenchargemapPoi;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OcmHarvestService
{
    private const GRID_CELL_DEG = 0.01;
    private const COOLDOWN_TTL = 21600;
    private const DEFAULT_RADIUS_KM = 5;
    private const MAX_RADIUS_KM = 20;
    private const MAX_RESULTS = 500;

    public function harvestNearby(float $lat, float $lng, float $radiusKm, ?string $country = null): array
    {
        $radiusKm = min($radiusKm, self::MAX_RADIUS_KM);
        $country = strtoupper($country ?? config('services.openchargemap.default_country', 'ID'));

        if ($this->isWithinCooldown($lat, $lng)) {
            return ['fetched' => 0, 'inserted' => 0, 'cooldown' => true];
        }

        try {
            $key = config('services.openchargemap.key');
            if (empty($key)) {
                return ['fetched' => 0, 'inserted' => 0, 'error' => 'OCM_API_KEY belum diisi'];
            }

            $response = Http::timeout(30)->get(config('services.openchargemap.base_url'), [
                'key' => $key,
                'latitude' => $lat,
                'longitude' => $lng,
                'distance' => $radiusKm,
                'distanceunit' => 'KM',
                'countrycode' => $country,
                'maxresults' => self::MAX_RESULTS,
                'compact' => 'true',
                'verbose' => 'false',
            ]);

            if (! $response->successful()) {
                Log::warning('OCM API harvest failed', ['status' => $response->status()]);
                return ['fetched' => 0, 'inserted' => 0, 'error' => 'API request failed (HTTP '.$response->status().')'];
            }

            $data = $response->json();
            if (!is_array($data)) {
                return ['fetched' => 0, 'inserted' => 0, 'error' => 'Invalid response'];
            }

            $fetched = count($data);
            $inserted = 0;
            $updated = 0;

            foreach ($data as $point) {
                if ($this->upsertPoi($point, $country)) {
                    $inserted++;
                } else {
                    $updated++;
                }
            }

            $this->setCooldown($lat, $lng);

            return ['fetched' => $fetched, 'inserted' => $inserted, 'updated' => $updated];
        } catch (\Throwable $e) {
            Log::error('OCM harvest error', ['error' => $e->getMessage()]);

            return ['fetched' => 0, 'inserted' => 0, 'error' => $e->getMessage()];
        }
    }

    public function harvestFromLocalFile(string $filePath): array
    {
        if (! file_exists($filePath)) {
            return ['fetched' => 0, 'inserted' => 0, 'error' => 'File not found'];
        }

        $json = json_decode(file_get_contents($filePath), true);
        if ($json === null) {
            return ['fetched' => 0, 'inserted' => 0, 'error' => 'Invalid JSON'];
        }

        $points = $json['points'] ?? [];
        $inserted = 0;
        $updated = 0;

        foreach ($points as $point) {
            if ($this->upsertPoi($point)) {
                $inserted++;
            } else {
                $updated++;
            }
        }

        return ['fetched' => count($points), 'inserted' => $inserted, 'updated' => $updated];
    }

    /**
     * Upsert satu POI dari bentuk API mentah (AddressInfo/Connections) maupun
     * bentuk normalisasi tools (nama/lat/lng/konektor).
     *
     * Publik karena dipakai OcmSyncService (ocm:sync) — jalur import file
     * (ocm:import) & harvest nearby memakai mapper yang sama supaya bentuk
     * data identik.
     *
     * @param  string|null  $countryCode  ISO-2 dari konteks sync/harvest —
     *                                    diutamakan daripada infer dari payload
     *                                    (respons compact hanya memuat CountryID
     *                                    numerik, bukan kode ISO).
     * @return bool true bila POI benar-benar baru (insert), false bila update
     */
    public function upsertPoi(array $point, ?string $countryCode = null): bool
    {
        $ocmId = (int) ($point['ID'] ?? $point['id'] ?? $point['external_id'] ?? 0);
        if ($ocmId === 0) {
            return false;
        }

        $addressInfo = $point['AddressInfo'] ?? [];
        $connections = $point['Connections'] ?? $point['konektor'] ?? null;

        $lat = $addressInfo['Latitude'] ?? $point['lat'] ?? null;
        $lng = $addressInfo['Longitude'] ?? $point['lng'] ?? null;

        if ($lat === null || $lng === null) {
            return false;
        }

        $attributes = [
            'country_code' => strtoupper((string) ($countryCode
                ?? $addressInfo['Country']['ISOCode']
                ?? $point['negara']
                ?? '')) ?: null,
            'uuid' => $point['UUID'] ?? $point['uuid'] ?? null,
            'title' => $addressInfo['Title'] ?? $point['nama'] ?? $point['title'] ?? null,
            'address_line1' => $addressInfo['AddressLine1'] ?? $point['alamat'] ?? null,
            'address_line2' => $addressInfo['AddressLine2'] ?? null,
            'town' => $addressInfo['Town'] ?? $point['kota'] ?? null,
            'state_or_province' => $addressInfo['StateOrProvince'] ?? $point['provinsi'] ?? null,
            'postcode' => $addressInfo['Postcode'] ?? $point['kode_pos'] ?? null,
            'latitude' => (float) $lat,
            'longitude' => (float) $lng,
            'operator' => ($point['OperatorInfo'] ?? [])['Title'] ?? $point['operator'] ?? null,
            'usage_cost' => $point['UsageCost'] ?? null,
            'status_title' => ($point['StatusType'] ?? [])['Title'] ?? $point['status'] ?? null,
            'number_of_points' => $point['NumberOfPoints'] ?? $point['jumlah_poin'] ?? null,
            'data_quality_level' => $point['DataQualityLevel'] ?? $point['kualitas_data'] ?? 1,
            'connections' => is_array($connections) ? $connections : null,
            'raw_payload' => $point,
            'date_created' => $point['DateCreated'] ?? $point['dibuat'] ?? null,
            'date_last_status_update' => $point['DateLastStatusUpdate'] ?? $point['update_terakhir'] ?? null,
            'date_last_verified' => $point['DateLastVerified'] ?? $point['verifikasi_terakhir'] ?? null,
        ];

        $existing = OpenchargemapPoi::query()->where('ocm_id', $ocmId)->first();
        if ($existing === null) {
            OpenchargemapPoi::create($attributes + ['ocm_id' => $ocmId]);

            return true;
        }

        // Jangan hapus country_code yang sudah ada hanya karena jalur import
        // lama (file JSON) tidak menyuplai negara.
        if (($attributes['country_code'] ?? null) === null) {
            unset($attributes['country_code']);
        }

        $existing->fill($attributes)->save();

        return false;
    }

    private function isWithinCooldown(float $lat, float $lng): bool
    {
        $cellLat = round($lat / self::GRID_CELL_DEG) * self::GRID_CELL_DEG;
        $cellLng = round($lng / self::GRID_CELL_DEG) * self::GRID_CELL_DEG;
        $cacheKey = "ocm-harvest:{$cellLat}:{$cellLng}";

        return Cache::has($cacheKey);
    }

    private function setCooldown(float $lat, float $lng): void
    {
        $cellLat = round($lat / self::GRID_CELL_DEG) * self::GRID_CELL_DEG;
        $cellLng = round($lng / self::GRID_CELL_DEG) * self::GRID_CELL_DEG;
        $cacheKey = "ocm-harvest:{$cellLat}:{$cellLng}";

        Cache::put($cacheKey, true, self::COOLDOWN_TTL);
    }
}
