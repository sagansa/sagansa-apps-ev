<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SpkluCsvImportService
{
    /**
     * Koneksi DB yang dipakai untuk semua operasi tulis.
     * Produksi selalu 'ev' (sagansa_ev). Saat testing, default connection
     * (sqlite) dipakai supaya isolasi transaction RefreshDatabase bekerja —
     * pola sama dgn UsesDefaultConnectionWhenTesting pada model.
     */
    private function db(): \Illuminate\Database\Connection
    {
        return app()->environment('testing') ? DB::connection() : DB::connection('ev');
    }

    private function schema(): \Illuminate\Database\Schema\Builder
    {
        return app()->environment('testing')
            ? Schema::connection(config('database.default'))
            : Schema::connection('ev');
    }

    private array $provinceMap = [
        'ACEH' => 1,
        'SUMATERA UTARA' => 2,
        'SUMATERA BARAT' => 3,
        'RIAU' => 4,
        'JAMBI' => 5,
        'SUMATERA SELATAN' => 6,
        'BENGKULU' => 7,
        'LAMPUNG' => 8,
        'KEP. BANGKA BELITUNG' => 9,
        'KEPULAUAN BANGKA BELITUNG' => 9,
        'BABEL' => 9,
        'KEPULAUAN RIAU' => 10,
        'RIAU DAN KEPRI' => 10,
        'DKI JAKARTA' => 11,
        'JAKARTA' => 11,
        'JAWA BARAT' => 12,
        'JAWA TENGAH' => 13,
        'DI YOGYAKARTA' => 14,
        'JAWA TIMUR' => 15,
        'BANTEN' => 16,
        'BALI' => 17,
        'NUSA TENGGARA BARAT' => 18,
        'NTB' => 18,
        'NUSA TENGGARA TIMUR' => 19,
        'NTT' => 19,
        'KALIMANTAN BARAT' => 20,
        'KALIMANTAN TENGAH' => 21,
        'KALIMANTAN SELATAN' => 22,
        'KALIMANTAN TIMUR' => 23,
        'KALIMANTAN UTARA' => 24,
        'SULAWESI UTARA' => 25,
        'SULAWESI TENGAH' => 26,
        'SULAWESI SELATAN' => 27,
        'SULAWESI TENGGARA' => 28,
        'GORONTALO' => 29,
        'SULAWESI BARAT' => 30,
        'MALUKU' => 31,
        'MALUKU UTARA' => 32,
        'PAPUA' => 33,
        'PAPUA BARAT' => 34,
    ];

    private array $merkMap = [
        'ABB' => 2,
        'ALTRO' => 3,
        'ATESS' => 4,
        'AURORA' => 5,
        'AUTEL' => 6,
        'BENY' => 7,
        'CHARGECORE' => 8,
        'CIRCONTROL' => 9,
        'CORNERSTONE' => 10,
        'DELTA' => 11,
        'EV' => 12,
        'EV CITY' => 13,
        'EV POWER' => 14,
        'EXICOM' => 15,
        'FASTROOM' => 16,
        'HIMEL' => 17,
        'HVT' => 18,
        'INJECT' => 20,
        'MARVEL' => 21,
        'PHIHONG' => 22,
        'PROTEKSINDO' => 23,
        'SCHNEIDER' => 24,
        'SIGNET' => 25,
        'SINO' => 26,
        'SSKE' => 27,
        'STARCHARGE' => 28,
        'STARVO' => 29,
        'TEISON' => 30,
        'TERRA' => 31,
        'TIAR' => 32,
        'VOLTRON' => 33,
        'WALLBOX' => 34,
        'WULING' => 35,
        'ZEROVA' => 36,
        'MOBI' => null,
        'OPINTEH' => null,
        'CBI' => null,
    ];

    private array $chargingTypeMap = [
        'FAST CHARGING' => 1,
        'MEDIUM CHARGING' => 2,
        'STANDARD CHARGING' => 3,
        'ULTRA FAST CHARGING' => 4,
    ];

    private array $clusterIslandSeed = [
        '' => 'Tidak Diketahui',
        'JAWA' => 'Jawa',
        'SUMATERA' => 'Sumatera',
        'BALI' => 'Bali & Nusa Tenggara',
        'KALIMANTAN' => 'Kalimantan',
        'SULAWESI' => 'Sulawesi',
        'NUSA TENGGARA' => 'Nusa Tenggara',
        'PAPUA' => 'Papua & Maluku',
        'MALUKU' => 'Papua & Maluku',
    ];

    private array $providerAliasMap = [
        'PLN' => 'PLN Mobile',
        'PERTAMINA' => 'Pertamina',
        'SHELL' => 'Shell',
        'VOLTRON' => 'Voltron',
        'STARVO' => 'Starvo',
        'WULING' => 'Wuling',
        'HYUNDAI' => 'Hyundai',
        'TOYOTA' => 'Toyota Lexus',
        'LEXUS' => 'Toyota Lexus',
        'ASTRA' => 'Astra',
        'PORSCHE' => 'Porsche',
        'BLUECHARGE' => 'BlueCharge',
        'CHARGE+' => 'Charge+',
        'CHARGE POINT' => 'ChargePoint',
        'CHARGEPOINT' => 'ChargePoint',
        'TERRA' => 'Terra Charge',
        'HVT' => 'HVT Charging',
    ];

    private array $clusterIslandMap = [];

    private array $resolvedChargingTypes = [];

    private array $resolvedMerkChargers = [];

    private array $resolvedProviders = [];

    private array $resolvedLocationCategories = [];

    private array $resolvedChargerCategories = [];

    private array $requiredHeaders = [
        'Chargebox ID',
        'ID Spklu',
        'Merek',
        'Nama Chargebox',
        'Daya Chargebox',
        'Kategori',
        'Is Active',
        'Tgl Integrasi',
        'Nama Spklu',
        'Alamat Spklu',
        'Latitude',
        'Longitude',
        'Propinsi',
        'Cluster Pulau',
        'Kepemilikan Mesin',
        'Kategori Tol',
        'Kategori2',
        'Kategori3',
        'Cluster Peruntukan',
    ];

    public function import(string $filePath, bool $replaceExisting = true, bool $dryRun = false, bool $prune = false): array
    {
        if (! file_exists($filePath)) {
            throw new InvalidArgumentException("File tidak ditemukan: {$filePath}");
        }

        $rows = $this->parseCsv($filePath);
        $grouped = $this->groupRowsBySpkluId($rows);

        $summary = [
            'total_rows' => count($rows),
            'locations' => count($grouped),
            'details' => array_sum(array_map('count', $grouped)),
            'deleted_locations' => 0,
            'deleted_details' => 0,
            'inserted_locations' => 0,
            'inserted_details' => 0,
            'skipped_rows' => count($rows) - array_sum(array_map('count', $grouped)),
            'replace_existing' => $replaceExisting,
            'dry_run' => $dryRun,
            'pruned_locations' => 0,
        ];

        if ($dryRun) {
            return $summary;
        }

        return $this->db()->transaction(function () use ($grouped, $replaceExisting, $prune, $summary) {
            $this->ensureClusterIslands();

            if ($replaceExisting) {
                $summary['deleted_details'] = $this->db()
                    ->table('pln_charger_location_details')
                    ->delete();

                $summary['deleted_locations'] = $this->db()
                    ->table('pln_charger_locations')
                    ->delete();
            }

            foreach ($grouped as $spkluId => $chargeboxes) {
                $firstRow = $chargeboxes[0];
                $existingLocation = null;

                if (! $replaceExisting) {
                    $existingLocation = $this->db()
                        ->table('pln_charger_locations')
                        ->where('pln_id', $spkluId)
                        ->first();
                }

                $locationId = $existingLocation?->id;
                $locationData = $this->buildLocation($spkluId, $firstRow);

                if ($existingLocation) {
                    $this->db()
                        ->table('pln_charger_locations')
                        ->where('id', $locationId)
                        ->update($locationData);

                    $this->db()
                        ->table('pln_charger_location_details')
                        ->where('pln_charger_location_id', $locationId)
                        ->delete();
                } else {
                    $locationData['created_at'] = now();
                    $locationId = $this->db()
                        ->table('pln_charger_locations')
                        ->insertGetId($locationData);
                    $summary['inserted_locations']++;
                }

                foreach ($chargeboxes as $chargebox) {
                    $this->db()
                        ->table('pln_charger_location_details')
                        ->insert($this->buildDetail($locationId, $chargebox));
                    $summary['inserted_details']++;
                }
            }

            if ($prune) {
                $summary['pruned_locations'] = $this->pruneLocations($grouped);
            }

            return $summary;
        });
    }

    /**
     * Hapus lokasi yang pln_id-nya tidak ada lagi di CSV (hanya baris ber-pln_id).
     * Detail dihapus dulu (tabel detail tidak punya cascade), lalu lokasinya.
     */
    private function pruneLocations(array $grouped): int
    {
        $csvIds = array_keys($grouped);

        if ($csvIds === []) {
            return 0;
        }

        $staleIds = $this->db()
            ->table('pln_charger_locations')
            ->whereNotNull('pln_id')
            ->whereNotIn('pln_id', $csvIds)
            ->pluck('id')
            ->all();

        if ($staleIds === []) {
            return 0;
        }

        $this->db()
            ->table('pln_charger_location_details')
            ->whereIn('pln_charger_location_id', $staleIds)
            ->delete();

        return $this->db()
            ->table('pln_charger_locations')
            ->whereIn('id', $staleIds)
            ->delete();
    }

    public function preview(string $filePath): array
    {
        return $this->import($filePath, true, true);
    }

    public function importFromFiles(string $locationFilePath, string $detailFilePath, bool $replaceExisting = true, bool $dryRun = false, bool $prune = false): array
    {
        if (! file_exists($locationFilePath)) {
            throw new InvalidArgumentException("File lokasi tidak ditemukan: {$locationFilePath}");
        }

        if (! file_exists($detailFilePath)) {
            throw new InvalidArgumentException("File detail charger tidak ditemukan: {$detailFilePath}");
        }

        $locationRows = $this->parseCsv($locationFilePath);
        $detailRows = $this->parseCsv($detailFilePath);
        $locationsBySpkluId = $this->uniqueRowsBySpkluId($locationRows);
        $detailsBySpkluId = $this->groupRowsBySpkluId($detailRows);
        $validDetailCount = array_sum(array_map('count', $detailsBySpkluId));
        $missingLocationDetailCount = 0;

        foreach ($detailsBySpkluId as $spkluId => $details) {
            if (! array_key_exists($spkluId, $locationsBySpkluId)) {
                $missingLocationDetailCount += count($details);
            }
        }

        $summary = [
            'total_rows' => count($locationRows) + count($detailRows),
            'location_rows' => count($locationRows),
            'detail_rows' => count($detailRows),
            'locations' => count($locationsBySpkluId),
            'details' => $validDetailCount - $missingLocationDetailCount,
            'deleted_locations' => 0,
            'deleted_details' => 0,
            'inserted_locations' => 0,
            'inserted_details' => 0,
            'skipped_rows' => (count($locationRows) - count($locationsBySpkluId))
                + (count($detailRows) - $validDetailCount)
                + $missingLocationDetailCount,
            'replace_existing' => $replaceExisting,
            'dry_run' => $dryRun,
            'pruned_locations' => 0,
        ];

        if ($dryRun) {
            return $summary;
        }

        return $this->db()->transaction(function () use ($locationsBySpkluId, $detailsBySpkluId, $replaceExisting, $prune, $summary) {
            $this->ensureClusterIslands();

            if ($replaceExisting) {
                $summary['deleted_details'] = $this->db()
                    ->table('pln_charger_location_details')
                    ->delete();

                $summary['deleted_locations'] = $this->db()
                    ->table('pln_charger_locations')
                    ->delete();
            }

            foreach ($locationsBySpkluId as $spkluId => $locationRow) {
                $existingLocation = null;

                if (! $replaceExisting) {
                    $existingLocation = $this->db()
                        ->table('pln_charger_locations')
                        ->where('pln_id', $spkluId)
                        ->first();
                }

                $locationId = $existingLocation?->id;
                $locationData = $this->buildLocation($spkluId, $locationRow);

                if ($existingLocation) {
                    $this->db()
                        ->table('pln_charger_locations')
                        ->where('id', $locationId)
                        ->update($locationData);

                    $this->db()
                        ->table('pln_charger_location_details')
                        ->where('pln_charger_location_id', $locationId)
                        ->delete();
                } else {
                    $locationData['created_at'] = now();
                    $locationId = $this->db()
                        ->table('pln_charger_locations')
                        ->insertGetId($locationData);
                    $summary['inserted_locations']++;
                }

                foreach ($detailsBySpkluId[$spkluId] ?? [] as $detailRow) {
                    $this->db()
                        ->table('pln_charger_location_details')
                        ->insert($this->buildDetail($locationId, $detailRow));
                    $summary['inserted_details']++;
                }
            }

            if ($prune) {
                $summary['pruned_locations'] = $this->pruneLocations($locationsBySpkluId);
            }

            return $summary;
        });
    }

    public function previewFromFiles(string $locationFilePath, string $detailFilePath): array
    {
        return $this->importFromFiles($locationFilePath, $detailFilePath, true, true);
    }

    private function ensureClusterIslands(): void
    {
        $existing = $this->db()->table('cluster_islands')->get()->keyBy('name');

        foreach ($this->clusterIslandSeed as $csvKey => $name) {
            $row = $existing->get($name);

            if (! $row) {
                $id = $this->db()->table('cluster_islands')->insertGetId(array_merge([
                    'name' => $name,
                ], $this->timestampColumns('cluster_islands')));
            } else {
                $id = $row->id;
            }

            $this->clusterIslandMap[$csvKey] = $id;
        }
    }

    private function parseCsv(string $filePath): array
    {
        $handle = fopen($filePath, 'r');
        $headers = fgetcsv($handle, 0, ',', '"', '\\');

        if (! $headers) {
            fclose($handle);
            throw new InvalidArgumentException('CSV tidak memiliki header.');
        }

        $headers[0] = preg_replace('/[\x{FEFF}]/u', '', $headers[0]);
        $headers = array_map('trim', $headers);
        $missingHeaders = array_values(array_diff($this->requiredHeaders, $headers));

        if ($missingHeaders) {
            fclose($handle);
            throw new InvalidArgumentException('Header CSV tidak lengkap: '.implode(', ', $missingHeaders));
        }

        $rows = [];
        while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if (count($data) !== count($headers)) {
                continue;
            }

            $rows[] = array_combine($headers, $data);
        }

        fclose($handle);

        return $rows;
    }

    private function groupRowsBySpkluId(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $spkluId = trim($row['ID Spklu'] ?? '');

            if ($spkluId === '' || ! is_numeric($spkluId)) {
                continue;
            }

            $grouped[$spkluId][] = $row;
        }

        return $grouped;
    }

    private function uniqueRowsBySpkluId(array $rows): array
    {
        $unique = [];

        foreach ($rows as $row) {
            $spkluId = trim($row['ID Spklu'] ?? '');

            if ($spkluId === '' || ! is_numeric($spkluId) || array_key_exists($spkluId, $unique)) {
                continue;
            }

            $unique[$spkluId] = $row;
        }

        return $unique;
    }

    private function buildLocation(string $spkluId, array $row): array
    {
        $clusterPulau = strtoupper(trim($row['Cluster Pulau'] ?? ''));
        $clusterIslandId = $this->clusterIslandMap[$clusterPulau]
            ?? $this->clusterIslandMap['']
            ?? null;

        [$latitude, $longitude] = $this->parseCoordWithinIndonesia(
            $row['Latitude'] ?? '',
            $row['Longitude'] ?? ''
        );

        return [
            'pln_id' => $spkluId,
            'name' => trim($row['Nama Spklu'] ?? ''),
            'address' => trim($row['Alamat Spklu'] ?? ''),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'province_id' => $this->mapProvince($row['Propinsi'] ?? ''),
            'cluster_island_id' => $clusterIslandId,
            'owner_machine' => trim($row['Kepemilikan Mesin'] ?? ''),
            'provider_id' => $this->resolveProviderId($row['Provider'] ?? '', $row['Nama Spklu'] ?? '', $row['Kepemilikan Mesin'] ?? ''),
            'location_category_id' => $this->resolveLocationCategoryId($row),
            'kategori_tol' => trim($row['Kategori Tol'] ?? '') ?: null,
            'updated_at' => now(),
        ];
    }

    private function buildDetail(int $locationId, array $row): array
    {
        $operationDate = $this->parseDate($row['Tgl Integrasi'] ?? '');

        return [
            'pln_charger_location_id' => $locationId,
            'chargebox_id' => trim($row['Chargebox ID'] ?? ''),
            'chargebox_name' => trim($row['Nama Chargebox'] ?? ''),
            'power' => (string) ($this->parsePower($row['Daya Chargebox'] ?? '') ?? ''),
            'is_active_charger' => strtoupper(trim($row['Is Active'] ?? 'N')) === 'Y' ? 'Y' : 'N',
            'operation_date' => $operationDate,
            'charging_type_id' => $this->resolveChargingTypeId($row['Kategori'] ?? ''),
            'merk_charger_id' => $this->resolveMerkChargerId($row['Merek'] ?? ''),
            'category_charger_id' => $this->resolveChargerCategoryId($row['Kategori'] ?? ''),
            'count_connector_charger' => 1,
            'year' => $operationDate ? (int) Carbon::parse($operationDate)->format('Y') : null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function mapProvince(string $name): ?int
    {
        return $this->provinceMap[strtoupper(trim($name))] ?? null;
    }

    private function resolveChargingTypeId(string $name): ?int
    {
        $normalizedName = strtoupper(trim($name));

        if ($normalizedName === '') {
            return null;
        }

        if (array_key_exists($normalizedName, $this->resolvedChargingTypes)) {
            return $this->resolvedChargingTypes[$normalizedName];
        }

        $mappedId = $this->chargingTypeMap[$normalizedName] ?? null;

        if ($mappedId) {
            return $this->resolvedChargingTypes[$normalizedName] = $mappedId;
        }

        $existingId = $this->db()
            ->table('charging_types')
            ->whereRaw('UPPER(name) = ?', [$normalizedName])
            ->value('id');

        if ($existingId) {
            return $this->resolvedChargingTypes[$normalizedName] = (int) $existingId;
        }

        $id = $this->db()->table('charging_types')->insertGetId(array_merge([
            'name' => $normalizedName,
        ], $this->timestampColumns('charging_types')));

        return $this->resolvedChargingTypes[$normalizedName] = (int) $id;
    }

    private function resolveMerkChargerId(string $name): int|string|null
    {
        $normalizedName = strtoupper(trim($name));

        if ($normalizedName === '') {
            return null;
        }

        if (array_key_exists($normalizedName, $this->resolvedMerkChargers)) {
            return $this->resolvedMerkChargers[$normalizedName];
        }

        if (array_key_exists($normalizedName, $this->merkMap)) {
            return $this->resolvedMerkChargers[$normalizedName] = $this->merkMap[$normalizedName];
        }

        $existingId = $this->db()
            ->table('merk_chargers')
            ->whereRaw('UPPER(name) = ?', [$normalizedName])
            ->value('id');

        if ($existingId) {
            return $this->resolvedMerkChargers[$normalizedName] = $existingId;
        }

        $idColumnType = $this->schema()->getColumnType('merk_chargers', 'id');
        $isStringKey = in_array($idColumnType, ['char', 'string', 'varchar'], true);

        if ($isStringKey) {
            $id = (string) Str::uuid();

            $this->db()->table('merk_chargers')->insert(array_merge([
                'id' => $id,
                'name' => $normalizedName,
            ], $this->timestampColumns('merk_chargers')));

            return $this->resolvedMerkChargers[$normalizedName] = $id;
        }

        $id = $this->db()->table('merk_chargers')->insertGetId(array_merge([
            'name' => $normalizedName,
        ], $this->timestampColumns('merk_chargers')));

        return $this->resolvedMerkChargers[$normalizedName] = (int) $id;
    }

    private function resolveProviderId(string $providerName, string $spkluName = '', string $ownerMachine = ''): ?string
    {
        $providerName = $this->cleanName($providerName);
        $normalizedSpkluName = $this->normalizeLookup($spkluName);
        $normalizedProviderName = $this->normalizeLookup($providerName);

        if ($normalizedProviderName === '' && $normalizedSpkluName === '') {
            return null;
        }

        $cacheKey = $normalizedProviderName !== ''
            ? "PROVIDER:{$normalizedProviderName}"
            : "SPKLU:{$normalizedSpkluName}";

        if (array_key_exists($cacheKey, $this->resolvedProviders)) {
            return $this->resolvedProviders[$cacheKey];
        }

        $providers = $this->db()
            ->table('providers')
            ->select(['id', 'name'])
            ->get();

        if ($normalizedProviderName !== '') {
            $provider = $providers->first(
                fn ($provider) => $this->normalizeLookup($provider->name) === $normalizedProviderName
            );

            return $this->resolvedProviders[$cacheKey] = $provider?->id
                ?? $this->resolveUuidMasterId('providers', $providerName, ['status' => 1]);
        }

        foreach ($this->providerAliasMap as $needle => $providerName) {
            if (! str_contains($normalizedSpkluName, $this->normalizeLookup($needle))) {
                continue;
            }

            $provider = $providers->first(
                fn ($provider) => $this->normalizeLookup($provider->name) === $this->normalizeLookup($providerName)
            );

            return $this->resolvedProviders[$cacheKey] = $provider?->id
                ?? $this->resolveUuidMasterId('providers', $providerName, ['status' => 1]);
        }

        $matchedProvider = $providers
            ->filter(fn ($provider) => $this->normalizeLookup($provider->name) !== '')
            ->filter(fn ($provider) => str_contains($normalizedSpkluName, $this->normalizeLookup($provider->name)))
            ->sortByDesc(fn ($provider) => strlen($this->normalizeLookup($provider->name)))
            ->first();

        if ($matchedProvider) {
            return $this->resolvedProviders[$cacheKey] = $matchedProvider->id;
        }

        // Fallback terakhir: provider PLN yang sudah ada. Dulu fallback-nya
        // menjadikan nama lokasi sebagai provider baru — inilah sumber
        // provider sampah "Mall", "Kantor", "Hotel", "Rumah", dst. di app.
        // Jangan pernah membuat baris provider dari nama lokasi.
        return $this->resolvedProviders[$cacheKey] = $this->plnProviderId($providers);
    }

    /**
     * Id provider PLN yang sudah ada di tabel providers (tanpa membuat baru):
     * "PLN Mobile" persis, lalu nama yang mengandung PLN. Null bila tidak ada.
     */
    private function plnProviderId($providers): ?string
    {
        $pln = $providers->first(fn ($p) => $this->normalizeLookup($p->name) === $this->normalizeLookup('PLN Mobile'))
            ?? $providers->first(fn ($p) => str_contains($this->normalizeLookup($p->name), $this->normalizeLookup('PLN')));

        return $pln?->id;
    }

    private function resolveLocationCategoryId(array $row): ?int
    {
        $candidates = $this->cleanCandidates([
            $row['Kategori3'] ?? '',
            $row['Cluster Peruntukan'] ?? '',
            $row['Kategori2'] ?? '',
            $row['Kategori Tol'] ?? '',
        ]);

        return $this->resolveIntegerMasterId('location_categories', $candidates, $this->resolvedLocationCategories);
    }

    private function resolveChargerCategoryId(string $category): ?int
    {
        $candidates = $this->cleanCandidates([$category]);

        return $this->resolveIntegerMasterId('charger_categories', $candidates, $this->resolvedChargerCategories);
    }

    private function resolveIntegerMasterId(string $table, array $candidates, array &$cache): ?int
    {
        if ($candidates === []) {
            return null;
        }

        foreach ($candidates as $candidate) {
            $normalizedName = $this->normalizeLookup($candidate);

            if (array_key_exists($normalizedName, $cache)) {
                return $cache[$normalizedName];
            }

            $existingId = $this->db()
                ->table($table)
                ->whereRaw('UPPER(name) = ?', [$normalizedName])
                ->value('id');

            if ($existingId) {
                return $cache[$normalizedName] = (int) $existingId;
            }
        }

        $name = $candidates[0];
        $normalizedName = $this->normalizeLookup($name);
        $id = $this->db()->table($table)->insertGetId(array_merge([
            'name' => $name,
        ], $this->timestampColumns($table)));

        return $cache[$normalizedName] = (int) $id;
    }

    private function resolveUuidMasterId(string $table, string $name, array $extraColumns = []): ?string
    {
        $name = $this->cleanName($name);

        if ($name === '') {
            return null;
        }

        $normalizedName = $this->normalizeLookup($name);
        $existingId = $this->db()
            ->table($table)
            ->whereRaw('UPPER(name) = ?', [$normalizedName])
            ->value('id');

        if ($existingId) {
            return $existingId;
        }

        $id = (string) Str::uuid();

        $this->db()->table($table)->insert(array_merge($extraColumns, [
            'id' => $id,
            'name' => $name,
        ], $this->timestampColumns($table)));

        return $id;
    }

    private function cleanCandidates(array $values): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn ($value) => $this->cleanName((string) $value), $values),
            fn ($value) => $value !== '',
        )));
    }

    private function cleanName(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', $name) ?? '');
    }

    private function normalizeLookup(string $name): string
    {
        return strtoupper($this->cleanName($name));
    }

    private function timestampColumns(string $table): array
    {
        $timestamps = [];

        if ($this->schema()->hasColumn($table, 'created_at')) {
            $timestamps['created_at'] = now();
        }

        if ($this->schema()->hasColumn($table, 'updated_at')) {
            $timestamps['updated_at'] = now();
        }

        return $timestamps;
    }

    private function parseCoord(string $value): ?float
    {
        $value = str_replace(',', '.', trim($value));

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Parse pasangan koordinat lalu validasi terhadap bound Indonesia.
     * Koordinat di luar bound (mis. 41260 rusak) di-set null — lokasi tetap
     * masuk (audit via raw_payload di hydrate), hanya tidak di-serving.
     */
    private function parseCoordWithinIndonesia(string $latRaw, string $lngRaw): array
    {
        $lat = $this->parseCoord($latRaw);
        $lng = $this->parseCoord($lngRaw);

        $inBounds = $lat !== null
            && $lng !== null
            && $lat >= -12
            && $lat <= 8
            && $lng >= 94
            && $lng <= 142;

        return $inBounds ? [$lat, $lng] : [null, null];
    }

    private function parsePower(string $value): ?float
    {
        $value = str_replace(',', '.', $value);
        preg_match('/[\d.]+/', $value, $matches);

        return isset($matches[0]) ? (float) $matches[0] : null;
    }

    private function parseDate(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
