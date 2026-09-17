<?php

namespace App\Services;

use App\Models\ChargingStation;
use App\Models\ChargingStationCharger;
use App\Models\ChargingStationConnector;
use App\Models\EsdmSinggatSpkluStation;
use App\Models\EsdmSinggatStationStatus;
use App\Models\PlnChargerLocation;
use App\Models\PlnChargerLocationDetail;
use App\Models\PlnEsdmStationMatch;
use App\Models\Provider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Hydrate tabel kanonik charging_stations dari source adaptor (ESDM saat ini).
 *
 * Baca raw ESDM yang sudah di-import + di-cleaning, roll-up instalasi menjadi
 * agregat per stasiun, lalu upsert ke charging_stations by (source,
 * source_station_id). Idempoten & re-runnable — source berganti tinggal
 * menambah method hydrate baru (mis. hydrateFromX()).
 *
 * Prinsip:
 *  - type_charge disimpan verbatim dari ESDM ("Medium Charging" dll).
 *  - provider: guess via ScrapeDedupService, fallback nama_badan_usaha raw.
 *  - child charging_station_chargers: replace per station.
 */
class CanonicalStationHydrateService
{
    public const SOURCE_ESDM = 'esdm';

    public const SOURCE_PLN = 'pln';

    /** BPS kode_provinsi → nama provinsi (title case, konsisten dgn data historis). */
    public const PROVINCE_BY_BPS_CODE = [
        '11' => 'Aceh',
        '12' => 'Sumatera Utara',
        '13' => 'Sumatera Barat',
        '14' => 'Riau',
        '15' => 'Jambi',
        '16' => 'Sumatera Selatan',
        '17' => 'Bengkulu',
        '18' => 'Lampung',
        '19' => 'Bangka Belitung',
        '21' => 'Kepulauan Riau',
        '31' => 'DKI Jakarta',
        '32' => 'Jawa Barat',
        '33' => 'Jawa Tengah',
        '34' => 'DI Yogyakarta',
        '35' => 'Jawa Timur',
        '36' => 'Banten',
        '51' => 'Bali',
        '52' => 'Nusa Tenggara Barat',
        '53' => 'Nusa Tenggara Timur',
        '61' => 'Kalimantan Barat',
        '62' => 'Kalimantan Tengah',
        '63' => 'Kalimantan Selatan',
        '64' => 'Kalimantan Timur',
        '65' => 'Kalimantan Utara',
        '71' => 'Sulawesi Utara',
        '72' => 'Sulawesi Tengah',
        '73' => 'Sulawesi Selatan',
        '74' => 'Sulawesi Tenggara',
        '75' => 'Gorontalo',
        '76' => 'Sulawesi Barat',
        '81' => 'Maluku',
        '82' => 'Maluku Utara',
        '91' => 'Papua Barat',
        '92' => 'Papua',
    ];

    /** Label verbatim ESDM → tier kecepatan (semakin besar = semakin cepat). */
    private const TYPE_CHARGE_TIER = [
        'Slow Charging' => 1,
        'Medium Charging' => 2,
        'Fast Charging' => 3,
        'Ultra Fast Charging' => 4,
    ];

    /**
     * Label ESDM → label watt display (fallback; ESDM tidak menyediakan watt).
     * Kategori sesuai spesifikasi:
     *  - Slow/Medium Charging = AC, ≤22 kW
     *  - Fast Charging        = DC, 25–50 kW
     *  - Ultra Fast Charging  = DC, >50 kW (50kW, 60kW, 120kW, 150kW, 380kW, dst)
     */
    private const TYPE_CHARGE_WATT = [
        'Slow Charging' => '≤7 kW AC',
        'Medium Charging' => '≤22 kW AC',
        'Fast Charging' => '25–50 kW DC',
        'Ultra Fast Charging' => '>50 kW DC',
    ];

    /**
     * Nilai `is_active_charger` yang dianggap AKTIF. Sama dengan daftar yang
     * dipakai PlnChargerLocationController (API web & mobile PLN) — import CSV
     * menulis 'Y'/'N', tapi data lama bisa memakai varian lain.
     */
    private const ACTIVE_CHARGER_VALUES = ['Y', 'y', '1', 1, true, 'true', 'TRUE', 'aktif', 'Aktif', 'AKTIF'];

    /**
     * Label charging_type PLN (uppercase di charging_types) → label type_charge
     * canonical. "STANDARD CHARGING" dipetakan ke "Slow Charging" (bukan
     * "Standard") agar konsisten dgn konvensi label ESDM & filter mobile.
     */
    private const CHARGING_TYPE_TO_CANONICAL = [
        'FAST CHARGING' => 'Fast Charging',
        'MEDIUM CHARGING' => 'Medium Charging',
        'STANDARD CHARGING' => 'Slow Charging',
        'ULTRA FAST CHARGING' => 'Ultra Fast Charging',
    ];

