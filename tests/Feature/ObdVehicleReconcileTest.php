<?php

namespace Tests\Feature;

use App\Models\BrandVehicle;
use App\Models\ModelVehicle;
use App\Models\ObdVehicle;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Obd\VehicleRegistryReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Rekonsiliasi registry OBD (spec penyatuan 2026-11-24 §4): satu kendaraan
 * garasi = satu baris obd_vehicles — buat / perbarui / adopsi / hapus.
 */
class ObdVehicleReconcileTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private BrandVehicle $byd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->byd = BrandVehicle::create(['name' => 'BYD']);
    }

    private function garageVehicle(string $name = 'Atto 3', ?int $modelId = null): Vehicle
    {
        $model = $modelId === null
            ? ModelVehicle::create(['name' => $name, 'brand_vehicle_id' => $this->byd->id])
            : ModelVehicle::find($modelId);

        return Vehicle::create([
            'user_id' => $this->user->id,
            'brand_vehicle_id' => $this->byd->id,
            'model_vehicle_id' => $model->id,
            'status' => 1,
        ]);
    }

    public function test_buat_baris_untuk_garasi_tanpa_baris(): void
    {
        $g = $this->garageVehicle();

        app(VehicleRegistryReconciler::class)->reconcileFor($this->user->id);

        $row = ObdVehicle::where('user_id', $this->user->id)->sole();
        $this->assertSame((string) $g->id, $row->garage_vehicle_id);
        $this->assertSame('BYD Atto 3', $row->nickname);
        $this->assertSame($g->model_vehicle_id, (int) $row->model_vehicle_id);
    }

    public function test_perbarui_identitas_tanpa_merusak_data_obd(): void
    {
        $model = ModelVehicle::create(['name' => 'Nama Lama', 'brand_vehicle_id' => $this->byd->id]);
        $g = $this->garageVehicle('Nama Lama', $model->id);
        ObdVehicle::create([
            'user_id' => $this->user->id,
            'garage_vehicle_id' => (string) $g->id,
            'nickname' => 'Panggilan User',
            'discovered' => ['ecuHeaders' => ['7E7']],
        ]);
        $model->update(['name' => 'Nama Baru']);

        app(VehicleRegistryReconciler::class)->reconcileFor($this->user->id);

        $row = ObdVehicle::where('user_id', $this->user->id)->sole();
        $this->assertSame('BYD Nama Baru', $row->nickname, 'identitas mengikuti garasi');
        $this->assertSame(['ecuHeaders' => ['7E7']], $row->discovered, 'data OBD tidak disentuh');
    }

    public function test_adopsi_baris_lama_tanpa_garage_id_model_tunggal(): void
    {
        $model = ModelVehicle::create(['name' => 'IONIQ 5', 'brand_vehicle_id' => BrandVehicle::create(['name' => 'Hyundai'])->id]);
        $g = Vehicle::create(['user_id' => $this->user->id, 'brand_vehicle_id' => $model->brand_vehicle_id, 'model_vehicle_id' => $model->id, 'status' => 1]);
        $legacy = ObdVehicle::create([
            'user_id' => $this->user->id,
            'nickname' => 'IONIQ 5',
            'model_vehicle_id' => $model->id,
            'discovered' => ['didHits' => ['7D1 220104']],
        ]);

        app(VehicleRegistryReconciler::class)->reconcileFor($this->user->id);

        $this->assertSame((string) $g->id, $legacy->refresh()->garage_vehicle_id, 'baris lama di-adopt, bukan di-duplikasi');
        $this->assertSame(1, ObdVehicle::where('user_id', $this->user->id)->count());
    }

    public function test_hapus_baris_yatim_dan_lama_tak_teradopsi(): void
    {
        $g = $this->garageVehicle();
        ObdVehicle::create(['user_id' => $this->user->id, 'garage_vehicle_id' => '00000000-0000-0000-0000-000000000000', 'nickname' => 'Yatim']);
        ObdVehicle::create(['user_id' => $this->user->id, 'nickname' => 'Legacy Tak Cocok']);

        app(VehicleRegistryReconciler::class)->reconcileFor($this->user->id);

        $rows = ObdVehicle::where('user_id', $this->user->id)->get();
        $this->assertSame(1, $rows->count());
        $this->assertSame((string) $g->id, $rows->first()->garage_vehicle_id);
    }

    public function test_idempoten(): void
    {
        $this->garageVehicle();
        $svc = app(VehicleRegistryReconciler::class);
        $svc->reconcileFor($this->user->id);
        $svc->reconcileFor($this->user->id);

        $this->assertSame(1, ObdVehicle::where('user_id', $this->user->id)->count());
    }

    public function test_isolasi_antar_user(): void
    {
        $g = $this->garageVehicle();
        $other = User::factory()->create();
        ObdVehicle::create(['user_id' => $other->id, 'nickname' => 'Milik Orang', 'model_vehicle_id' => $g->model_vehicle_id]);

        app(VehicleRegistryReconciler::class)->reconcileFor($this->user->id);

        $this->assertSame(1, ObdVehicle::where('user_id', $this->user->id)->count());
        $this->assertSame(1, ObdVehicle::where('user_id', $other->id)->count(), 'baris user lain tidak disentuh');
    }

    public function test_get_obd_vehicles_merekonsiliasi(): void
    {
        Sanctum::actingAs($this->user);
        Config::set('obd.enabled', true);
        $g = $this->garageVehicle();
        $this->assertSame(0, ObdVehicle::where('user_id', $this->user->id)->count());

        $this->getJson('/api/v1/obd/vehicles')
            ->assertSuccessful()
            ->assertJsonPath('success', true);

        $row = ObdVehicle::where('user_id', $this->user->id)->sole();
        $this->assertSame((string) $g->id, $row->garage_vehicle_id);
    }
}
