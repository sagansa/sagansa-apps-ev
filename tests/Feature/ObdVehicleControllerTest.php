<?php

namespace Tests\Feature;

use App\Models\ModelVehicle;
use App\Models\ObdVehicle;
use App\Models\User;
use Database\Factories\ModelVehicleFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CRUD /api/v1/obd/vehicles — registry kendaraan OBD2 per-user
 * (2026-11-20, pindahan internal storage mobile).
 * Cover: 401 tanpa auth, 404 kill-switch mati, isolasi antar-user,
 * 201 valid tersimpan, update, dan delete.
 */
class ObdVehicleControllerTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(?int $modelVehicleId = null): array
    {
        return [
            'model_vehicle_id' => $modelVehicleId,
            'garage_vehicle_id' => '0f7d1c2e-1111-4222-8333-444455556666',
            'nickname' => 'Hyundai IONIQ 5',
            'brand_name' => 'Hyundai',
            'model_name' => 'IONIQ 5',
            'platform' => 'E-GMP',
            'usable_kwh' => 58.0,
            'efficiency_km_per_kwh' => 5.0,
            'invert_current' => false,
            'metric_config' => [
                ['pid' => ['key' => 'soh', 'mode' => 34, 'pid' => '0105'], 'enabled' => true, 'custom' => false, 'modified' => false],
            ],
            'discovered' => ['speedPid' => null, 'ecuHeaders' => ['7E4'], 'didHits' => ['7E4 220105']],
            'usage' => ['lastOdometerKm' => 63490.0, 'measuredEfficiencyKmPerKwh' => 5.7],
            'sort_order' => 0,
            'last_used_at' => 1737000000000,
        ];
    }

    private function actingAsUser(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_tanpa_auth_401(): void
    {
        $this->getJson('/api/v1/obd/vehicles')->assertStatus(401);
    }

    public function test_kill_switch_mati_404(): void
    {
        Config::set('obd.enabled', false);
        $user = $this->actingAsUser();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/obd/vehicles')->assertStatus(404);
        $this->postJson('/api/v1/obd/vehicles', $this->validPayload())->assertStatus(404);
    }

    public function test_store_dan_index_hanyaMilikUser(): void
    {
        Config::set('obd.enabled', true);
        $user = $this->actingAsUser();

        $model = ModelVehicle::factory()->create(['name' => 'IONIQ 5']);

        $this->postJson('/api/v1/obd/vehicles', $this->validPayload($model->id))
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nickname', 'Hyundai IONIQ 5');

        // User lain TIDAK melihat kendaraan user pertama.
        $other = User::factory()->create();
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/obd/vehicles')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(0, 'data');

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/obd/vehicles')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            // PHP json_encode membuang pecahan .0 — nilai JSON jadi int 58.
            ->assertJsonPath('data.0.usable_kwh', 58)
            ->assertJsonPath('data.0.invert_current', false)
            ->assertJsonPath('data.0.model_vehicle_id', (string) $model->id);
    }

    public function test_update_dan_delete_terisolasi_perUser(): void
    {
        Config::set('obd.enabled', true);
        $user = $this->actingAsUser();
        $created = $user->obdVehicles()->create([
            ...$this->validPayload(),
            'nickname' => 'Lama',
        ]);

        $this->putJson("/api/v1/obd/vehicles/{$created->id}", [
            'nickname' => 'Baru',
            'usable_kwh' => 74.0,
        ])->assertStatus(200)->assertJsonPath('data.nickname', 'Baru');

        // User lain tidak bisa update/delete kendaraan milik user pertama.
        Sanctum::actingAs(User::factory()->create());
        $this->putJson("/api/v1/obd/vehicles/{$created->id}", ['nickname' => 'X'])->assertStatus(404);
        $this->deleteJson("/api/v1/obd/vehicles/{$created->id}")->assertStatus(404);

        Sanctum::actingAs($user);
        $this->deleteJson("/api/v1/obd/vehicles/{$created->id}")->assertStatus(200);
        $this->assertDatabaseCount('obd_vehicles', 0, 'ev');
    }

    public function test_validasi_payload_takWajar_422(): void
    {
        Config::set('obd.enabled', true);
        $this->actingAsUser();

        $this->postJson('/api/v1/obd/vehicles', [
            'usable_kwh' => 500, // di luar 0..200
            'model_vehicle_id' => -3,
        ])->assertStatus(422);
    }
}
