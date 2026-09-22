<?php

namespace Tests\Feature;

use App\Models\BrandVehicle;
use App\Models\ChargingStation;
use App\Models\ModelVehicle;
use App\Models\SalesImport;
use App\Models\VehicleSalesStat;
use App\Services\CanonicalStationHydrateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExportAppSeedTest extends TestCase
{
    use RefreshDatabase;

    private string $outDir;

    protected function setUp(): void
    {
        parent::setUp();

        config(['spklu.serving_source' => CanonicalStationHydrateService::SOURCE_PLN]);

        $this->outDir = storage_path('app/seed-test-'.uniqid());
    }

    protected function tearDown(): void
    {
        if (is_dir($this->outDir)) {
            $files = glob($this->outDir.'/*');
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($this->outDir);
        }

        parent::tearDown();
    }

    private function seedStations(): void
    {
        $a = ChargingStation::create([
            'source' => 'pln',
            'source_station_id' => 1,
            'nama_lokasi' => 'SPKLU PLN Jakarta',
            'provinsi' => 'DKI Jakarta',
            'latitude' => -6.2,
            'longitude' => 106.8,
        ]);
        $b = ChargingStation::create([
            'source' => 'pln',
            'source_station_id' => 2,
            'nama_lokasi' => 'SPKLU PLN Bandung',
            'provinsi' => 'Jawa Barat',
            'latitude' => -6.9,
            'longitude' => 107.6,
        ]);
        // Source lain tidak boleh masuk seed.
        ChargingStation::create([
            'source' => 'esdm',
            'source_station_id' => 999,
            'nama_lokasi' => 'SPKLU ESDM',
            'latitude' => -7.0,
            'longitude' => 110.0,
        ]);

        ChargingStation::query()->whereKey($a->id)->toBase()
            ->update(['created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-15 00:00:00']);
        ChargingStation::query()->whereKey($b->id)->toBase()
            ->update(['created_at' => '2026-02-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00']);
    }

    public function test_spklu_seed_matches_api_response(): void
    {
        $this->seedStations();

        $this->artisan('app:export-seed', ['--out' => $this->outDir])
            ->assertSuccessful();

        // Baca seed file.
        $seedPath = $this->outDir.'/spklu_seed.json';
        $this->assertFileExists($seedPath);

        $seed = json_decode(file_get_contents($seedPath), true);
        $this->assertIsArray($seed);
        $this->assertArrayHasKey('meta', $seed);
        $this->assertArrayHasKey('locations', $seed);

        // Meta marker harus match endpoint sync.
        $syncResponse = $this->getJson('/api/v1/meta/spklu-sync');
        $syncResponse->assertOk();
        $syncData = $syncResponse->json('data');

        $this->assertSame($syncData['count'], $seed['meta']['count']);
        $this->assertSame($syncData['max_created_at'], $seed['meta']['max_created_at']);
        $this->assertSame($syncData['max_updated_at'], $seed['meta']['max_updated_at']);
        $this->assertSame(2, $seed['meta']['schema_version']);
        $this->assertNotEmpty($seed['meta']['generated_at']);

        // Data harus match endpoint API (tanpa filter).
        $apiResponse = $this->getJson('/api/v1/spklu?per_page=5000');
        $apiResponse->assertOk();

        $apiIds = collect($apiResponse->json('data'))->pluck('id')->sort()->values()->all();
        $seedIds = collect($seed['locations'])->pluck('id')->sort()->values()->all();

        $this->assertCount(2, $apiIds);
        $this->assertSame($apiIds, $seedIds);

        // Validasi keras pipeline: marker count HARUS == jumlah baris terekspor.
        $this->assertSame(count($seed['locations']), $seed['meta']['count']);

        // Setiap lokasi harus punya field 'loc' (terenkode) karena header X-Coord-Codec: v1.
        foreach ($seed['locations'] as $loc) {
            $this->assertArrayHasKey('loc', $loc, 'Each seed location should have encoded loc field');
            $this->assertArrayNotHasKey('latitude', $loc, 'Seed location should not have plaintext latitude');
            $this->assertArrayNotHasKey('longitude', $loc, 'Seed location should not have plaintext longitude');
        }
    }

    public function test_spklu_seed_has_correct_envelope_structure(): void
    {
        $this->seedStations();

        $this->artisan('app:export-seed', ['--out' => $this->outDir])
            ->assertSuccessful();

        $seed = json_decode(file_get_contents($this->outDir.'/spklu_seed.json'), true);

        // Envelope structure: meta + locations.
        $this->assertArrayHasKey('meta', $seed);
        $this->assertArrayHasKey('locations', $seed);

        $meta = $seed['meta'];
        $this->assertArrayHasKey('schema_version', $meta);
        $this->assertArrayHasKey('count', $meta);
        $this->assertArrayHasKey('max_created_at', $meta);
        $this->assertArrayHasKey('max_updated_at', $meta);
        $this->assertArrayHasKey('generated_at', $meta);

        // Each location has required fields from SpkluLocationResource.
        foreach ($seed['locations'] as $loc) {
            $this->assertArrayHasKey('id', $loc);
            $this->assertArrayHasKey('nama_lokasi', $loc);
            $this->assertArrayHasKey('loc', $loc);
        }
    }

    public function test_market_seed_has_envelope_structure(): void
    {
        $this->seedStations();

        $this->artisan('app:export-seed', ['--out' => $this->outDir])
            ->assertSuccessful();

        $seedPath = $this->outDir.'/market_seed.json';
        $this->assertFileExists($seedPath);

        $seed = json_decode(file_get_contents($seedPath), true);
        $this->assertIsArray($seed);

        // Envelope: meta + sections.
        $this->assertArrayHasKey('meta', $seed);
        $this->assertArrayHasKey('sections', $seed);

        $meta = $seed['meta'];
        $this->assertArrayHasKey('last_import_at', $meta);
        $this->assertArrayHasKey('latest_import_id', $meta);
        $this->assertArrayHasKey('data_version', $meta);
        $this->assertArrayHasKey('latest_year', $meta);
        $this->assertArrayHasKey('latest_month', $meta);
        $this->assertArrayHasKey('generated_at', $meta);

        $sections = $seed['sections'];
        $this->assertArrayHasKey('summary', $sections);
        $this->assertArrayHasKey('trend', $sections);
    }

    /**
     * Seksi market seed harus IDENTIK dengan payload endpoint live (bentuk
     * yang diparse VehicleMarketRepository) — seed dibentuk objek mentah,
     * bukan string.
     */
    public function test_market_seed_sections_match_endpoints(): void
    {
        $byd = BrandVehicle::create(['name' => 'BYD']);
        $atto = ModelVehicle::create(['brand_vehicle_id' => $byd->id, 'name' => 'Atto 1']);
        $import = SalesImport::create([
            'file_name' => '2026.xlsx', 'source' => 'gaikindo', 'year' => 2026,
            'period_start' => '2026-01-01', 'period_end' => '2026-03-31', 'status' => 'processed',
            'meta' => ['official' => ['grand' => [
                'label' => 'DOMESTIC SALES TOTAL', 'total' => 90000,
                'months' => [1 => 30000, 2 => 30000, 3 => 30000],
            ]]],
        ]);
        foreach (range(1, 3) as $m) {
            VehicleSalesStat::create([
                'sales_import_id' => $import->id, 'raw_brand' => 'BYD', 'raw_model' => 'Atto 1',
                'brand_vehicle_id' => $byd->id, 'model_vehicle_id' => $atto->id, 'segment' => 'Sedan',
                'powertrain' => 'BEV', 'year' => 2026, 'month' => $m, 'units' => 1000,
            ]);
        }
        VehicleSalesStat::create([
            'sales_import_id' => $import->id, 'raw_brand' => 'BYD', 'raw_model' => 'Atto 1',
            'brand_vehicle_id' => $byd->id, 'model_vehicle_id' => $atto->id, 'segment' => 'Sedan',
            'powertrain' => 'BEV', 'year' => 2026, 'month' => null, 'units' => 3000,
        ]);

        $this->artisan('app:export-seed', ['--out' => $this->outDir])
            ->assertSuccessful();

        $seed = json_decode(file_get_contents($this->outDir.'/market_seed.json'), true);

        Sanctum::actingAs(\App\Models\User::factory()->create(), abilities: ['*']);

        $endpoints = [
            'summary' => '/api/v1/vehicle-market/summary',
            'trend' => '/api/v1/vehicle-market/trend',
            'top' => '/api/v1/vehicle-market/top?powertrain=BEV&limit=10',
            'catalog' => '/api/v1/vehicle-market/catalog',
            'composition' => '/api/v1/vehicle-market/composition?powertrain=ALL',
        ];
        foreach ($endpoints as $section => $url) {
            $data = $this->getJson($url)->assertOk()->json('data');
            $this->assertNotNull($data, "endpoint {$section} harus berisi data");
            $this->assertSame(
                $data,
                $seed['sections'][$section],
                "Seksi '{$section}' seed harus identik dengan payload endpoint."
            );
        }

        // Meta seed == endpoint meta.
        $meta = $this->getJson('/api/v1/vehicle-market/meta')->assertOk()->json('data');
        $this->assertSame($meta['last_import_at'], $seed['meta']['last_import_at']);
        $this->assertSame($meta['data_version'], $seed['meta']['data_version']);
    }

    public function test_command_returns_zero_on_success(): void
    {
        $this->artisan('app:export-seed', ['--out' => $this->outDir])
            ->assertSuccessful();
    }
}