    /**
     * Hydrate seluruh stasiun ESDM SPKLU ke charging_stations.
     *
     * Bila ada stasiun ESDM yang latitude/longitude-nya belum di-clean (masih
     * NULL padahal *_raw ada), otomatis jalankan geo cleaning dulu. Ini membuat
     * pipeline production-safe: urutan import → hydrate tetap menghasilkan
     * koordinat, tanpa harus ingat menjalankan esdm:clean-geo terpisah.
     *
     * Setelah charger box di-hydrate, fold status real-time per-box dari
     * connector_status yang sudah ada (bila poller pernah jalan). Charger box
     * yang konektornya belum terlacak tetap 'unknown' sampai poller berjalan.
     *
     * @return array{processed: int, created: int, updated: int, skipped: int, chargers: int, geo_cleaned: int, charger_boxes_folded: int}
     */
    public function hydrateFromEsdm(): array
    {
        // Auto-clean geo bila ada stasiun yang belum di-normalize (production safety).
        $geoCleaned = 0;
        $needsGeoClean = EsdmSinggatSpkluStation::whereNull('latitude')
            ->whereNotNull('latitude_spklu_raw')
            ->exists();
        if ($needsGeoClean) {
            $cleaning = app(EsdmSinggatGeoCleaningService::class);
            $result = $cleaning->clean(false);
            $geoCleaned = $result['spklu']['fixed'] ?? 0;
        }

        $stations = EsdmSinggatSpkluStation::with(['installations.connectors'])->get();

        $statusMap = EsdmSinggatStationStatus::query()
            ->get()
            ->keyBy('station_esdm_id');

        $guesser = new ScrapeDedupService;
        $plnProvider = Provider::where('name', 'PLN Mobile')->first();

        $stats = ['processed' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'chargers' => 0];

        DB::connection('ev')->transaction(function () use ($stations, $statusMap, $guesser, $plnProvider, &$stats) {
            foreach ($stations as $station) {
                $stats['processed']++;

                $data = $this->buildStationData($station, $statusMap, $guesser, $plnProvider);
                if ($data === null) {
                    $stats['skipped']++;

                    continue;
                }

                [$canonical, $masterChanged] = $this->upsertCanonicalStation(
                    self::SOURCE_ESDM,
                    $station->esdm_id,
                    $data
                );
                $stats[$canonical->wasRecentlyCreated
                    ? 'created'
                    : ($masterChanged ? 'updated' : 'unchanged')]++;

                [$chargersWritten, $chargersChanged] = $this->replaceChargersIfChanged($canonical, $station);
                $stats['chargers'] += $chargersWritten;

                if ($chargersChanged && ! $canonical->wasRecentlyCreated) {
                    $canonical->touch();
                }
            }
        });

        // Fold status real-time per-charger box dari connector_status yg sudah ada.
        // Backfill awal: semua charger box dapat status dari snapshot konektor terakhir.
        $chargerFolded = $this->foldAllChargerBoxesFromConnectorStatus();

        // Backfill status per-konektor (plug individual) dari snapshot terbaru.
        $connectorFolded = $this->foldAllConnectorsFromStatus();

        $stats['geo_cleaned'] = $geoCleaned;
        $stats['charger_boxes_folded'] = $chargerFolded;
        $stats['connectors_folded'] = $connectorFolded;

        return $stats;
    }

    /**
     * Hydrate seluruh stasiun PLN (pln_charger_locations) ke charging_stations.
     *
     * Satu lokasi PLN → satu stasiun canonical; satu detail charger aktif →
     * satu child charging_station_chargers (tanpa connectors — PLN tidak
     * melacak plug individual). Idempoten & re-runnable: upsert change-aware
     * by (source, source_station_id) — baris yang tidak berubah tidak
     * ditulis ulang (updated_at stabil), child charger disinkronkan by
     * source_charger_id.
     *
     * Setelah upsert, stasiun canonical source='pln' yang lokasinya sudah tidak
     * punya detail charger aktif (hilang dari CSV / semua non-aktif) di-prune
     * bersama link pln_esdm_station_matches yang menunjuknya.
     *
     * Data ESDM di tabel charging_stations TIDAK disentuh (source berbeda);
     * serving bisa dialihkan via config spklu.serving_source.
     *
     * @return array{processed: int, created: int, updated: int, unchanged: int, skipped: int, chargers: int, pruned: int}
     */
    public function hydrateFromPln(): array
    {
        $locations = PlnChargerLocation::with([
            'provider',
            'province',
            'plnChargerLocationDetails' => function ($query) {
                $query->whereIn('is_active_charger', self::ACTIVE_CHARGER_VALUES);
            },
            'plnChargerLocationDetails.chargerCategory',
            'plnChargerLocationDetails.merkCharger',
            'plnChargerLocationDetails.chargingType',
        ])
            ->whereHas('plnChargerLocationDetails', function ($query) {
                $query->whereIn('is_active_charger', self::ACTIVE_CHARGER_VALUES);
            })
            ->get();

        $stats = ['processed' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'chargers' => 0, 'pruned' => 0];

        DB::connection('ev')->transaction(function () use ($locations, &$stats) {
            foreach ($locations as $location) {
                $stats['processed']++;

                $data = $this->buildPlnStationData($location);
                if ($data === null) {
                    $stats['skipped']++;

                    continue;
                }

                [$canonical, $masterChanged] = $this->upsertCanonicalStation(
                    self::SOURCE_PLN,
                    $location->id,
                    $data
                );
                $stats[$canonical->wasRecentlyCreated
                    ? 'created'
                    : ($masterChanged ? 'updated' : 'unchanged')]++;

                [$chargersWritten, $chargersChanged] = $this->syncPlnChargers($canonical, $location);
                $stats['chargers'] += $chargersWritten;

                // Perubahan nested charger adalah perubahan master — touch
                // stasiun induk supaya tertangkap delta sync klien.
                if ($chargersChanged && ! $canonical->wasRecentlyCreated) {
                    $canonical->touch();
                }
            }

            $stats['pruned'] = $this->pruneStalePlnStations($locations);
        });

        return $stats;
    }

