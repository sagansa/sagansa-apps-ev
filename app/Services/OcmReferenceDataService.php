<?php

namespace App\Services;

use App\Models\OcmReferenceData;
use Illuminate\Support\Facades\Http;

/**
 * Katalog referensi OpenChargeMap (/v3/referencedata/).
 *
 * Respons POI compact (compact=true, verbose=false) hanya memuat ID integer —
 * OperatorID, ConnectionTypeID, LevelID, CurrentTypeID — tanpa objek Title.
 * Nama hanya tersedia di referencedata, maka snapshot-nya disimpan lokal
 * (tabel ocm_reference_data) & dipakai ocm:import-operators serta
 * hydrateFromOcm agar tidak menghantam API utk tiap POI.
 */
class OcmReferenceDataService
{
    public const SCOPE_OPERATORS = 'operators';

    public const SCOPE_CONNECTION_TYPES = 'connection_types';

    public const SCOPE_LEVELS = 'levels';

    public const SCOPE_CURRENT_TYPES = 'current_types';

    /**
     * Key respons referencedata (lowercase) → scope tabel. Catatan: level
     * OCM (Level 1/2/3) dikirim di key `ChargerTypes` — istilah OCM utk
     * kategori kecepatan, bukan konektor fisik.
     */
    private const SCOPE_BY_RESPONSE_KEY = [
        'operators' => self::SCOPE_OPERATORS,
        'connectiontypes' => self::SCOPE_CONNECTION_TYPES,
        'chargertypes' => self::SCOPE_LEVELS,
        'currenttypes' => self::SCOPE_CURRENT_TYPES,
    ];

    private const ALL_SCOPES = [
        self::SCOPE_OPERATORS,
        self::SCOPE_CONNECTION_TYPES,
        self::SCOPE_LEVELS,
        self::SCOPE_CURRENT_TYPES,
    ];

    /**
     * Tarik & simpan snapshot referencedata.
     *
     * @return array{fetched: int, error: string|null}
     */
    public function fetchAndStore(): array
    {
        $key = config('services.openchargemap.key');
        if (empty($key)) {
            return ['fetched' => 0, 'error' => 'OCM_API_KEY belum diisi'];
        }

        $response = Http::timeout(120)
            ->withHeaders(['User-Agent' => 'ev-app-data-sync/1.0'])
            ->get(config('services.openchargemap.referencedata_url'), ['key' => $key]);

        if (! $response->successful()) {
            return ['fetched' => 0, 'error' => 'HTTP '.$response->status()];
        }

        $data = $response->json();
        if (! is_array($data)) {
            return ['fetched' => 0, 'error' => 'Invalid response'];
        }

        // Respons live memakai PascalCase (Operators/ConnectionTypes/
        // ChargerTypes/CurrentTypes) — normalisasi lower agar map stabil.
        $data = array_change_key_case($data, CASE_LOWER);

        $fetched = 0;
        foreach (self::SCOPE_BY_RESPONSE_KEY as $responseKey => $scope) {
            $rows = collect($data[$responseKey] ?? [])
                ->filter(fn ($row) => is_array($row) && isset($row['ID'], $row['Title']))
                ->map(fn ($row) => ['id' => (int) $row['ID'], 'title' => (string) $row['Title']])
                ->values()
                ->all();

            OcmReferenceData::query()->updateOrCreate(
                ['scope' => $scope],
                ['payload' => $rows, 'fetched_at' => now()]
            );
            $fetched++;
        }

        return ['fetched' => $fetched, 'error' => null];
    }

    /**
     * Pastikan snapshot tersedia & tidak basi (default 24 jam — seumur siklus
     * scheduler harian). Fetch hanya bila scope hilang/stale atau dipaksa.
     *
     * @return array{fetched: int, error: string|null}
     */
    public function ensureFresh(bool $force = false, int $maxAgeHours = 24): array
    {
        if (! $force) {
            $fresh = OcmReferenceData::query()
                ->whereIn('scope', self::ALL_SCOPES)
                ->where('fetched_at', '>=', now()->subHours($maxAgeHours))
                ->count();
            if ($fresh === count(self::ALL_SCOPES)) {
                return ['fetched' => 0, 'error' => null];
            }
        }

        return $this->fetchAndStore();
    }

    /** Memo per-scope — hydrate memanggil lookup per koneksi POI (ribuan kali). */
    private array $maps = [];

    /**
     * Map id → title untuk satu scope (di-memo per instance).
     *
     * @return array<int, string>
     */
    public function map(string $scope): array
    {
        if (! array_key_exists($scope, $this->maps)) {
            $row = OcmReferenceData::query()->find($scope);

            $this->maps[$scope] = collect($row?->payload ?? [])
                ->pluck('title', 'id')
                ->all();
        }

        return $this->maps[$scope];
    }

    public function operatorTitle(int $id): ?string
    {
        return $this->map(self::SCOPE_OPERATORS)[$id] ?? null;
    }

    public function connectionTypeTitle(int $id): ?string
    {
        return $this->map(self::SCOPE_CONNECTION_TYPES)[$id] ?? null;
    }

    public function levelTitle(int $id): ?string
    {
        return $this->map(self::SCOPE_LEVELS)[$id] ?? null;
    }

    public function currentTypeTitle(int $id): ?string
    {
        return $this->map(self::SCOPE_CURRENT_TYPES)[$id] ?? null;
    }
}
