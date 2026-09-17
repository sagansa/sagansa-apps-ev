<?php

namespace Tests\Feature\Services;

use App\Models\ChargingStation;
use App\Models\EsdmSinggatSpkluConnector;
use App\Models\EsdmSinggatSpkluInstallation;
use App\Models\EsdmSinggatSpkluStation;
use App\Models\EsdmSinggatStationStatus;
use App\Models\Provider;
use App\Services\CanonicalStationHydrateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CanonicalStationHydrateTest extends TestCase
{
    use RefreshDatabase;

    public function test_hydrate_rolls_up_esdm_station_into_canonical(): void
    {
        Provider::create(['name' => 'PLN Mobile', 'status' => 1]);

        $station = $this->makeEsdmStation(
            esdmId: 101,
            name: 'SPKLU Test PLN',
            province: '32',
            operator: 'PERUSAHAAN PERSEROAN (PERSERO) PT. PERUSAHAAN LISTRIK NEGARA',
        );
        $this->addInstallation($station, 'Fast Charging', 'CCS2', 'Chargepoint', '2500', '1000');
        $this->addInstallation($station, 'Slow Charging', 'Type 2', 'ABB', '1500', '0');

        EsdmSinggatStationStatus::create([
            'station_esdm_id' => 101,
            'station_id' => $station->id,
            'total_connectors' => 2,
            'available_count' => 1,
            'charging_count' => 1,
            'finishing_count' => 0,
            'unavailable_count' => 0,
            'unknown_count' => 0,
            'availability_level' => 'available',
            'aggregated_at' => now(),
        ]);

        $service = app(CanonicalStationHydrateService::class);
        $stats = $service->hydrateFromEsdm();

        $this->assertSame(1, $stats['created']);
        $this->assertSame(1, $stats['processed']);

        $canonical = ChargingStation::where('source', 'esdm')->where('source_station_id', 101)->firstOrFail();

        // Master data roll-up
        $this->assertSame('SPKLU Test PLN', $canonical->nama_lokasi);
        $this->assertSame('Jawa Barat', $canonical->provinsi);
        $this->assertSame('32', $canonical->kode_provinsi);
        $this->assertNull($canonical->kabupaten_kota);
        $this->assertSame('Fast Charging', $canonical->type_charge); // tier tertinggi
        $this->assertSame('50 kW', $canonical->watt);
        $this->assertSame(2, $canonical->total_charger);
        $this->assertSame(2, $canonical->total_konektor);

        // Provider: alias khusus ESDM untuk nama legal PLN
        $this->assertNotNull($canonical->provider_id);
        $this->assertSame('PLN Mobile', $canonical->provider_name);

        // Tarif roll-up distinct
        $this->assertSame('2500 / 1500', $canonical->harga_pengisian);
        $this->assertSame('1000 / 0', $canonical->harga_layanan);

        // Status real-time folded saat hydrate
        $this->assertSame('available', $canonical->availability_level);
        $this->assertSame(1, $canonical->available_count);
        $this->assertSame(1, $canonical->charging_count);

        // Child chargers: 1 per instalasi, nama = merek_mesin
        $this->assertSame(2, $canonical->chargers()->count());
        $fast = $canonical->chargers()->where('type_charge', 'Fast Charging')->firstOrFail();
        $this->assertSame('Chargepoint', $fast->nama);
        $this->assertSame('50 kW', $fast->watt);
        $this->assertSame('2500', $fast->harga_pengisian);
        $this->assertSame(1, $fast->jumlah_konektor);
    }

    public function test_hydrate_is_idempotent(): void
    {
        Provider::create(['name' => 'PLN Mobile', 'status' => 1]);
        Provider::create(['name' => 'Shell', 'status' => 1]);
        $station = $this->makeEsdmStation(202, 'SPKLU Idempoten', '31', 'PT SHELL INDONESIA');
        $this->addInstallation($station, 'Medium Charging', 'Type 2', 'ABB', '2466', '0');

        $service = app(CanonicalStationHydrateService::class);
        $service->hydrateFromEsdm();

        $stats = $service->hydrateFromEsdm();

        // Change-aware: re-run tanpa perubahan master = nol penulisan
        // (updated_at stabil — prasyarat marker delta sync).
        $this->assertSame(0, $stats['updated']);
        $this->assertSame(1, $stats['unchanged']);
        $this->assertSame(0, $stats['created']);
        $this->assertDatabaseCount('charging_stations', 1);
        $this->assertDatabaseCount('charging_station_chargers', 1);

        // Provider dari guess ScrapeDedupService
        $canonical = ChargingStation::where('source_station_id', 202)->firstOrFail();
        $this->assertSame('Shell', $canonical->provider_name);
    }

    public function test_hydrate_uses_raw_operator_name_as_provider_fallback(): void
    {
        Provider::create(['name' => 'PLN Mobile', 'status' => 1]);
        $station = $this->makeEsdmStation(303, 'SPKLU Unknown Ops', '51', 'ARDENDI JAYA SENTOSA');
        $this->addInstallation($station, 'Slow Charging', 'Type 2', 'ABB', '2000', '0');

        app(CanonicalStationHydrateService::class)->hydrateFromEsdm();

        $canonical = ChargingStation::where('source_station_id', 303)->firstOrFail();
        $this->assertNull($canonical->provider_id);
        $this->assertSame('ARDENDI JAYA SENTOSA', $canonical->provider_name);
        $this->assertSame('Bali', $canonical->provinsi);
    }

    public function test_fold_esdm_status_updates_canonical_real_time_columns(): void
    {
        Provider::create(['name' => 'PLN Mobile', 'status' => 1]);
        $station = $this->makeEsdmStation(404, 'SPKLU Status', '73', 'PT UTOMO CHARGEPLUS INDONESIA');
        $this->addInstallation($station, 'Fast Charging', 'CCS2', 'Chargecore', '2466', '0');

        $service = app(CanonicalStationHydrateService::class);
        $service->hydrateFromEsdm();
        $canonical = ChargingStation::where('source_station_id', 404)->firstOrFail();
        $this->assertSame('unknown', $canonical->availability_level);

        $service->foldEsdmStatus(404, [
            'availability_level' => 'occupied',
            'available_count' => 0,
            'charging_count' => 1,
            'finishing_count' => 0,
            'aggregated_at' => now(),
        ]);

        $canonical->refresh();
        $this->assertSame('occupied', $canonical->availability_level);
        $this->assertSame(0, $canonical->available_count);
        $this->assertSame(1, $canonical->charging_count);
        $this->assertNotNull($canonical->status_updated_at);
    }

    public function test_fold_esdm_status_noop_for_unhydrated_station(): void
    {
        $service = app(CanonicalStationHydrateService::class);

        $service->foldEsdmStatus(99999, [
            'availability_level' => 'available',
            'available_count' => 1,
            'charging_count' => 0,
            'finishing_count' => 0,
        ]);

        $this->assertDatabaseCount('charging_stations', 0);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function makeEsdmStation(
        int $esdmId,
        string $name,
        string $province,
        ?string $operator = null,
        float $lat = -6.200000,
        float $lng = 106.800000
    ): EsdmSinggatSpkluStation {
        return EsdmSinggatSpkluStation::create([
            'esdm_id' => $esdmId,
            'nama_stasiun' => $name,
            'alamat_spklu' => 'Jl. Test No. 1',
            'kode_provinsi' => $province,
            'kode_kota' => '1101',
            'nama_badan_usaha' => $operator,
            'latitude' => $lat,
            'longitude' => $lng,
            'geo_status' => 'ok',
            'count_konektor' => 0,
            'estimasi' => 68.611,
            'estimasi_menit' => 4117,
            'raw_payload' => ['jarak' => '1372.22'],
        ]);
    }

    private function addInstallation(
        EsdmSinggatSpkluStation $station,
        string $typeCharge,
        string $connectorName,
        string $machineBrand,
        string $hargaPengisian,
        string $hargaLayanan
    ): void {
        $installation = EsdmSinggatSpkluInstallation::create([
            'esdm_id' => random_int(1000, 9999),
            'station_id' => $station->id,
            'station_esdm_id' => $station->esdm_id,
            'nomor_identitas' => 'REG-'.$station->esdm_id,
            'merek_mesin' => $machineBrand,
            'jenis_pengisian_spklu' => $typeCharge,
            'harga_pengisian_raw' => $hargaPengisian,
            'harga_layanan_raw' => $hargaLayanan,
        ]);

        EsdmSinggatSpkluConnector::create([
            'esdm_id' => random_int(10000, 99999),
            'installation_id' => $installation->id,
            'installation_esdm_id' => $installation->esdm_id,
            'nama_konektor' => $connectorName,
            'status' => 'Beroperasi',
            'status_konektor' => 'available',
            'img_path' => 'storage/esdm/konektor_unique/'.str_replace(' ', '', $connectorName).'.png',
        ]);
    }
}
