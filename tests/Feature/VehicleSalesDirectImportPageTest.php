<?php

namespace Tests\Feature;

use App\Filament\Pages\VehicleSalesDirectImport;
use App\Models\ModelVehicle;
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

    /** Bulk: terapkan kategori massal lalu tautkan semua terpilih sekaligus. */
    public function test_bulk_kategori_massal_dan_tautkan_semua(): void
    {
        $import = SalesImport::create([
            'file_name' => 'uji.xlsx',
            'source' => 'gaikindo-csv',
            'year' => 2024,
            'status' => 'processed',
        ]);

        $combos = [
            ['HINO', 'FG 235 JD', 'D'],
            ['FUSO', 'FM 517', 'D'],
            ['LEXUS', 'RZ 500e', 'BEV'],
        ];

        foreach ($combos as $i => [$brand, $model, $fuel]) {
            VehicleSalesStat::create([
                'sales_import_id' => $import->id,
                'raw_brand' => $brand,
                'raw_model' => $model,
                'year' => 2024,
                'month' => 5,
                'units' => 5 + $i,
                'powertrain' => $fuel === 'BEV' ? 'BEV' : 'ICE',
            ]);

            VehicleConnecting::create([
                'raw_gabungan' => "{$brand} {$model}",
                'brand_name' => $brand,
                'model_name' => $model,
                'type_name' => $model,
                'fuel' => $fuel,
            ]);
        }

        $page = Livewire::actingAs($this->admin)
            ->test(VehicleSalesDirectImport::class);

        // Kategori massal utk 2 truk (diesel): LEXUS tidak ikut terpilih.
        $page->set('selected.HINOFG235JD', true)
            ->set('selected.FUSOFM517', true)
            ->set('selected.LEXUSRZ500E', false)
            ->set('bulkCategory', 'Truk Ringan')
            ->call('applyBulkCategory')
            ->call('bulkLink');

        // 2 truk tertaut + ter-back-link; LEXUS tetap pending.
        $this->assertSame(2, VehicleSalesStat::whereNotNull('model_vehicle_id')->count());
        $this->assertSame(1, VehicleSalesStat::whereNull('model_vehicle_id')->count());
        $this->assertSame('1', VehicleSalesDirectImport::getNavigationBadge());

        // Bulk kedua: sisa diisi kategorinya manual per baris.
        $page->set('selected.LEXUSRZ500E', true)
            ->set('forms.LEXUSRZ500E.category', 'SUV')
            ->call('bulkLink');

        $this->assertSame(0, VehicleSalesStat::whereNull('model_vehicle_id')->count());
        $this->assertNull(VehicleSalesDirectImport::getNavigationBadge());
    }

    /**
     * Satu keluarga dgn beberapa type (FUEL beda: G → ICE, HEV → HEV)
     * cukup SATU keputusan kategori — semua type dibuat di bawah model
     * yang sama dgn powertrain masing-masing dari FUEL.
     */
    public function test_satu_keluarga_multi_type_satu_keputusan_kategori(): void
    {
        $import = SalesImport::create([
            'file_name' => 'uji.xlsx',
            'source' => 'gaikindo-csv',
            'year' => 2024,
            'status' => 'processed',
        ]);

        $types = [
            ['Stargazer X', 'G', 'ICE'],
            ['Stargazer X Hev', 'HEV', 'HEV'],
        ];

        foreach ($types as [$model, $fuel, $pt]) {
            VehicleSalesStat::create([
                'sales_import_id' => $import->id,
                'raw_brand' => 'HYUNDAI',
                'raw_model' => $model,
                'year' => 2024,
                'month' => 5,
                'units' => 10,
                'powertrain' => $pt,
            ]);

            VehicleConnecting::create([
                'raw_gabungan' => "HYUNDAI {$model}",
                'brand_name' => 'HYUNDAI',
                'model_name' => 'Stargazer X',
                'type_name' => $model,
                'fuel' => $fuel,
            ]);
        }

        $page = Livewire::actingAs($this->admin)
            ->test(VehicleSalesDirectImport::class);

        // Kedua type tergabung dalam SATU keluarga "HYUNDAI Stargazer X"
        // (keys forms = keys grup pending).
        $formKeys = array_keys($page->get('forms'));
        $this->assertCount(1, $formKeys, 'kedua type harus tergabung satu keluarga');
        $gKey = $formKeys[0];

        // Satu keputusan kategori → kedua type tertaut sekaligus.
        $page->set("forms.{$gKey}.category", 'MPV')
            ->set("forms.{$gKey}.size", 'Medium')
            ->call('linkCombo', $gKey);

        $model = ModelVehicle::query()
            ->whereHas('brandVehicle', fn ($q) => $q->where('name', 'HYUNDAI'))
            ->where('name', 'Stargazer X')->first();

        $this->assertNotNull($model, 'keluarga Stargazer X dibuat di katalog');
        $this->assertSame('MPV', $model->category);
        $this->assertSame('Medium', $model->size_class);
        $this->assertSame(2, $model->typeVehicles()->count(), 'kedua type di bawah satu model');

        // Powertrain per type mengikuti FUEL: G→ICE, HEV→HEV.
        $this->assertSame(['HEV', 'ICE'],
            $model->typeVehicles()->orderBy('name')->pluck('powertrain')->unique()->sort()->values()->all());

        $this->assertSame(0, VehicleSalesStat::whereNull('model_vehicle_id')->count());
    }
}
