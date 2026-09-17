<?php

namespace Tests\Feature\Api;

use App\Models\ChargingStation;
use App\Models\ChargingStationCharger;
use App\Models\ChargingStationConnector;
use App\Models\Provider;
use App\Services\CanonicalStationHydrateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresi kompatibilitas app versi TERPUBLIKASI (1.1.x/1.2.x).
 *
 * App lama: tanpa header X-Coord-Codec, tanpa updated_since, parser
 * kotlinx ignoreUnknownKeys (field baru diabaikan). Test ini mengunci bahwa
 * endpoint SPKLU inti tetap menyajikan kontrak lama SECARA UTUH — field lama
 * tidak boleh hilang/bernama ulang/berubah tipe, dan koordinat tetap polos
 * selama codec tidak di-enforce. Bila test ini gagal setelah perubahan API,
 * berarti app terpublikasi akan terganggu — jangan deploy.
 */
class SpkluOldClientCompatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['spklu.serving_source' => CanonicalStationHydrateService::SOURCE_PLN]);
        // Pin eksplisit — app lama tidak bisa decode `loc`.
        config(['spklu.coord_codec_enforce' => false]);

        $this->seedOneCanonicalStation();
    }

    public function test_index_serves_full_old_contract_without_new_headers_or_params(): void
    {
        // Persis seperti permintaan app lama: query params lama, tanpa header baru.
        $response = $this->getJson('/api/v1/spklu?provinsi=Jawa%20Barat&type_charge=fast&per_page=50');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        // Field lama versi terpublikasi — SEMUA harus tetap ada.
                        'id', 'provinsi', 'kabupaten_kota', 'nama_lokasi', 'alamat',
                        'latitude', 'longitude', 'keterangan', 'status', 'type_charge',
                        'watt', 'toll_category', 'location_category', 'kategori_tol',
                        'kategori_lokasi', 'total_charger', 'total_konektor',
                        'availability_level', 'available_count', 'charging_count',
                        'finishing_count', 'status_updated_at', 'provider_id',
                        'provider_name', 'provider_logo',
                        'charger_boxes' => [
                            '*' => [
                                'id', 'chargerbox_id', 'nama_chargerbox', 'type_charge',
                                'watt', 'jumlah_charger', 'jumlah_konektor', 'icon',
                                'gambar', 'availability_level', 'available_count',
                                'status_updated_at', 'connectors',
                            ],
                        ],
                    ],
                ],
                'links',
                // Paginasi lama tetap di meta (marker delta-sync hanyalah kunci tambahan).
                'meta' => ['current_page', 'last_page', 'total', 'per_page'],
            ]);

        $item = $response->json('data.0');
        // Codec tidak aktif tanpa header → koordinat polos, tanpa field `loc`.
        $this->assertNotNull($item['latitude']);
        $this->assertNotNull($item['longitude']);
        $this->assertArrayNotHasKey('loc', $item);
        // Charger box + konektor nested tetap terisi (form sesi charging lama membacanya).
        $box = $item['charger_boxes'][0];
        $this->assertSame('CB-COMPAT-1', $box['chargerbox_id']);
        $this->assertNotEmpty($box['connectors']);
    }

    public function test_show_serves_old_detail_contract(): void
    {
        $station = ChargingStation::firstOrFail();

        $response = $this->getJson("/api/v1/spklu/{$station->id}");

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'data' => [
                    'id', 'nama_lokasi', 'latitude', 'longitude', 'type_charge',
                    'watt', 'total_charger', 'total_konektor', 'provider_name',
                    'charger_boxes',
                ],
            ])
            ->assertJsonPath('data.latitude', -6.56);
        $this->assertArrayNotHasKey('loc', $response->json('data'));
    }

    public function test_meta_filters_keeps_old_keys_including_data_version(): void
    {
        $response = $this->getJson('/api/v1/meta/filters');

        $response->assertOk()
            ->assertJsonStructure([
                'status',
                'data' => [
                    // Konsumsi app lama dari /meta/filters.
                    'provinces', 'charge_types', 'kategori_tol', 'kategori_lokasi',
                    'data_version',
                ],
            ])
            ->assertJsonPath('data.provinces.0', 'Jawa Barat')
            ->assertJsonPath('data.charge_types.0', 'Fast Charging');
        $this->assertNotSame('', $response->json('data.data_version'));
    }

    private function seedOneCanonicalStation(): void
    {
        $provider = Provider::create(['name' => 'PLN Mobile', 'status' => 1]);

        $station = ChargingStation::create([
            'source' => 'pln',
            'source_station_id' => 9001,
            'nama_lokasi' => 'SPKLU COMPAT SENTUL',
            'alamat' => 'Jl. Compat No. 1',
            'latitude' => -6.56,
            'longitude' => 106.86,
            'provinsi' => 'Jawa Barat',
            'kategori_tol' => 'NON TOL',
            'kategori_lokasi' => 'Publik',
            'toll_category' => 'NON TOL',
            'location_category' => 'Publik',
            'type_charge' => 'Fast Charging',
            'watt' => '25–50 kW DC',
            'total_charger' => 1,
            'total_konektor' => 2,
            'provider_id' => $provider->id,
            'provider_name' => 'PLN Mobile',
            'availability_level' => 'unknown',
            'available_count' => 0,
            'charging_count' => 0,
            'finishing_count' => 0,
        ]);

        $charger = ChargingStationCharger::create([
            'station_id' => $station->id,
            'source_charger_id' => 9001,
            'chargerbox_id' => 'CB-COMPAT-1',
            'type_charge' => 'Fast Charging',
            'nama' => 'ABB',
            'watt' => '25–50 kW DC',
            'jumlah_charger' => 1,
            'jumlah_konektor' => 2,
            'icon' => null,
            'gambar' => null,
        ]);

        ChargingStationConnector::create([
            'charger_id' => $charger->id,
            'source_connector_id' => 90011,
            'nama_konektor' => 'CCS2',
            'img_path' => null,
        ]);
        ChargingStationConnector::create([
            'charger_id' => $charger->id,
            'source_connector_id' => 90012,
            'nama_konektor' => 'CHAdeMO',
            'img_path' => null,
        ]);
    }
}