    /**
     * Hapus stasiun canonical source='pln' yang lokasinya sudah tidak punya
     * detail charger aktif (hilang dari CSV / semua charger non-aktif), plus
     * link pln_esdm_station_matches yang menunjuk stasiun ter-prune.
     * Child chargers & connectors ikut terhapus via FK cascade.
     */
    private function pruneStalePlnStations($activeLocations): int
    {
        $activeLocationIds = $activeLocations->pluck('id')->all();

        $staleIds = ChargingStation::query()
            ->where('source', self::SOURCE_PLN)
            ->whereNotIn('source_station_id', $activeLocationIds)
            ->pluck('id')
            ->all();

        if ($staleIds === []) {
            return 0;
        }

        PlnEsdmStationMatch::whereIn('pln_station_id', $staleIds)->delete();

        return ChargingStation::query()
            ->whereIn('id', $staleIds)
            ->delete();
    }

    /**
     * Fold status untuk SEMUA konektor (plug) canonical dari esdm_singgat_connector_status.
     * Backfill awal saat hydrate. Poller berikutnya hanya fold delta.
     */
    private function foldAllConnectorsFromStatus(): int
    {
        $rows = DB::connection('ev')->table('esdm_singgat_connector_status')
            ->select(['connector_esdm_id', 'status_konektor', 'status', 'last_seen_at'])
            ->get();

        $folded = 0;
        foreach ($rows as $row) {
            ChargingStationConnector::query()
                ->where('source_connector_id', $row->connector_esdm_id)
                ->toBase() // mass update tanpa auto updated_at (status ≠ master).
                ->update([
                    'status_konektor' => $row->status_konektor,
                    'status' => $row->status,
                    'status_updated_at' => $row->last_seen_at,
                ]);
            $folded++;
        }

        return $folded;
    }

    /**
     * Fold status untuk SEMUA charger box canonical dari connector_status.
     * Dipanggil hydrate (bukan hanya poll delta) supaya initial backfill lengkap.
     */
    private function foldAllChargerBoxesFromConnectorStatus(): int
    {
        // Ambil semua installation_esdm_id unik dari connector_status
        $instIds = DB::connection('ev')->table('esdm_singgat_connector_status')
            ->whereNotNull('installation_esdm_id')
            ->distinct()
            ->pluck('installation_esdm_id')
            ->all();

        if (empty($instIds)) {
            return 0;
        }

        $instData = array_map(fn ($id) => ['installation_esdm_id' => $id], $instIds);

        return $this->foldEsdmChargerStatuses($instData);
    }

    /**
     * Fold status real-time agregat ESDM ke canonical (dipanggil poller setelah
     * meng-update esdm_singgat_station_status). Update no-op bila stasiun belum
     * di-hydrate.
     *
     * @param  array{availability_level: string, available_count: int, charging_count: int, finishing_count: int, aggregated_at?: Carbon|null}  $aggregate
     */
    public function foldEsdmStatus(int $stationEsdmId, array $aggregate): void
    {
        ChargingStation::query()
            ->where('source', self::SOURCE_ESDM)
            ->where('source_station_id', $stationEsdmId)
            ->toBase() // mass update tanpa auto updated_at (status ≠ master).
            ->update([
                'availability_level' => $aggregate['availability_level'],
                'available_count' => $aggregate['available_count'],
                'charging_count' => $aggregate['charging_count'],
                'finishing_count' => $aggregate['finishing_count'],
                'status_updated_at' => $aggregate['aggregated_at'] ?? now(),
            ]);
    }

