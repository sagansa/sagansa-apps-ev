<?php

namespace Tests\Feature\Api;

use App\Models\ChargingStation;
use App\Models\VoltronPoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalVoltronIngestTest extends TestCase
{
    use RefreshDatabase;

    private const SNAPSHOT = [
        'metadata' => ['diambil_pada' => '2026-11-28T01:00:00Z', 'jumlah' => 1],
        'points' => [[
            'source' => 'voltron', 'external_id' => 500160,
            'nama' => 'Voltron Endpoint Test', 'lat' => -6.2, 'lng' => 106.8,
            'operator' => 'Voltron', 'status' => 'Available', 'jumlah_poin' => 1,
            'konektor' => [['nama' => 'CCS 2', 'daya_kw' => 60.0, 'jumlah' => 1, 'status' => 'Available']],
        ]],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['spklu.ingest_token' => 'rahasia-ingest']);
    }

    public function test_ingest_sukses_dengan_token_valid(): void
    {
        $response = $this->postJson('/api/v1/internal/spklu/voltron-import', self::SNAPSHOT, [
            'Authorization' => 'Bearer rahasia-ingest',
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'ok');
        $response->assertJsonPath('import.imported', 1);
        $response->assertJsonPath('hydrate.created', 1);

        $this->assertSame(1, VoltronPoint::count());
        $this->assertSame(1, ChargingStation::where('source', 'voltron')->count());
    }

    public function test_token_salah_atau_hilang_401(): void
    {
        $this->postJson('/api/v1/internal/spklu/voltron-import', self::SNAPSHOT)
            ->assertStatus(401);

        $this->postJson('/api/v1/internal/spklu/voltron-import', self::SNAPSHOT, [
            'Authorization' => 'Bearer token-salah',
        ])->assertStatus(401);

        $this->assertSame(0, VoltronPoint::count());
    }

    public function test_token_env_kosong_endpoint_mati(): void
    {
        config(['spklu.ingest_token' => '']);

        $this->postJson('/api/v1/internal/spklu/voltron-import', self::SNAPSHOT, [
            'Authorization' => 'Bearer ',
        ])->assertStatus(401);

        $this->assertSame(0, VoltronPoint::count());
    }

    public function test_payload_invalid_422(): void
    {
        $this->postJson('/api/v1/internal/spklu/voltron-import', ['metadata' => []], [
            'Authorization' => 'Bearer rahasia-ingest',
        ])->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    public function test_sanity_gate_422_dan_data_tidak_berubah(): void
    {
        // Seed baseline 10 titik via import langsung.
        $titik = [];
        foreach (range(1, 10) as $i) {
            $titik[] = [
                'external_id' => $i, 'nama' => "Titik $i", 'lat' => -6.2 + $i * 0.01, 'lng' => 106.8,
                'operator' => 'Voltron', 'status' => 'Available', 'jumlah_poin' => 1,
                'konektor' => [['nama' => 'CCS 2', 'daya_kw' => 60.0, 'jumlah' => 1, 'status' => 'Available']],
            ];
        }
        app(\App\Services\VoltronSyncService::class)->import(['metadata' => [], 'points' => $titik]);

        // Body hanya 1 titik < 80% × 10 → gate menolak, DB utuh.
        $this->postJson('/api/v1/internal/spklu/voltron-import', self::SNAPSHOT, [
            'Authorization' => 'Bearer rahasia-ingest',
        ])->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $this->assertSame(10, VoltronPoint::count());
    }

    public function test_diambil_pada_bukan_tanggal_422_bukan_500(): void
    {
        // Carbon::parse di service melempar InvalidFormatException —
        // controller wajib membalas 422, bukan 500, dan DB tetap kosong.
        $snapshot = self::SNAPSHOT;
        $snapshot['metadata']['diambil_pada'] = 'bukan-tanggal';

        $this->postJson('/api/v1/internal/spklu/voltron-import', $snapshot, [
            'Authorization' => 'Bearer rahasia-ingest',
        ])->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $this->assertSame(0, VoltronPoint::count());
    }
}
