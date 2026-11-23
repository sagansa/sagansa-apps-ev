<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Panel\ObdVehicleResource\Pages\CreateObdVehicle;
use App\Models\ObdVehicle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Form admin OBD Vehicle: satu kendaraan garasi = maksimal satu baris per
 * user (2026-11-24). Admin yang mengisi garage_vehicle_id yang sudah dipakai
 * user yang sama mendiapat error validasi — bukan error 500 dari unique index.
 */
class ObdVehicleResourceTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('super_admin', 'web');
        $this->admin = User::factory()->create(['email' => 'admin@admin.com']);
        $this->admin->assignRole('super_admin');
        $this->owner = User::factory()->create();
    }

    public function test_create_kendaraanGarasiSama_untukUserSama_ditolak(): void
    {
        $form = [
            'user_id' => $this->owner->id,
            'garage_vehicle_id' => '0f7d1c2e-1111-4222-8333-444455556666',
            'nickname' => 'IONIQ 5',
        ];

        Livewire::actingAs($this->admin)
            ->test(CreateObdVehicle::class)
            ->fillForm($form)
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::actingAs($this->admin)
            ->test(CreateObdVehicle::class)
            ->fillForm($form + ['nickname' => 'IONIQ 5 lagi'])
            ->call('create')
            ->assertHasFormErrors(['garage_vehicle_id']);

        $this->assertSame(1, ObdVehicle::query()->count());
    }

    public function test_create_kendaraanGarasiSama_userBerbeda_boleh(): void
    {
        $garage = ['garage_vehicle_id' => '0f7d1c2e-1111-4222-8333-444455556666', 'nickname' => 'IONIQ 5'];

        Livewire::actingAs($this->admin)
            ->test(CreateObdVehicle::class)
            ->fillForm($garage + ['user_id' => $this->owner->id])
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::actingAs($this->admin)
            ->test(CreateObdVehicle::class)
            ->fillForm($garage + ['user_id' => User::factory()->create()->id])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(2, ObdVehicle::query()->count());
    }

    public function test_create_tanpaGarasi_bolehLebihDariSatu(): void
    {
        // Baris tanpa garasi (kendaraan tanpa profil katalog) tidak dibatasi.
        Livewire::actingAs($this->admin)
            ->test(CreateObdVehicle::class)
            ->fillForm(['user_id' => $this->owner->id, 'nickname' => 'OBD2 Standar'])
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::actingAs($this->admin)
            ->test(CreateObdVehicle::class)
            ->fillForm(['user_id' => $this->owner->id, 'nickname' => 'OBD2 Standar'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(2, ObdVehicle::query()->count());
    }
}
