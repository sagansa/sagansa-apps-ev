<?php

namespace Tests\Feature\Filament;

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
}
