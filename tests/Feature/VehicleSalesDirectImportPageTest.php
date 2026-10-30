<?php

namespace Tests\Feature;

use App\Filament\Pages\VehicleSalesDirectImport;
use App\Models\SalesImport;
use App\Models\User;
use App\Models\VehicleConnecting;
use App\Models\VehicleSalesStat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VehicleSalesDirectImportPageTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('super_admin', 'web');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super_admin');
    }

    /**
     * Worklist mem-prefill powertrain dari FUEL master CONNECTING — dan
     * prefill harus ADA DI STATE Livewire (bukan hanya atribut selected),
     * karena sinkronisasi Livewire menimpa select tanpa state.
     */
    public function test_worklist_prefill_powertrain_dari_fuel_master(): void
    {
        $import = SalesImport::create([
            'file_name' => 'uji.xlsx',
            'source' => 'gaikindo-csv',
            'year' => 2024,
            'status' => 'processed',
        ]);

        VehicleSalesStat::create([
            'sales_import_id' => $import->id,
            'raw_brand' => 'HYUNDAI',
            'raw_model' => 'Stargazer X',
            'year' => 2024,
            'month' => 3,
            'units' => 42,
            // Stats bilang ICE, tapi FUEL master HEV → FUEL harus menang
            // (powertrain mengikuti FUEL file — kebijakan 2026-10-30).
            'powertrain' => 'ICE',
        ]);

        VehicleConnecting::create([
            'raw_gabungan' => 'HYUNDAI Stargazer X',
            'brand_name' => 'HYUNDAI',
            'model_name' => 'Stargazer X',
            'type_name' => 'Stargazer X',
            'fuel' => 'HEV',
        ]);

        Livewire::actingAs($this->admin)
            ->test(VehicleSalesDirectImport::class)
            ->assertSee('HYUNDAI Stargazer X')
            ->assertSee('fuel: HEV')
            ->assertSet('forms.HYUNDAISTARGAZERX.powertrain', 'HEV');
    }

    /** Badge navigasi menghitung grup pending (squashed key), bukan baris. */
    public function test_badge_menghitung_grup_pending(): void
    {
        $import = SalesImport::create([
            'file_name' => 'uji.xlsx',
            'source' => 'gaikindo-csv',
            'year' => 2024,
            'status' => 'processed',
        ]);

        foreach ([3, 4] as $m) {
            VehicleSalesStat::create([
                'sales_import_id' => $import->id,
                'raw_brand' => 'HYUNDAI',
                'raw_model' => 'Stargazer X',
                'year' => 2024,
                'month' => $m,
                'units' => 10,
            ]);
        }

        $this->assertSame('1', VehicleSalesDirectImport::getNavigationBadge());
    }
}
