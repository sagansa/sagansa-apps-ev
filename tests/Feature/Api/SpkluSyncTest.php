<?php

namespace Tests\Feature\Api;

use App\Models\ChargingStation;
use App\Services\CanonicalStationHydrateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SpkluSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['spklu.serving_source' => CanonicalStationHydrateService::SOURCE_PLN]);
    }

    public function test_sync_manifest_returns_markers_scoped_to_serving_source(): void
    {
        $a = ChargingStation::create([
            'source' => 'pln',
            'source_station_id' => 1,
            'nama_lokasi' => 'SPKLU A',
            'latitude' => -6.2,
            'longitude' => 106.8,
        ]);
        $b = ChargingStation::create([
            'source' => 'pln',
            'source_station_id' => 2,
            'nama_lokasi' => 'SPKLU B',
            'latitude' => -6.9,
            'longitude' => 107.6,
        ]);
        // Baris source lain tidak boleh memengaruhi marker serving.
        ChargingStation::create([
            'source' => 'esdm',
            'source_station_id' => 999,
            'nama_lokasi' => 'SPKLU ESDM',
            'latitude' => -7.0,
            'longitude' => 110.0,
        ]);

        // Bekukan timestamp via query builder agar deterministik.
        ChargingStation::query()->whereKey($a->id)->toBase()
            ->update(['created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        ChargingStation::query()->whereKey($b->id)->toBase()
            ->update(['created_at' => '2026-02-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00']);

        $response = $this->getJson('/api/v1/meta/spklu-sync');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.count', 2)
            ->assertJsonPath('data.max_created_at', '2026-02-01T00:00:00.000000Z')
            ->assertJsonPath('data.max_updated_at', '2026-03-01T00:00:00.000000Z');
    }

    public function test_sync_manifest_handles_empty_dataset(): void
    {
        $response = $this->getJson('/api/v1/meta/spklu-sync');

        $response->assertOk()
            ->assertJsonPath('data.count', 0)
            ->assertJsonPath('data.max_created_at', null)
            ->assertJsonPath('data.max_updated_at', null);
    }
}
