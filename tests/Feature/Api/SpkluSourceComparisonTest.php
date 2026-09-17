<?php

namespace Tests\Feature\Api;

use App\Models\ChargingStation;
use App\Models\OpenchargemapPoi;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;

/**
 * Endpoint admin perbandingan 3 sumber (esdm ↔ pln ↔ ocm).
 * Seed koordinat dikontrol agar klasifikasi deterministik:
 * 0.001° lat ≈ 111 m.
 */
class SpkluSourceComparisonTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_non_admin_is_rejected(): void
    {
        $this->getJson('/api/v1/admin/spklu-source-comparison')
            ->assertStatus(403)
            ->assertJsonPath('status', 'error');
    }

    public function test_super_admin_gets_summary_and_classified_items(): void
    {
        $this->actingAsSuperAdmin();
        $this->seedCases();

        $response = $this->getJson('/api/v1/admin/spklu-source-comparison?refresh=1');

        $response->assertOk()->assertJsonPath('status', 'success');

        $json = $response->json('data');

        // Pasangan esdm_pln: 1 exact (30 m), 1 near (100 m), 1 candidate (500 m).
        $this->assertSame(1, $json['summary']['esdm_pln']['match_exact']);
        $this->assertSame(1, $json['summary']['esdm_pln']['match_near']);
        $this->assertSame(1, $json['summary']['esdm_pln']['candidate']);

        // Pasangan esdm_ocm: conflict_far (nama sama, ~30 km).
        $this->assertSame(1, $json['summary']['esdm_ocm']['conflict_far']);

        // OCM unik (jauh & nama beda).
        $this->assertSame(1, $json['summary']['esdm_ocm']['unique']);

        $categories = collect($json['items'])->pluck('category')->unique()->all();
        $this->assertContains('match_exact', $categories);
        $this->assertContains('match_near', $categories);
        $this->assertContains('candidate', $categories);
        $this->assertContains('conflict_far', $categories);
        $this->assertContains('unique', $categories);
    }

    public function test_category_filter_limits_items(): void
    {
        $this->actingAsSuperAdmin();
        $this->seedCases();

        $response = $this->getJson(
            '/api/v1/admin/spklu-source-comparison?refresh=1&pair=esdm_pln&category=match_exact'
        );

        $response->assertOk();
        $items = $response->json('data.items');
        $this->assertNotSame([], $items);
        foreach ($items as $item) {
            $this->assertSame('match_exact', $item['category']);
            $this->assertSame('esdm_pln', $item['pair']);
        }
    }

    public function test_search_filter_matches_names(): void
    {
        $this->actingAsSuperAdmin();
        $this->seedCases();

        $response = $this->getJson(
            '/api/v1/admin/spklu-source-comparison?refresh=1&q=Tugu'
        );

        $response->assertOk();
        foreach ($response->json('data.items') as $item) {
            $nameA = mb_strtolower((string) ($item['a']['name'] ?? ''));
            $nameB = mb_strtolower((string) ($item['b']['name'] ?? ''));
            $this->assertTrue(
                str_contains($nameA, 'tugu') || str_contains($nameB, 'tugu'),
                'Item di luar filter q: '.$nameA.' / '.$nameB
            );
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function actingAsSuperAdmin(): void
    {
        Role::findOrCreate('super_admin', 'web');
        $this->authUser->assignRole('super_admin');
    }

    private function seedChargingStation(string $source, int $sourceStationId, string $name, float $lat, float $lng): ChargingStation
    {
        return ChargingStation::create([
            'source' => $source,
            'source_station_id' => (string) $sourceStationId,
            'nama_lokasi' => $name,
            'alamat' => 'Jl. Test No. 1',
            'latitude' => $lat,
            'longitude' => $lng,
            'provider_name' => 'Provider '.$source,
        ]);
    }

    private function seedCases(): void
    {
        $baseLat = -6.2;
        $baseLng = 106.8;

        // esdm_pln — match_exact (30 m, nama sama)
        $this->seedChargingStation('esdm', 1, 'SPKLU Tugu Barat', $baseLat, $baseLng);
        $this->seedChargingStation('pln', 101, 'SPKLU Tugu Barat', $baseLat + 0.00027, $baseLng);

        // esdm_pln — match_near (±100 m)
        $this->seedChargingStation('esdm', 2, 'SPKLU Senayan Indah', $baseLat + 0.01, $baseLng);
        $this->seedChargingStation('pln', 102, 'SPKLU Senayan Indah', $baseLat + 0.0109, $baseLng);

        // esdm_pln — candidate (±500 m, nama serupa)
        $this->seedChargingStation('esdm', 3, 'SPKLU Melati Raya', $baseLat + 0.02, $baseLng);
        $this->seedChargingStation('pln', 103, 'SPKLU Melati Raya II', $baseLat + 0.0245, $baseLng);

        // esdm_ocm — conflict_far (±30 km, nama sama); PLN tidak punya pasangan ini
        $this->seedChargingStation('esdm', 4, 'SPKLU Bandara Citra', $baseLat + 0.03, $baseLng);
        OpenchargemapPoi::create([
            'ocm_id' => 9001,
            'title' => 'SPKLU Bandara Citra',
            'latitude' => $baseLat + 0.30,
            'longitude' => $baseLng + 0.01,
            'operator' => 'OCM Operator',
        ]);

        // ocm — unique (jauh dari semua, nama/token unik)
        OpenchargemapPoi::create([
            'ocm_id' => 9002,
            'title' => 'Zebraq Corner Station',
            'latitude' => -7.8,
            'longitude' => 110.4,
            'operator' => 'Zebraq Net',
        ]);
    }
}
