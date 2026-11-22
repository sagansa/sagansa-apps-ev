<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Panel\ObdVehicleProfileResource\Pages\EditObdProfile;
use App\Filament\Resources\Panel\ObdVehicleProfileResource\Pages\ListObdProfiles;
use App\Models\BrandVehicle;
use App\Models\ModelVehicle;
use App\Models\ObdVehicleProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ObdVehicleProfileResourceTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('super_admin', 'web');
        $this->admin = User::factory()->create(['email' => 'admin@admin.com']);
        $this->admin->assignRole('super_admin');
    }

    /** Bentuk baris sama dengan hasil seeder katalog CS (generik, 2 metrik). */
    protected function makeProfile(array $overrides = []): ObdVehicleProfile
    {
        return ObdVehicleProfile::create(array_merge([
            'model_vehicle_id' => null,
            'platform' => 'CS-aaaabbbb',
            'vin_pattern' => null,
            'year_min' => null,
            'year_max' => null,
            'grade' => 'community',
            'version' => '1',
            'status' => 'active',
            'pid_list' => [
                'metrics' => [
                    ['mode' => 34, 'pid' => '0101', 'description' => 'Battery SoC BMS'],
                    ['mode' => 34, 'pid' => '0102', 'description' => 'Cell Voltage'],
                ],
            ],
            'metadata' => [
                'source' => 'obd:seed-profiles',
                'brand' => null,
                'model_name' => 'Katalog CS aaaabbbb',
            ],
        ], $overrides));
    }

    public function test_list_page_renders_with_rows(): void
    {
        $profile = $this->makeProfile();

        Livewire::actingAs($this->admin)
            ->test(ListObdProfiles::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$profile]);
    }

    public function test_mapped_filter_shows_only_matching_rows(): void
    {
        $brand = BrandVehicle::create(['name' => 'Hyundai']);
        $model = ModelVehicle::create(['name' => 'IONIQ 5', 'brand_vehicle_id' => $brand->id]);

        $mapped = $this->makeProfile(['platform' => 'CS-mapped1', 'model_vehicle_id' => $model->id]);
        $unmapped = $this->makeProfile(['platform' => 'CS-plain22']);

        $component = Livewire::actingAs($this->admin)->test(ListObdProfiles::class);

        $component->filterTable('mapped', true)
            ->assertCanSeeTableRecords([$mapped])
            ->assertCanNotSeeTableRecords([$unmapped]);

        $component->filterTable('mapped', false)
            ->assertCanSeeTableRecords([$unmapped])
            ->assertCanNotSeeTableRecords([$mapped]);
    }

    public function test_bulk_assign_sets_model_vehicle_id(): void
    {
        $brand = BrandVehicle::create(['name' => 'Hyundai']);
        $model = ModelVehicle::create(['name' => 'IONIQ 5', 'brand_vehicle_id' => $brand->id]);

        $a = $this->makeProfile(['platform' => 'CS-aaaa1111']);
        $b = $this->makeProfile(['platform' => 'CS-bbbb2222']);

        Livewire::actingAs($this->admin)
            ->test(ListObdProfiles::class)
            ->callTableBulkAction('assignToModel', [$a, $b], [
                'brand_vehicle_id' => $brand->id,
                'model_vehicle_id' => $model->id,
            ]);

        $this->assertSame($model->id, $a->refresh()->model_vehicle_id);
        $this->assertSame($model->id, $b->refresh()->model_vehicle_id);
    }

    public function test_bulk_assign_requires_model_selection(): void
    {
        $a = $this->makeProfile(['platform' => 'CS-cccc3333']);

        Livewire::actingAs($this->admin)
            ->test(ListObdProfiles::class)
            ->callTableBulkAction('assignToModel', [$a], [])
            ->assertHasActionErrors();

        $this->assertNull($a->refresh()->model_vehicle_id);
    }

    public function test_edit_page_assigns_model_via_two_tier_select(): void
    {
        $hyundai = BrandVehicle::create(['name' => 'Hyundai']);
        $ioniq = ModelVehicle::create(['name' => 'IONIQ 5', 'brand_vehicle_id' => $hyundai->id]);
        $kia = BrandVehicle::create(['name' => 'Kia']);
        $ev6 = ModelVehicle::create(['name' => 'EV6', 'brand_vehicle_id' => $kia->id]);

        // Catatan: platform & pid_list dibentuk valid untuk form edit —
        // platform CS-* tidak ada di opsi select dan pid_list katalog berbentuk
        // JSON utuh (bukan array item repeater). Itu masalah pre-existing form,
        // di luar cakupan test ini yang menguji select dua tingkat.
        $profile = $this->makeProfile([
            'platform' => 'BEV',
            'model_vehicle_id' => $ioniq->id,
            'pid_list' => [
                ['mode' => 34, 'pid' => '0101', 'description' => 'Battery SoC BMS',
                    'formula' => 'A*0.5', 'unit' => '%', 'category' => 'general'],
            ],
        ]);

        Livewire::actingAs($this->admin)
            ->test(EditObdProfile::class, ['record' => $profile->getRouteKey()])
            ->assertSuccessful()
            // Ganti brand → model lama di-reset oleh afterStateUpdated
            ->fillForm(['brand_vehicle_id' => $kia->id])
            ->fillForm(['model_vehicle_id' => $ev6->id])
            ->call('save')
            ->assertHasNoActionErrors();

        $this->assertSame($ev6->id, $profile->refresh()->model_vehicle_id);
    }
}
