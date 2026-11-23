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

    public function test_manifest_menyertakan_data_version_yang_berubah_saat_dataset_berubah(): void
    {
        // Seed mengikuti pola test di file ini — minimal 2 stasiun source pln
        // dengan latitude/longitude not-null.
        $a = $this->seedStationWithTimes(1, '2026-01-01 00:00:00', '2026-01-01 00:00:00');
        $this->seedStationWithTimes(2, '2026-02-01 00:00:00', '2026-02-01 00:00:00');

        $v1 = $this->getJson('/api/v1/meta/spklu-sync')->json('data');

        $this->assertArrayHasKey('data_version', $v1);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $v1['data_version']);
        $this->assertSame(
            \App\Support\SpkluServing::computeDataVersion(),
            $v1['data_version'],
            'data_version manifest = computeDataVersion()'
        );

        // Penghapusan satu baris harus mengubah data_version (inilah sensor
        // penghapusan yang dipakai klien untuk purge ghost).
        $a->delete();
        $v2 = $this->getJson('/api/v1/meta/spklu-sync')->json('data');

        $this->assertNotSame($v1['data_version'], $v2['data_version']);
    }

    private function seedStationWithTimes(int $sourceId, string $created, string $updated): ChargingStation
    {
        $station = ChargingStation::create([
            'source' => 'pln',
            'source_station_id' => $sourceId,
            'nama_lokasi' => 'SPKLU '.$sourceId,
            'latitude' => -6.2,
            'longitude' => 106.8,
        ]);
        ChargingStation::query()->whereKey($station->id)->toBase()
            ->update(['created_at' => $created, 'updated_at' => $updated]);

        return $station;
    }

    public function test_index_updated_since_returns_only_changed_rows(): void
    {
        $this->seedStationWithTimes(1, '2026-01-01 00:00:00', '2026-01-01 00:00:00');
        $this->seedStationWithTimes(2, '2026-04-01 00:00:00', '2026-04-01 00:00:00'); // baru
        $this->seedStationWithTimes(3, '2025-12-01 00:00:00', '2026-05-01 00:00:00'); // diedit

        $response = $this->getJson('/api/v1/spklu?updated_since=2026-03-01T00:00:00.000000Z&per_page=100');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('nama_lokasi')->all();
        sort($names);
        $this->assertSame(['SPKLU 2', 'SPKLU 3'], $names);
    }

    public function test_index_meta_includes_sync_markers(): void
    {
        $this->seedStationWithTimes(1, '2026-01-01 00:00:00', '2026-01-01 00:00:00');

        $response = $this->getJson('/api/v1/spklu?per_page=100');

        $response->assertOk()
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('meta.max_created_at', '2026-01-01T00:00:00.000000Z')
            ->assertJsonPath('meta.max_updated_at', '2026-01-01T00:00:00.000000Z');
        $this->assertNotEmpty($response->json('meta.data_version'));
    }

    public function test_index_rejects_invalid_updated_since(): void
    {
        $response = $this->getJson('/api/v1/spklu?updated_since=bukan-tanggal');

        $response->assertStatus(422);
    }

    public function test_fold_esdm_status_does_not_bump_updated_at(): void
    {
        $station = ChargingStation::create([
            'source' => 'esdm',
            'source_station_id' => 77,
            'nama_lokasi' => 'SPKLU ESDM X',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'availability_level' => 'unknown',
            'available_count' => 0,
        ]);
        ChargingStation::query()->whereKey($station->id)->toBase()
            ->update(['updated_at' => '2026-01-01 00:00:00']);

        app(CanonicalStationHydrateService::class)->foldEsdmStatus(77, [
            'availability_level' => 'available',
            'available_count' => 2,
            'charging_count' => 0,
            'finishing_count' => 0,
            'aggregated_at' => Carbon::parse('2026-09-17 08:00:00'),
        ]);

        $fresh = $station->fresh();
        $this->assertSame('available', $fresh->availability_level);
        $this->assertSame(2, $fresh->available_count);
        // Status berubah tapi updated_at TIDAK boleh ter-bump.
        $this->assertSame(
            '2026-01-01 00:00:00',
            $fresh->updated_at->format('Y-m-d H:i:s')
        );
    }
}