    /**
     * Fold status real-time per charger box (instalasi) ESDM ke canonical.
     *
     * Dipanggil poller setelah update konektor status. Untuk setiap charger box
     * canonical yang punya source_charger_id (ESDM instalasi id), hitung agregat
     * dari konektor-konektornya: berapa available/charging/finishing, lalu
     * turunkan availability_level. No-op bila charger box belum di-hydrate.
     *
     * @param  array<int, array{installation_esdm_id: int, aggregated_at?: Carbon|null}>  $installations  list of installation esdm IDs touched by this poll
     */
    public function foldEsdmChargerStatuses(array $installations, $aggregatedAt = null): int
    {
        $ids = array_column($installations, 'installation_esdm_id');
        $ids = array_values(array_filter($ids));
        if (empty($ids)) {
            return 0;
        }

        // Agregat status per instalasi dari esdm_singgat_connector_status.
        // Join via installation_esdm_id di connector_status.
        $rows = DB::connection('ev')->table('esdm_singgat_connector_status')
            ->selectRaw('
                installation_esdm_id,
                count(*) as total,
                sum(status_konektor = "available") as avail,
                sum(status_konektor = "charging") as charg,
                sum(status_konektor = "finishing") as finish
            ')
            ->whereIn('installation_esdm_id', $ids)
            ->groupBy('installation_esdm_id')
            ->get();

        $folded = 0;
        $ts = $aggregatedAt ?? now();
        foreach ($rows as $row) {
            $avail = (int) $row->avail;
            $charg = (int) $row->charg;
            $finish = (int) $row->finish;
            $total = (int) $row->total;
            $level = $this->computeAvailabilityLevel($avail, $finish, $charg, $total);

            ChargingStationCharger::query()
                ->where('source_charger_id', $row->installation_esdm_id)
                ->toBase() // mass update tanpa auto updated_at (status ≠ master).
                ->update([
                    'availability_level' => $level,
                    'available_count' => $avail,
                    'charging_count' => $charg,
                    'finishing_count' => $finish,
                    'status_updated_at' => $ts,
                ]);
            $folded++;
        }

        return $folded;
    }

    /**
     * availability_level dari count. Dipakai untuk station & charger box.
     *
     * Logika sederhana: ada slot bebas → hijau (available). Tidak ada → merah
     * (occupied), apapun status konektornya (charging/finishing). finishing_count
     * tetap disimpan utk info detail "segera bebas" tapi tidak mengubah warna.
     */
    private function computeAvailabilityLevel(int $avail, int $finish, int $charg, int $total): string
    {
        if ($total === 0) {
            return 'offline';
        }
        if ($avail > 0) {
            return 'available';
        }

        // Tidak ada slot bebas. Bedakan occupied (semua sibuk) vs offline (mati).
        $activeCount = $avail + $finish + $charg;
        if ($activeCount > 0) {
            return 'occupied';
        }

        return 'offline';
    }

    /**
     * Atribut availabilitas — ditulis TANPA menaikkan updated_at (status
     * real-time tidak boleh memicu delta sync klien).
     */
    private const AVAILABILITY_KEYS = [
        'availability_level',
        'available_count',
        'charging_count',
        'finishing_count',
        'status_updated_at',
    ];

    /** Perbandingan longgar nilai lama vs baru (float utk numerik, == utk array). */
    private function looseDifferent(mixed $current, mixed $new): bool
    {
        if (is_numeric($current) && is_numeric($new)) {
            return (float) $current !== (float) $new;
        }
        if (is_array($current) || is_array($new)) {
            return $current != $new;
        }

        return (string) $current !== (string) $new;
    }

    /**
     * Upsert stasiun kanonik change-aware: hanya menulis bila ada perubahan
     * atribut master; atribut availabilitas ditulis tanpa timestamps.
     *
     * @return array{0: ChargingStation, 1: bool} [station, masterChanged]
     */
    private function upsertCanonicalStation(string $source, int|string $sourceStationId, array $data): array
    {
        $station = ChargingStation::query()
            ->where('source', $source)
            ->where('source_station_id', $sourceStationId)
            ->first();

        if ($station === null) {
            return [
                ChargingStation::create(array_merge($data, [
                    'source' => $source,
                    'source_station_id' => $sourceStationId,
                ])),
                true,
            ];
        }

        $masterChanged = false;
        $availabilityDirty = false;

        foreach ($data as $key => $value) {
            if (in_array($key, self::AVAILABILITY_KEYS, true)) {
                if ($this->looseDifferent($station->{$key}, $value)) {
                    $availabilityDirty = true;
                    $station->{$key} = $value;
                }
                continue;
            }
            if ($this->looseDifferent($station->{$key}, $value)) {
                $masterChanged = true;
                $station->{$key} = $value;
            }
        }

        if ($masterChanged) {
            $station->save(); // updated_at ter-bump — memang perubahan master.
        } elseif ($availabilityDirty) {
            $station->timestamps = false;
            $station->save(); // status saja — jangan sentuh updated_at.
            $station->timestamps = true;
        }

        return [$station, $masterChanged];
    }

    // ─── Roll-up master data ────────────────────────────────────────────────

    /**
     * Bangun data kanonik untuk satu stasiun ESDM. Return null bila stasiun
     * tidak layak di-serving (mis. tanpa instalasi / tanpa nama).
     */
    private function buildStationData(
        EsdmSinggatSpkluStation $station,
        $statusMap,
        ScrapeDedupService $guesser,
        ?Provider $plnProvider
    ): ?array {
        $namaLokasi = trim((string) $station->nama_stasiun);
        if ($namaLokasi === '') {
            return null;
        }

        $installations = $station->installations;
        $primaryType = $this->resolvePrimaryTypeCharge($installations->pluck('jenis_pengisian_spklu')->all());
        $totalKonektor = (int) $installations->reduce(
            fn ($carry, $inst) => $carry + (int) $inst->connectors->count(),
            0
        );
        if ($totalKonektor === 0) {
            $totalKonektor = (int) $station->count_konektor;
        }

        [$providerId, $providerName] = $this->resolveProvider(
            $station->nama_badan_usaha, $guesser, $plnProvider, $namaLokasi
        );

        $raw = $station->raw_payload;
        $status = $statusMap[$station->esdm_id] ?? null;

        // Koordinat terbaik: manual_fixed > verified > drift_minor (OSM candidate)
        // > cleaned > raw. drift_major / not_found / province_mismatch tidak
        // dipakai karena butuh review manual — fallback ke koordinat cleaned.
        $best = app(GeoVerificationService::class)->getBestCoordinate($station);

        return [
            'nama_lokasi' => $namaLokasi,
            'alamat' => $station->alamat_spklu,
            'latitude' => $best['latitude'],
            'longitude' => $best['longitude'],
            'kode_provinsi' => $station->kode_provinsi,
            'provinsi' => self::PROVINCE_BY_BPS_CODE[(string) $station->kode_provinsi] ?? null,
            'kabupaten_kota' => null, // ESDM hanya menyediakan kode_kota, bukan nama
            'kategori_tol' => $raw['kategori_tol'] ?? ($this->resolveTollCategory($raw['kategori_tol'] ?? null, $namaLokasi, $station->alamat_spklu) === 'TOLL' ? 'TOL' : 'NON TOL'),
            'kategori_lokasi' => $raw['location_category'] ?? $raw['kategori_lokasi'] ?? null,
            'toll_category' => $this->resolveTollCategory($raw['kategori_tol'] ?? null, $namaLokasi, $station->alamat_spklu),
            'location_category' => $this->resolveLocationCategory($raw['location_category'] ?? $raw['kategori_lokasi'] ?? null, $namaLokasi, $station->alamat_spklu),
            'type_charge' => $primaryType,
            'watt' => $primaryType !== null ? (self::TYPE_CHARGE_WATT[$primaryType] ?? null) : null,
            'total_charger' => $installations->count(),
            'total_konektor' => $totalKonektor,
            'nama_badan_usaha' => $station->nama_badan_usaha,
            'provider_id' => $providerId,
            'provider_name' => $providerName,
            'harga_pengisian' => $this->joinDistinctRaw($installations->pluck('harga_pengisian_raw')->all()),
            'harga_layanan' => $this->joinDistinctRaw($installations->pluck('harga_layanan_raw')->all()),
            'estimasi' => $station->estimasi,
            'estimasi_menit' => $station->estimasi_menit,
            'jarak' => isset($raw['jarak']) ? (float) $raw['jarak'] : null,
            'availability_level' => $status?->availability_level ?? 'unknown',
            'available_count' => $status?->available_count ?? 0,
            'charging_count' => $status?->charging_count ?? 0,
            'finishing_count' => $status?->finishing_count ?? 0,
            'status_updated_at' => $status?->aggregated_at,
            'raw_payload' => $raw,
        ];
    }

    /** Replace seluruh child charger + konektor milik satu stasiun. */
    private function replaceChargers(ChargingStation $canonical, EsdmSinggatSpkluStation $station): int
    {
        $canonical->chargers()->delete();

        $inserted = 0;
        foreach ($station->installations as $inst) {
            $connectors = $inst->connectors;
            $firstConnector = $connectors->first();

            $charger = ChargingStationCharger::create([
                'station_id' => $canonical->id,
                'source_charger_id' => $inst->esdm_id,
                'chargerbox_id' => $inst->nomor_identitas,
                'type_charge' => $inst->jenis_pengisian_spklu,
                'nama' => $inst->merek_mesin,
                'watt' => isset(self::TYPE_CHARGE_WATT[$inst->jenis_pengisian_spklu])
                    ? self::TYPE_CHARGE_WATT[$inst->jenis_pengisian_spklu]
                    : null,
                'jumlah_charger' => 1,
                'jumlah_konektor' => $connectors->count(),
                'icon' => null,
                'gambar' => $firstConnector?->img_path,
                'harga_pengisian' => $inst->harga_pengisian_raw,
                'harga_layanan' => $inst->harga_layanan_raw,
            ]);

            // Child connectors (plug individual) — master data + img_path.
            // Status real-time di-fold terpisah oleh poller.
            foreach ($connectors as $kon) {
                ChargingStationConnector::create([
                    'charger_id' => $charger->id,
                    'source_connector_id' => $kon->esdm_id,
                    'nama_konektor' => $kon->nama_konektor,
                    'img_path' => $kon->img_path,
                ]);
            }
            $inserted++;
        }

        return $inserted;
    }

    /**
     * Bangun snapshot desired charger+connector ESDM (tanpa menulis DB).
     *
     * @return array<int, array{charger: array<string, mixed>, connectors: list<array<string, mixed>>}>
     */
    private function buildDesiredEsdmChargers(EsdmSinggatSpkluStation $station): array
    {
        $desired = [];
        foreach ($station->installations as $inst) {
            $connectors = $inst->connectors;
            $firstConnector = $connectors->first();

            $desired[] = [
                'charger' => [
                    'source_charger_id' => $inst->esdm_id,
                    'chargerbox_id' => $inst->nomor_identitas,
                    'type_charge' => $inst->jenis_pengisian_spklu,
                    'nama' => $inst->merek_mesin,
                    'watt' => isset(self::TYPE_CHARGE_WATT[$inst->jenis_pengisian_spklu])
                        ? self::TYPE_CHARGE_WATT[$inst->jenis_pengisian_spklu]
                        : null,
                    'jumlah_charger' => 1,
                    'jumlah_konektor' => $connectors->count(),
                    'icon' => null,
                    'gambar' => $firstConnector?->img_path,
                    'harga_pengisian' => $inst->harga_pengisian_raw,
                    'harga_layanan' => $inst->harga_layanan_raw,
                ],
                'connectors' => $connectors->map(fn ($kon) => [
                    'source_connector_id' => $kon->esdm_id,
                    'nama_konektor' => $kon->nama_konektor,
                    'img_path' => $kon->img_path,
                ])->all(),
            ];
        }

        return $desired;
    }

    /** Snapshot existing charger+connector ESDM utk perbandingan. */
    private function buildExistingEsdmChargers(ChargingStation $canonical): array
    {
        return $canonical->chargers()->with('connectors')->get()->map(fn ($charger) => [
            'charger' => [
                'source_charger_id' => $charger->source_charger_id,
                'chargerbox_id' => $charger->chargerbox_id,
                'type_charge' => $charger->type_charge,
                'nama' => $charger->nama,
                'watt' => $charger->watt,
                'jumlah_charger' => $charger->jumlah_charger,
                'jumlah_konektor' => $charger->jumlah_konektor,
                'icon' => $charger->icon,
                'gambar' => $charger->gambar,
                'harga_pengisian' => $charger->harga_pengisian,
                'harga_layanan' => $charger->harga_layanan,
            ],
            'connectors' => $charger->connectors->map(fn ($kon) => [
                'source_connector_id' => $kon->source_connector_id,
                'nama_konektor' => $kon->nama_konektor,
                'img_path' => $kon->img_path,
            ])->all(),
        ])->all();
    }

    /**
     * Replace child charger+konektor ESDM HANYA bila berbeda — mengembalikan
     * [jumlahCharger, berubah]. Identik → nol penulisan (idempoten).
     *
     * @return array{0: int, 1: bool}
     */
    private function replaceChargersIfChanged(ChargingStation $canonical, EsdmSinggatSpkluStation $station): array
    {
        $desired = $this->buildDesiredEsdmChargers($station);

        if ($this->buildExistingEsdmChargers($canonical) == $desired) {
            return [count($desired), false];
        }

        $canonical->chargers()->delete();

        $inserted = 0;
        foreach ($desired as $row) {
            $charger = ChargingStationCharger::create(
                array_merge($row['charger'], ['station_id' => $canonical->id])
            );
            foreach ($row['connectors'] as $conn) {
                ChargingStationConnector::create(
                    array_merge($conn, ['charger_id' => $charger->id])
                );
            }
            $inserted++;
        }

        return [$inserted, true];
    }

    // ─── PLN (pln_charger_locations) ────────────────────────────────────────

    /**
     * Bangun data kanonik untuk satu lokasi PLN. Return null bila tidak layak
     * di-serving (mis. tanpa nama).
     */
    private function buildPlnStationData(PlnChargerLocation $location): ?array
    {
        $namaLokasi = trim((string) $location->name);
        if ($namaLokasi === '') {
            return null;
        }

        $details = $location->plnChargerLocationDetails;

        $types = $details->map(fn ($detail) => $this->canonicalChargingType($detail))
            ->filter()
            ->values()
            ->all();
        $primaryType = $this->resolvePrimaryTypeCharge($types);

        $totalKonektor = (int) $details->reduce(
            fn ($carry, $detail) => $carry + (int) $detail->count_connector_charger,
            0
        );

        return [
            'nama_lokasi' => $namaLokasi,
            'alamat' => $location->address,
            'latitude' => $location->latitude,
            'longitude' => $location->longitude,
            'kode_provinsi' => null, // PLN memakai FK province_id, bukan kode BPS
            'provinsi' => $location->province?->name,
            'kabupaten_kota' => null, // PLN tidak menyediakan kabupaten/kota
            'kategori_tol' => $location->kategori_tol,
            'kategori_lokasi' => $location->locationCategory?->name,
            'toll_category' => $this->resolveTollCategory($location->kategori_tol, $namaLokasi, $location->address),
            'location_category' => $this->resolveLocationCategory($location->locationCategory?->name, $namaLokasi, $location->address),
            'type_charge' => $primaryType,
            'watt' => $primaryType !== null ? (self::TYPE_CHARGE_WATT[$primaryType] ?? null) : null,
            'total_charger' => $details->count(),
            'total_konektor' => $totalKonektor,
            'provider_id' => $location->provider_id,
            'provider_name' => $location->provider?->name,
            'availability_level' => 'unknown',
            'available_count' => 0,
            'charging_count' => 0,
            'finishing_count' => 0,
            'status_updated_at' => null,
            'raw_payload' => $this->buildPlnRawPayload($location),
        ];
    }

    /**
     * Sinkronkan child charger stasiun PLN secara change-aware: bandingkan
     * baris desired vs existing by source_charger_id; hapus yang hilang,
     * update yang berubah, insert yang baru.
     *
     * @return array{0: int, 1: bool} [jumlahCharger, berubah]
     */
    private function syncPlnChargers(ChargingStation $canonical, PlnChargerLocation $location): array
    {
        $desired = [];
        foreach ($location->plnChargerLocationDetails as $detail) {
            $typeCharge = $this->canonicalChargingType($detail);
            $desired[$detail->id] = [
                'source_charger_id' => $detail->id,
                'chargerbox_id' => $detail->chargebox_id,
                'type_charge' => $typeCharge,
                'nama' => $detail->merkCharger?->name ?? $detail->chargebox_name,
                'watt' => $typeCharge !== null ? (self::TYPE_CHARGE_WATT[$typeCharge] ?? null) : null,
                'jumlah_charger' => 1,
                'jumlah_konektor' => (int) $detail->count_connector_charger,
                'icon' => null,
                'gambar' => null,
            ];
        }

        $existing = $canonical->chargers()->get()->keyBy('source_charger_id');
        $changed = false;

        $deleted = $canonical->chargers()
            ->whereNotIn('source_charger_id', array_keys($desired))
            ->delete();
        if ($deleted > 0) {
            $changed = true;
        }

        $written = 0;
        foreach ($desired as $sourceId => $row) {
            $current = $existing->get($sourceId);
            if ($current === null) {
                ChargingStationCharger::create(array_merge($row, ['station_id' => $canonical->id]));
                $changed = true;
                $written++;
                continue;
            }
            $dirty = false;
            foreach ($row as $key => $value) {
                if ($this->looseDifferent($current->{$key}, $value)) {
                    $current->{$key} = $value;
                    $dirty = true;
                }
            }
            if ($dirty) {
                $current->save();
                $changed = true;
            }
            $written++;
        }

        return [$written, $changed];
    }

    /**
     * Map label charging_type PLN (uppercase) ke label type_charge canonical.
     * Daya chargebox (kw) diutamakan — kategori PLN tidak konsisten (7 kW bisa
     * berlabel ULTRA FAST, 100 kW bisa berlabel MEDIUM/STANDARD). Fallback ke
     * label hanya bila daya tidak tersedia/tidak valid.
     */
    private function canonicalChargingType(PlnChargerLocationDetail $detail): ?string
    {
        $fromPower = $this->deriveCanonicalTypeFromPower($detail->power);
        if ($fromPower !== null) {
            return $fromPower;
        }

        $name = strtoupper(trim((string) $detail->chargingType?->name));
        if ($name === '') {
            return null;
        }

        return self::CHARGING_TYPE_TO_CANONICAL[$name] ?? null;
    }

    /**
     * Derive tier kecepatan dari daya chargebox (kW):
     * ≤11 kW → Slow, ≤22 → Medium, ≤50 → Fast, >50 → Ultra Fast.
     * Nilai non-numerik / ≤0 dianggap tidak valid → null (fallback ke label).
     */
    private function deriveCanonicalTypeFromPower(mixed $power): ?string
    {
        $kw = is_numeric($power) ? (float) $power : null;

        if ($kw === null || $kw <= 0) {
            return null;
        }

        if ($kw <= 11) {
            return 'Slow Charging';
        }
        if ($kw <= 22) {
            return 'Medium Charging';
        }
        if ($kw <= 50) {
            return 'Fast Charging';
        }

        return 'Ultra Fast Charging';
    }

    /** Snapshot raw data PLN (audit) — termasuk power numerik aktual per charger. */
    private function buildPlnRawPayload(PlnChargerLocation $location): array
    {
        return [
            'pln_charger_location_id' => $location->id,
            'name' => $location->name,
            'address' => $location->address,
            'provider_id' => $location->provider_id,
            'provider_name' => $location->provider?->name,
            'province_name' => $location->province?->name,
            'owner_machine' => $location->owner_machine,
            'kategori_tol' => $location->kategori_tol,
            'details' => $location->plnChargerLocationDetails->map(fn ($detail) => [
                'chargebox_id' => $detail->chargebox_id,
                'chargebox_name' => $detail->chargebox_name,
                'power' => $detail->power,
                'is_active_charger' => $detail->is_active_charger,
                'count_connector_charger' => $detail->count_connector_charger,
                'operation_date' => $detail->operation_date,
                'year' => $detail->year,
                'charging_type' => $detail->chargingType?->name,
                'charger_category' => $detail->chargerCategory?->name,
                'merk_charger' => $detail->merkCharger?->name,
            ])->all(),
        ];
    }

    /**
     * Pilih type_charge "primary" untuk stasiun: tier tertinggi yang tersedia
     * lintas instalasi (agar filter kecepatan & pin map tetap relevan).
     */
    private function resolvePrimaryTypeCharge(array $types): ?string
    {
        $best = null;
        $bestTier = 0;
        foreach (array_unique(array_filter($types)) as $type) {
            $tier = self::TYPE_CHARGE_TIER[$type] ?? 0;
            if ($tier > $bestTier) {
                $bestTier = $tier;
                $best = $type;
            }
        }

        return $best;
    }

    /**
     * Resolve provider: guess via ScrapeDedupService dengan cek GABUNGAN
     * nama_badan_usaha + nama_lokasi. Banyak stasiun ESDM punya nama legal
     * panjang di badan_usaha tapi nama singkat provider di nama_lokasi
     * (mis. badan="...", nama_lokasi="Astra Otopower" → provider "Astra").
     *
     * Strategi: guess dgn badan_usaha; bila no-match, coba guess dgn
     * gabungan "badan_usaha + nama_lokasi" utk catch provider di nama_lokasi.
     * Alias khusus ESDM: nama legal PLN tidak memuat token "PLN".
     *
     * @return array{0: string|null, 1: string|null} [provider_id, provider_name]
     */
    private function resolveProvider(?string $namaBadanUsaha, ScrapeDedupService $guesser, ?Provider $plnProvider, ?string $namaLokasi = null): array
    {
        $name = trim((string) $namaBadanUsaha);
        if ($name === '') {
            return [null, null];
        }

        if ($plnProvider !== null && (
            str_contains(strtoupper($name), 'LISTRIK NEGARA')
            || str_contains(strtoupper($name), ' PLN')
            || str_starts_with(strtoupper($name), 'PLN')
        )) {
            return [$plnProvider->id, $plnProvider->name];
        }

        // Coba badan_usaha dulu
        $provider = $guesser->guessProvider($name);
        if ($provider !== null) {
            return [$provider->id, $provider->name];
        }

        // No-match di badan_usaha → cek nama_lokasi secara terpisah.
        // Penting utk provider generik (Astra, Mall, Hotel) yg match-nya
        // starts-with: nama_lokasi "Astra Otopower" match "Astra", tapi
        // gabungan "ARDENDI JAYA SENTOSA Astra Otopower" tidak (tidak mulai dgn Astra).
        if ($namaLokasi !== null && trim($namaLokasi) !== '') {
            $provider = $guesser->guessProvider(trim($namaLokasi));
            if ($provider !== null) {
                return [$provider->id, $provider->name];
            }
            // Terakhir: coba gabungan utk non-generik (token match di mana saja).
            $combined = $name.' '.$namaLokasi;
            $provider = $guesser->guessProvider($combined);
            if ($provider !== null) {
                return [$provider->id, $provider->name];
            }
        }

        return [null, null];
    }

    /** Gabungkan nilai string unik (non-null/non-kosong) dengan " / ". */
    private function joinDistinctRaw(array $values): ?string
    {
        $clean = array_values(array_unique(array_filter(
            $values,
            fn ($v) => $v !== null && trim((string) $v) !== ''
        )));
        if ($clean === []) {
            return null;
        }

        return implode(' / ', $clean);
    }

    private function resolveTollCategory(?string $rawToll, string $namaLokasi = '', ?string $alamat = null): ?string
    {
        if ($rawToll !== null && trim($rawToll) !== '') {
            $upper = strtoupper(trim($rawToll));
            if ($upper === 'TOL' || str_contains($upper, 'TOL')) {
                return 'TOLL';
            }
            if ($upper === 'NON TOL' || str_contains($upper, 'NON')) {
                return 'NON_TOLL';
            }
        }
        $combined = strtoupper($namaLokasi . ' ' . ($alamat ?? ''));
        if (str_contains($combined, 'REST AREA') || str_contains($combined, ' TOL')) {
            return 'TOLL';
        }
        return 'NON_TOLL';
    }

    private function resolveLocationCategory(?string $rawCategory, string $namaLokasi = '', ?string $alamat = null): ?string
    {
        $raw = strtoupper(trim((string) $rawCategory));
        if (str_contains($raw, 'REST AREA')) return 'REST_AREA';
        if (str_contains($raw, 'MALL') || str_contains($raw, 'SUPERMARKET') || str_contains($raw, 'PASAR')) return 'SHOPPING_MALL';
        if (str_contains($raw, 'HOTEL')) return 'HOTEL';
        if (str_contains($raw, 'PLN')) return 'PLN_OFFICE';
        if (str_contains($raw, 'SPBU') || str_contains($raw, 'PERTAMINA')) return 'GAS_STATION';
        if (str_contains($raw, 'BISNIS') || str_contains($raw, 'RUKO') || str_contains($raw, 'SWASTA') || str_contains($raw, 'SHOWROOM')) return 'COMMERCIAL';
        if (str_contains($raw, 'PEMERINTAH') || str_contains($raw, 'BANDARA') || str_contains($raw, 'STASIUN') || str_contains($raw, 'PELABUHAN') || str_contains($raw, 'TERMINAL') || str_contains($raw, 'HOSPITAL') || str_contains($raw, 'RUMAH SAKIT')) return 'PUBLIC_FACILITY';
        if (str_contains($raw, 'APARTEMEN') || str_contains($raw, 'PERUMAHAN')) return 'RESIDENTIAL';

        $combined = strtoupper($namaLokasi . ' ' . ($alamat ?? ''));
        if (str_contains($combined, 'REST AREA')) return 'REST_AREA';
        if (str_contains($combined, 'MALL') || str_contains($combined, 'PLAZA') || str_contains($combined, 'CITY')) return 'SHOPPING_MALL';
        if (str_contains($combined, 'HOTEL') || str_contains($combined, 'RESORT')) return 'HOTEL';
        if (str_contains($combined, 'PLN')) return 'PLN_OFFICE';
        if (str_contains($combined, 'SPBU') || str_contains($combined, 'PERTAMINA') || str_contains($combined, 'SHELL')) return 'GAS_STATION';

        return null;
    }
}
