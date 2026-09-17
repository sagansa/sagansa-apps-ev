<?php

namespace Tests\Feature\Api;

use App\Models\OpenchargemapPoi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GET /api/v1/ocm/nearby — serve lokal + auto-harvest titik baru dari OCM API.
 * OCM API di-fake; koordinat query & POI dikontrol (1° lat ≈ 111 km).
 */
class OcmNearbyTest extends TestCase
{
    use RefreshDatabase;

    private const LAT = -6.2;

    private const LNG = 106.8;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_serves_local_points_and_harvests_new_ones(): void
    {
        $this->seedLocalPoi();

        Http::fake([
            'api.openchargemap.io/*' => Http::response([
                $this->rawApiPoi(7001, 'Brand New Mall Charger', self::LAT + 0.001, self::LNG + 0.001),
            ], 200),
        ]);

        $response = $this->getJson('/api/v1/ocm/nearby?lat='.self::LAT.'&lng='.self::LNG.'&radius_km=5');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('meta.harvest.fetched', 1)
            ->assertJsonPath('meta.harvest.inserted', 1)
            ->assertJsonPath('meta.attribution', fn ($v) => str_contains((string) $v, 'Open Charge Map'));

        // Lokal tetap tersaji + yang baru langsung masuk DB.
        $this->assertSame(2, OpenchargemapPoi::count());
        $this->assertDatabaseHas('openchargemap_pois', [
            'ocm_id' => 7001,
            'title' => 'Brand New Mall Charger',
            'operator' => 'Terra Charge',
        ]);
    }

    public function test_cooldown_prevents_repeated_api_calls(): void
    {
        $this->seedLocalPoi();

        Http::fake([
            'api.openchargemap.io/*' => Http::response([], 200),
        ]);

        $this->getJson('/api/v1/ocm/nearby?lat='.self::LAT.'&lng='.self::LNG.'&radius_km=5')->assertOk();
        $this->getJson('/api/v1/ocm/nearby?lat='.self::LAT.'&lng='.self::LNG.'&radius_km=5')->assertOk();

        Http::assertSentCount(1); // panggilan kedua kena cooldown

        $second = $this->getJson('/api/v1/ocm/nearby?lat='.self::LAT.'&lng='.self::LNG.'&radius_km=5');
        $second->assertOk()->assertJsonPath('meta.harvest.cooldown', true);
    }

    public function test_api_failure_does_not_break_response(): void
    {
        $this->seedLocalPoi();

        Http::fake([
            'api.openchargemap.io/*' => Http::response('upstream down', 500),
        ]);

        $response = $this->getJson('/api/v1/ocm/nearby?lat='.self::LAT.'&lng='.self::LNG.'&radius_km=5');

        $response->assertOk()->assertJsonPath('status', 'success');
        $this->assertSame(1, count($response->json('data')));
        $this->assertNotNull($response->json('meta.harvest.error'));
    }

    public function test_validates_missing_coordinates(): void
    {
        $this->getJson('/api/v1/ocm/nearby')->assertStatus(400);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function seedLocalPoi(): void
    {
        OpenchargemapPoi::create([
            'ocm_id' => 6001,
            'title' => 'Existing Local Charger',
            'latitude' => self::LAT + 0.0005,
            'longitude' => self::LNG + 0.0005,
            'operator' => 'Local Op',
        ]);
    }

    /** Bentuk respons API OCM mentah (compact). */
    private function rawApiPoi(int $id, string $title, float $lat, float $lng): array
    {
        return [
            'ID' => $id,
            'UUID' => 'uuid-'.$id,
            'Title' => null, // bulk import sering null; nama ada di AddressInfo
            'AddressInfo' => [
                'ID' => $id + 500000,
                'Title' => $title,
                'AddressLine1' => 'Jl. Harvest No. 2',
                'Town' => 'Jakarta Selatan',
                'StateOrProvince' => 'DKI Jakarta',
                'Postcode' => '12130',
                'CountryID' => 107,
                'Latitude' => $lat,
                'Longitude' => $lng,
            ],
            'Connections' => [[
                'ConnectionTypeID' => 33,
                'LevelID' => 3,
                'PowerKW' => 120,
                'Quantity' => 2,
            ]],
            'OperatorInfo' => ['ID' => 3664, 'Title' => 'Terra Charge'],
            'UsageCost' => 'IDR 2467/kWh',
            'StatusType' => ['ID' => 50, 'Title' => 'Operational', 'IsOperational' => true],
            'NumberOfPoints' => 2,
            'DataQualityLevel' => 1,
            'DateCreated' => '2026-09-07T16:49:00Z',
            'DateLastStatusUpdate' => '2026-09-07T16:49:00Z',
            'DateLastVerified' => '2026-09-07T16:49:00Z',
        ];
    }
}
