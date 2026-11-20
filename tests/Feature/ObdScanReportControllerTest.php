<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST /api/v1/obd/scan-reports — hasil scan OBD2 dari mobile.
 * Cover: 401 tanpa auth, 404 kill-switch mati, 201 valid tersimpan,
 * 422 validasi payload, dan trimming raw_response PID.
 */
class ObdScanReportControllerTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(): array
    {
        return [
            'model_vehicle_id' => null,
            'vin' => 'KMF3KM81ARU123456',
            'vin_shared' => true,
            'adapter_type' => 'BLE',
            'elm_protocol' => 'ISO 15765-4 (11 bit, 500k)',
            'profile_key' => null,
            'profile_version' => null,
            'profile_match' => 'unknown',
            'ecus_responded' => ['7E4', '7E3'],
            'supported_pids' => [
                ['mode' => 1, 'pid' => 91, 'description' => 'soc', 'supported' => true],
            ],
            'unsupported_pids' => [
                ['mode' => 34, 'pid' => 260, 'description' => 'SoC 220104', 'supported' => false,
                    'raw_response' => '7EC03415B72'],
            ],
            'discovery' => ['vin' => null, 'ecus' => [], 'didHits' => []],
            'dtc_codes' => [],
            'atrv_voltage' => 12.4,
            'app_version' => '1.6.0',
            'device_platform' => 'android',
            'session_started_at' => 1700000000000,
            'duration_ms' => 65000,
            'captured_at' => 1700000065000,
        ];
    }

    public function test_requires_authentication(): void
    {
        $this->postJson('/api/v1/obd/scan-reports', $this->validPayload())
            ->assertStatus(401);
    }

    public function test_returns_404_when_kill_switch_off(): void
    {
        Config::set('obd.enabled', false);

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/obd/scan-reports', $this->validPayload())
            ->assertStatus(404);
    }

    public function test_stores_valid_report(): void
    {
        Config::set('obd.enabled', true);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/obd/scan-reports', $this->validPayload())
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['id']]);

        $report = $user->obdScanReports()->firstOrFail();
        $this->assertSame('KMF3KM81ARU123456', $report->vin);
        $this->assertTrue($report->vin_shared);
        $this->assertSame('BLE', $report->adapter_type);
        $this->assertSame('unknown', $report->profile_match);
        $this->assertSame(['7E4', '7E3'], $report->ecus_responded);
        // raw_response dibuang server — tidak membengkak penyimpanan.
        $this->assertArrayNotHasKey('raw_response', $report->unsupported_pids[0]);
        $this->assertSame(12.4, (float) $report->atrv_voltage);
        $this->assertNotNull($report->captured_at);
    }

    public function test_defaults_vin_shared_true(): void
    {
        Config::set('obd.enabled', true);

        Sanctum::actingAs(User::factory()->create());

        $payload = $this->validPayload();
        unset($payload['vin_shared']);

        $this->postJson('/api/v1/obd/scan-reports', $payload)->assertStatus(201);

        $this->assertTrue(User::first()->obdScanReports()->firstOrFail()->vin_shared);
    }

    public function test_validates_payload(): void
    {
        Config::set('obd.enabled', true);

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/obd/scan-reports', array_merge($this->validPayload(), [
            'adapter_type' => 'USB', // tidak valid
            'profile_match' => 'magic', // tidak valid
            'vin' => 'PENDek', // bukan 17 char
            'atrv_voltage' => 99,
        ]))->assertStatus(422);
    }
}
