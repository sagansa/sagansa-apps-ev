<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Panel\ModelVehicleResource\Pages\ListModelVehicles;
use App\Filament\Resources\Panel\ModelVehicleResource\Pages\ViewModelVehicle;
use App\Models\BrandVehicle;
use App\Models\ModelVehicle;
use App\Models\ObdVehicleProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Informasi profil OBD di Model Vehicles (feedback owner 2026-11-24): kolom
 * "Profil OBD" dulu hanya hitungan ("1") dan halaman detail tanpa bagian
 * profil sama sekali — admin tidak bisa melihat profil mana yang tertaut
 * ke sebuah model, apalagi apakah model itu model utama atau hanya pivot
 * (app versi lama hanya mengenali model utama).
 */
class ModelVehicleObdProfileInfoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Halaman Model Vehicles dijaga policy (permission Filament Shield —
        // PermissionSeeder tidak memuatnya, jadi dibuat langsung di sini).
        $permissions = ['view_any_panel::model::vehicle', 'view_panel::model::vehicle'];
        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        Role::findOrCreate('super_admin', 'web')->givePermissionTo($permissions);
        $admin = User::factory()->create(['email' => 'admin@admin.com']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin);
    }

    /**
     * Profil multi-model gaya produksi: id utama Dolphin, Atto 3 hanya di pivot.
     *
     * @return array{0: ModelVehicle, 1: ModelVehicle, 2: ObdVehicleProfile}
     */
    protected function seedByd(): array
    {
        $byd = BrandVehicle::create(['name' => 'BYD']);
        $dolphin = ModelVehicle::create(['brand_vehicle_id' => $byd->id, 'name' => 'Dolphin']);
        $atto3 = ModelVehicle::create(['brand_vehicle_id' => $byd->id, 'name' => 'Atto 3']);

        $profile = ObdVehicleProfile::create([
            'model_vehicle_id' => $dolphin->id,
            'platform' => 'CS-3fee8f5b',
            'grade' => 'community',
            'version' => '1',
            'status' => 'active',
            'pid_list' => ['metrics' => [['mode' => 34, 'pid' => '0005']]],
            'metadata' => [
                'source' => 'obd:seed-profiles',
                'brand' => 'BYD',
                'model_name' => 'Atto 2 / Atto 3 / Dolphin',
            ],
        ]);
        $profile->modelVehicles()->sync([$dolphin->id, $atto3->id]);

        return [$atto3, $dolphin, $profile];
    }

    public function test_daftar_menampilkan_identitas_profil_tertaut(): void
    {
        [$atto3] = $this->seedByd();

        Livewire::test(ListModelVehicles::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$atto3])
            ->assertSee('CS-3fee8f5b')
            ->assertSee('Atto 2 / Atto 3 / Dolphin');
    }

    public function test_daftar_tanpa_profil_tetap_belum_ada(): void
    {
        $byd = BrandVehicle::create(['name' => 'BYD']);
        $seres = ModelVehicle::create(['brand_vehicle_id' => $byd->id, 'name' => 'Tanpa Profil']);

        Livewire::test(ListModelVehicles::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$seres])
            ->assertSee('belum ada');
    }

    public function test_detail_menampilkan_profil_dan_peran_pivot(): void
    {
        [$atto3] = $this->seedByd();

        Livewire::test(ViewModelVehicle::class, ['record' => $atto3->getKey()])
            ->assertSuccessful()
            ->assertSee('CS-3fee8f5b')
            ->assertSee('Atto 2 / Atto 3 / Dolphin')
            ->assertSee('model pivot');
    }

    public function test_detail_model_utama_ditandai(): void
    {
        [, $dolphin] = $this->seedByd();

        Livewire::test(ViewModelVehicle::class, ['record' => $dolphin->getKey()])
            ->assertSuccessful()
            ->assertSee('CS-3fee8f5b')
            ->assertSee('model utama');
    }

    public function test_detail_tanpa_profil_menunjukkan_placeholder(): void
    {
        $byd = BrandVehicle::create(['name' => 'BYD']);
        $seres = ModelVehicle::create(['brand_vehicle_id' => $byd->id, 'name' => 'Tanpa Profil']);

        Livewire::test(ViewModelVehicle::class, ['record' => $seres->getKey()])
            ->assertSuccessful()
            ->assertSee('Belum ada profil OBD');
    }
}
