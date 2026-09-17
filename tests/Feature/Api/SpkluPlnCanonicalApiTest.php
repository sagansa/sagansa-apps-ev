<?php

namespace Tests\Feature\Api;

use App\Models\ChargerCategory;
use App\Models\ChargingStation;
use App\Models\ChargingStationCharger;
use App\Models\ChargingType;
use App\Models\MerkCharger;
use App\Models\PlnChargerLocation;
use App\Models\PlnChargerLocationDetail;
use App\Models\Provider;
use App\Models\Province;
use App\Services\CanonicalStationHydrateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpkluPlnCanonicalApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['spklu.serving_source' => CanonicalStationHydrateService::SOURCE_PLN]);
    }

    public function test_index_serves_pln_stations_with_unknown_availability_and_contract_shape(): void
    {
        $this->seedPln();

        $response = $this->getJson('/api/v1/spklu');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id', 'provinsi', 'kabupaten_kota', 'nama_lokasi', 'alamat',
                        'latitude', 'longitude', 'keterangan', 'status', 'type_charge',
                        'watt', 'total_charger', 'total_konektor', 'provider_id',
                        'provider_name', 'provider_logo', 'charger_boxes',
                    ],
                ],
                'links', 'meta',
            ]);

        $data = $response->json('data');
        $this->assertCount(1, $data);

        $first = $data[0];
        $this->assertSame('SPKLU PLN SENTUL', $first['nama_lokasi']);
        $this->assertSame('Jawa Barat', $first['provinsi']);
        $this->assertSame('Fast Charging', $first['type_charge']);
        $this->assertSame('25–50 kW DC', $first['watt']);
        $this->assertSame(1, $first['total_charger']);
        $this->assertSame(2, $first['total_konektor']);
        $this->assertSame('PLN Mobile', $first['provider_name']);
        $this->assertSame('unknown', $first['availability_level']);
        $this->assertSame(0, $first['available_count']);
        $this->assertNull($first['status_updated_at']);

        $this->assertCount(1, $first['charger_boxes']);
        $box = $first['charger_boxes'][0];
        $this->assertSame('CB-0001', $box['chargerbox_id']);
        $this->assertSame('ABB', $box['nama_chargerbox']);
        $this->assertSame('Fast Charging', $box['type_charge']);
        $this->assertSame('25–50 kW DC', $box['watt']);
        $this->assertSame(2, (int) $box['jumlah_konektor']);
        $this->assertSame('unknown', $box['availability_level']);
        $this->assertSame([], $box['connectors']);
    }

    public function test_index_maps_pln_charging_types_to_canonical_labels(): void
    {
        $this->seedPln();

        // Tambah stasiun slow (STANDARD CHARGING) untuk cek mapping ke "Slow Charging".
        $location = $this->createLocation('SPKLU PLN BANDUNG', 'Jawa Barat', -6.90, 107.60);
        $this->addDetail($location, [
            'chargebox_id' => 'CB-0002',
            'chargebox_name' => 'Wallbox',
            'power' => '7',
            'charging_type' => 'STANDARD CHARGING',
            'merk' => 'Wallbox',
        ]);

        app(CanonicalStationHydrateService::class)->hydrateFromPln();

        $response = $this->getJson('/api/v1/spklu');
        $response->assertOk();

        $types = collect($response->json('data'))->pluck('type_charge')->sort()->values()->all();
        $this->assertSame(['Fast Charging', 'Slow Charging'], $types);

        $slow = collect($response->json('data'))->firstWhere('nama_lokasi', 'SPKLU PLN BANDUNG');
        $this->assertSame('Slow Charging', $slow['type_charge']);
        $this->assertSame('≤7 kW AC', $slow['watt']);
    }

    public function test_index_filters_by_provinsi_and_speed_id(): void
    {
        $this->seedPln();

        $response = $this->getJson('/api/v1/spklu?provinsi=Jawa%20Barat&type_charge=fast');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('SPKLU PLN SENTUL', $response->json('data.0.nama_lokasi'));

        $response = $this->getJson('/api/v1/spklu?provinsi=DKI%20Jakarta');
        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_show_looks_up_pln_station_by_canonical_id(): void
    {
        $this->seedPln();

        $station = ChargingStation::where('source', CanonicalStationHydrateService::SOURCE_PLN)->firstOrFail();

        $response = $this->getJson('/api/v1/spklu/'.$station->id);

        $response->assertOk()
            ->assertJsonPath('data.id', (int) $station->id)
            ->assertJsonPath('data.availability_level', 'unknown')
            ->assertJsonCount(1, 'data.charger_boxes');
    }

    public function test_meta_filters_sourced_from_pln_only(): void
    {
        $this->seedPln();

        $response = $this->getJson('/api/v1/meta/filters');

        $response->assertOk()
            ->assertJsonPath('data.provinces', ['Jawa Barat'])
            ->assertJsonPath('data.charge_types', ['Fast Charging']);
    }

    public function test_meta_filters_include_data_version(): void
    {
        $this->seedPln();

        $response = $this->getJson('/api/v1/meta/filters');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertArrayHasKey('data_version', $data);
        $this->assertNotEmpty($data['data_version']);
    }

    public function test_esdm_rows_are_not_served_when_serving_pln(): void
    {
        $this->seedPln();

        // Baris ESDM tetap ada di tabel kanonik, tapi tidak disajikan.
        ChargingStation::create([
            'source' => CanonicalStationHydrateService::SOURCE_ESDM,
            'source_station_id' => 999,
            'nama_lokasi' => 'SPKLU ESDM LAMA',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'provinsi' => 'DKI Jakarta',
            'type_charge' => 'Fast Charging',
        ]);

        $response = $this->getJson('/api/v1/spklu');
        $response->assertOk();
        $this->assertNotContains('SPKLU ESDM LAMA', collect($response->json('data'))->pluck('nama_lokasi'));
    }

    public function test_hydrate_pln_is_idempotent_when_master_unchanged(): void
    {
        $this->seedPln();

        $station = ChargingStation::where('source', CanonicalStationHydrateService::SOURCE_PLN)->firstOrFail();
        $chargerId = $station->chargers()->firstOrFail()->id;

        // Bekukan updated_at via query builder (bypass auto-timestamps).
        ChargingStation::query()->whereKey($station->id)->toBase()
            ->update(['updated_at' => '2026-01-01 00:00:00']);

        app(CanonicalStationHydrateService::class)->hydrateFromPln();

        $fresh = $station->fresh();
        // Tidak ada perubahan master → updated_at TIDAK boleh ter-bump.
        $this->assertSame(
            '2026-01-01 00:00:00',
            $fresh->updated_at->format('Y-m-d H:i:s')
        );
        // Charger tidak di-recreate → id stabil.
        $this->assertSame($chargerId, $fresh->chargers()->firstOrFail()->id);
    }

    public function test_hydrate_pln_touches_station_when_master_changes(): void
    {
        $this->seedPln();

        $station = ChargingStation::where('source', CanonicalStationHydrateService::SOURCE_PLN)->firstOrFail();
        ChargingStation::query()->whereKey($station->id)->toBase()
            ->update(['updated_at' => '2026-01-01 00:00:00']);

        // Ubah master: tambah satu charger detail baru.
        $location = \App\Models\PlnChargerLocation::firstOrFail();
        $this->addDetail($location, [
            'chargebox_id' => 'CB-9999',
            'chargebox_name' => 'Wallbox Baru',
            'power' => '7',
            'charging_type' => 'STANDARD CHARGING',
            'merk' => 'Wallbox',
        ]);

        app(CanonicalStationHydrateService::class)->hydrateFromPln();

        $fresh = $station->fresh();
        $this->assertSame(2, $fresh->chargers()->count());
        $this->assertGreaterThan(
            '2026-01-01 00:00:00',
            $fresh->updated_at->format('Y-m-d H:i:s')
        );
    }

    // ─── Setup ──────────────────────────────────────────────────────────────

    private function seedPln(): void
    {
        $this->seedLookups();

        $location = $this->createLocation('SPKLU PLN SENTUL', 'Jawa Barat', -6.56, 106.86);
        $this->addDetail($location, [
            'chargebox_id' => 'CB-0001',
            'chargebox_name' => 'ABB DC',
            'power' => '50',
            'charging_type' => 'FAST CHARGING',
            'merk' => 'ABB',
            'connectors' => 2,
        ]);

        app(CanonicalStationHydrateService::class)->hydrateFromPln();
    }

    private function seedLookups(): void
    {
        Province::create(['name' => 'Jawa Barat']);
        Province::create(['name' => 'DKI Jakarta']);

        Provider::create(['name' => 'PLN Mobile', 'status' => 1]);

        foreach (['FAST CHARGING', 'MEDIUM CHARGING', 'STANDARD CHARGING', 'ULTRA FAST CHARGING'] as $name) {
            ChargingType::create(['name' => $name]);
        }

        ChargerCategory::create(['name' => 'DC']);
    }

    private function createLocation(string $name, string $province, float $lat, float $lng): PlnChargerLocation
    {
        return PlnChargerLocation::create([
            'name' => $name,
            'address' => 'Jl. Test '.$name,
            'provider_id' => Provider::where('name', 'PLN Mobile')->firstOrFail()->id,
            'owner_machine' => 'PLN',
            'latitude' => $lat,
            'longitude' => $lng,
            'province_id' => Province::where('name', $province)->firstOrFail()->id,
        ]);
    }

    private function addDetail(PlnChargerLocation $location, array $overrides = []): PlnChargerLocationDetail
    {
        $chargingType = ChargingType::where('name', $overrides['charging_type'] ?? 'FAST CHARGING')->first();

        return PlnChargerLocationDetail::create([
            'pln_charger_location_id' => $location->id,
            'chargebox_id' => $overrides['chargebox_id'] ?? 'CB-X',
            'chargebox_name' => $overrides['chargebox_name'] ?? null,
            'power' => $overrides['power'] ?? '50',
            'is_active_charger' => 'Y',
            'count_connector_charger' => $overrides['connectors'] ?? 1,
            'operation_date' => '2025-01-01',
            'year' => 2025,
            'charging_type_id' => $chargingType?->id,
            'merk_charger_id' => MerkCharger::create(['name' => $overrides['merk'] ?? 'ABB'])->id,
            'category_charger_id' => ChargerCategory::where('name', 'DC')->firstOrFail()->id,
        ]);
    }
}
