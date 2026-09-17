<?php

namespace Tests\Feature\Api;

use App\Models\ChargingStation;
use App\Models\EsdmSinggatSpkluConnector;
use App\Models\EsdmSinggatSpkluInstallation;
use App\Models\EsdmSinggatSpkluStation;
use App\Models\Provider;
use App\Services\CanonicalStationHydrateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpkluCanonicalApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test ini memvalidasi serving dari source ESDM (hydrateFromEsdm). Karena
     * default serving_source sudah 'pln', pin ke ESDM agar pengujian tidak
     * bergantung pada nilai env.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['spklu.serving_source' => CanonicalStationHydrateService::SOURCE_ESDM]);
    }

    public function test_index_serves_canonical_stations_with_mobile_contract_shape(): void
    {
        $this->seedCanonical();

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

        $json = $response->json();
        $this->assertCount(2, $json['data']);

        $first = $json['data'][0];
        $this->assertSame('Fast Charging', $first['type_charge']); // verbatim ESDM
        $this->assertSame('SPKLU PLN JAKARTA', $first['nama_lokasi']);
        $this->assertSame('PLN Mobile', $first['provider_name']);
        $this->assertCount(1, $first['charger_boxes']);
        $this->assertSame('Chargepoint', $first['charger_boxes'][0]['nama_chargerbox']);
    }

    public function test_index_with_coord_codec_header_removes_lat_lng_and_adds_loc(): void
    {
        $this->seedCanonical();

        $response = $this->getJson('/api/v1/spklu', ['X-Coord-Codec' => 'v1']);

        $response->assertOk();
        $json = $response->json();

        $codec = new \App\Support\CoordinateCodec; // secret dari config app — sama dgn yang dipakai endpoint
        foreach ($json['data'] as $item) {
            $this->assertArrayNotHasKey('latitude', $item, 'latitude should not be present with codec header');
            $this->assertArrayNotHasKey('longitude', $item, 'longitude should not be present with codec header');
            $this->assertArrayHasKey('loc', $item, 'loc should be present with codec header');
            $this->assertNotEmpty($item['loc']);

            // loc harus ter-decode kembali ke koordinat stasiun di DB.
            $decoded = $codec->decode($item['id'], $item['loc']);
            $this->assertNotNull($decoded, "loc for id {$item['id']} should decode");
            $station = ChargingStation::find($item['id']);
            $this->assertEqualsWithDelta((float) $station->latitude, $decoded['lat'], 0.00000001);
            $this->assertEqualsWithDelta((float) $station->longitude, $decoded['lng'], 0.00000001);
        }
    }

    public function test_index_without_header_returns_plain_lat_lng(): void
    {
        $this->seedCanonical();

        $response = $this->getJson('/api/v1/spklu');

        $response->assertOk();
        $json = $response->json();

        foreach ($json['data'] as $item) {
            $this->assertArrayHasKey('latitude', $item);
            $this->assertArrayHasKey('longitude', $item);
            $this->assertArrayNotHasKey('loc', $item);
        }
    }

    public function test_show_with_codec_header_encodes_coordinates(): void
    {
        $this->seedCanonical();
        $station = ChargingStation::where('source_station_id', 101)->firstOrFail();

        $response = $this->getJson('/api/v1/spklu/'.$station->id, ['X-Coord-Codec' => 'v1']);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertArrayNotHasKey('latitude', $data);
        $this->assertArrayNotHasKey('longitude', $data);
        $this->assertArrayHasKey('loc', $data);
    }

    public function test_index_filters_by_speed_id_mapping_type_charge(): void
    {
        $this->seedCanonical();

        // "fast" (speed id mobile) → label verbatim "Fast Charging"
        $response = $this->getJson('/api/v1/spklu?type_charge=fast');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Fast Charging', $response->json('data.0.type_charge'));

        // "medium" → "Medium Charging" + "Slow Charging"
        $response = $this->getJson('/api/v1/spklu?type_charge=medium');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Medium Charging', $response->json('data.0.type_charge'));
    }

    public function test_index_filters_by_provinsi_search_and_geo(): void
    {
        $this->seedCanonical();

        $response = $this->getJson('/api/v1/spklu?provinsi=DKI Jakarta');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('SPKLU PLN JAKARTA', $response->json('data.0.nama_lokasi'));

        $response = $this->getJson('/api/v1/spklu?search=MAKASSAR');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('SPKLU SHELL MAKASSAR', $response->json('data.0.nama_lokasi'));

        // Geo: dekat Jakarta (-6.20, 106.80), radius 10km
        $response = $this->getJson('/api/v1/spklu?lat=-6.20&lng=106.80&radius=10');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertArrayHasKey('distance_km', $response->json('data.0'));
    }

    public function test_show_looks_up_by_canonical_id(): void
    {
        $this->seedCanonical();

        $station = ChargingStation::where('source_station_id', 101)->firstOrFail();

        $response = $this->getJson('/api/v1/spklu/'.$station->id);

        $response->assertOk()
            ->assertJsonPath('data.id', (int) $station->id)
            ->assertJsonPath('data.type_charge', 'Fast Charging')
            ->assertJsonCount(1, 'data.charger_boxes');
    }

    public function test_show_404_for_missing_canonical_id(): void
    {
        $this->getJson('/api/v1/spklu/999999')->assertNotFound();
    }

    public function test_meta_filters_sourced_from_canonical(): void
    {
        $this->seedCanonical();

        $response = $this->getJson('/api/v1/meta/filters');

        $response->assertOk()
            ->assertJsonPath('data.provinces', ['DKI Jakarta', 'Sulawesi Selatan'])
            ->assertJsonPath('data.charge_types', ['Fast Charging', 'Medium Charging']);
    }

    public function test_meta_filters_include_data_version(): void
    {
        $this->seedCanonical();

        $response = $this->getJson('/api/v1/meta/filters');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertArrayHasKey('data_version', $data);
        $this->assertNotEmpty($data['data_version']);
    }

    public function test_index_response_includes_data_version_in_meta(): void
    {
        $this->seedCanonical();

        $response = $this->getJson('/api/v1/spklu');

        $response->assertOk();
        $meta = $response->json('meta');
        $this->assertArrayHasKey('data_version', $meta);
        $this->assertNotEmpty($meta['data_version']);

        // additional(['meta' => ...]) tidak boleh menimpa meta pagination.
        $this->assertArrayHasKey('current_page', $meta);
        $this->assertArrayHasKey('per_page', $meta);
        $this->assertArrayHasKey('total', $meta);
    }

    public function test_data_version_changes_when_data_changes(): void
    {
        $this->seedCanonical();

        $response1 = $this->getJson('/api/v1/spklu');
        $version1 = $response1->json('meta.data_version');

        // Add a new station.
        ChargingStation::create([
            'source' => CanonicalStationHydrateService::SOURCE_ESDM,
            'source_station_id' => 999,
            'nama_lokasi' => 'SPKLU BARU',
            'latitude' => -6.3,
            'longitude' => 106.9,
            'provinsi' => 'DKI Jakarta',
            'type_charge' => 'Fast Charging',
        ]);

        $response2 = $this->getJson('/api/v1/spklu');
        $version2 = $response2->json('meta.data_version');

        $this->assertNotSame($version1, $version2, 'data_version should change when data is added');
    }

    // ─── Setup ──────────────────────────────────────────────────────────────

    private function seedCanonical(): void
    {
        Provider::create(['name' => 'PLN Mobile', 'status' => 1]);
        Provider::create(['name' => 'Shell', 'status' => 1]);

        $jakarta = EsdmSinggatSpkluStation::create([
            'esdm_id' => 101,
            'nama_stasiun' => 'SPKLU PLN JAKARTA',
            'alamat_spklu' => 'Jl. Sudirman',
            'kode_provinsi' => '31',
            'kode_kota' => '3171',
            'nama_badan_usaha' => 'PERUSAHAAN PERSEROAN (PERSERO) PT. PERUSAHAAN LISTRIK NEGARA',
            'latitude' => -6.200000,
            'longitude' => 106.800000,
            'geo_status' => 'ok',
            'count_konektor' => 1,
            'raw_payload' => [],
        ]);
        $this->addInstallation($jakarta, 'Fast Charging', 'CCS2', 'Chargepoint');

        $makassar = EsdmSinggatSpkluStation::create([
            'esdm_id' => 102,
            'nama_stasiun' => 'SPKLU SHELL MAKASSAR',
            'alamat_spklu' => 'Jl. Jenderal Sudirman',
            'kode_provinsi' => '73',
            'kode_kota' => '7371',
            'nama_badan_usaha' => 'PT SHELL INDONESIA',
            'latitude' => -5.147700,
            'longitude' => 119.432700,
            'geo_status' => 'ok',
            'count_konektor' => 1,
            'raw_payload' => [],
        ]);
        $this->addInstallation($makassar, 'Medium Charging', 'Type 2', 'ABB');

        app(CanonicalStationHydrateService::class)->hydrateFromEsdm();
    }

    private function addInstallation(
        EsdmSinggatSpkluStation $station,
        string $typeCharge,
        string $connectorName,
        string $machineBrand
    ): void {
        $installation = EsdmSinggatSpkluInstallation::create([
            'esdm_id' => random_int(1000, 9999),
            'station_id' => $station->id,
            'station_esdm_id' => $station->esdm_id,
            'nomor_identitas' => 'REG-'.$station->esdm_id,
            'merek_mesin' => $machineBrand,
            'jenis_pengisian_spklu' => $typeCharge,
            'harga_pengisian_raw' => '2466',
            'harga_layanan_raw' => '0',
        ]);

        EsdmSinggatSpkluConnector::create([
            'esdm_id' => random_int(10000, 99999),
            'installation_id' => $installation->id,
            'installation_esdm_id' => $installation->esdm_id,
            'nama_konektor' => $connectorName,
            'status' => 'Beroperasi',
            'status_konektor' => 'available',
            'img_path' => 'storage/esdm/konektor_unique/'.$connectorName.'.png',
        ]);
    }
}
