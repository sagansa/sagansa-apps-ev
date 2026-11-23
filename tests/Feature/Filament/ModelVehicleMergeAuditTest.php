<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ModelVehicleMergeAudit;
use App\Models\BrandVehicle;
use App\Models\ModelVehicle;
use App\Models\TypeVehicle;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ModelVehicleMergeAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('super_admin', 'web');
        $admin = User::factory()->create(['email' => 'admin@admin.com']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin);
    }

    protected function seedPair(): array
    {
        $mg = BrandVehicle::create(['name' => 'MG']);
        $keeper = ModelVehicle::create(['brand_vehicle_id' => $mg->id, 'name' => 'MG S5 EV', 'category' => 'SUV']);
        $dup = ModelVehicle::create(['brand_vehicle_id' => $mg->id, 'name' => 'S5 EV']);
        TypeVehicle::create(['model_vehicle_id' => $dup->id, 'name' => 'Standar', 'type_charger' => [], 'battery_capacity' => 61.4]);
        Vehicle::create([
            'user_id' => User::factory()->create()->id,
            'brand_vehicle_id' => $mg->id,
            'model_vehicle_id' => $dup->id,
            'status' => 1,
        ]);

        return [$mg, $keeper, $dup];
    }

    public function test_halaman_menampilkan_pasangan_kandidat(): void
    {
        $this->seedPair();

        Livewire::test(ModelVehicleMergeAudit::class)
            ->assertSee('S5 EV')
            ->assertSee('MG S5 EV');
    }

    public function test_preview_tidak_menulis_ke_database(): void
    {
        [$mg, $keeper, $dup] = $this->seedPair();

        Livewire::test(ModelVehicleMergeAudit::class)
            ->call('previewMerge', 0)
            ->assertSet('previewIndex', 0);

        $this->assertDatabaseHas('model_vehicles', ['id' => $dup->id]);
        $this->assertDatabaseHas('vehicles', ['model_vehicle_id' => $dup->id]);
    }

    public function test_merge_mengeksekusi_dan_daftar_muat_ulang(): void
    {
        [$mg, $keeper, $dup] = $this->seedPair();

        // Saran keeper default (tier ketat) memilih 'S5 EV' (punya 1 user);
        // admin menukar keeper ke 'MG S5 EV' via radio sebelum merge.
        Livewire::test(ModelVehicleMergeAudit::class)
            ->set('keeperChoice.0', $keeper->id)
            ->call('mergePair', 0);

        $this->assertDatabaseMissing('model_vehicles', ['id' => $dup->id]);
        $this->assertDatabaseHas('vehicles', ['model_vehicle_id' => $keeper->id]);
    }
}
