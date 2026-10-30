<?php

namespace Tests\Feature;

use App\Filament\Pages\VehicleSalesPreviewImport;
use App\Models\BrandVehicle;
use App\Models\ModelVehicle;
use App\Models\User;
use App\Models\VehicleConnecting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VehicleSalesPreviewImportPageTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('super_admin', 'web');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super_admin');

        $byd = BrandVehicle::create(['name' => 'BYD']);
        ModelVehicle::create(['name' => 'Atto 1', 'brand_vehicle_id' => $byd->id]);
    }

    private function csvContent(): string
    {
        return implode("\n", [
            'BRAND,TYPE MODEL,CC,TRANS,FUEL,JAN,FEB,MAR,APR,MAY,JUN,JUL,AUG,SEP,OCT,NOV,DEC,TOTAL',
            'BYD,Atto 1 Dynamic,100,AT,BEV,5,-, , , , , , 5',
            'BYD,Sealion 8, ,BEV,7,-, , , , , , 7',
            'TOYOTA,Agya 1.2 G,1200,AT,G,9,-, , , , , , 9',
            'TOTAL,CUMULATIVE, , , , , , , ,16',
        ]);
    }

    public function test_halaman_render_dan_analisis_upload_csv(): void
    {
        Livewire::actingAs($this->admin)
            ->test(VehicleSalesPreviewImport::class)
            ->set('csvFile', UploadedFile::fake()->createWithContent('laporan.csv', $this->csvContent()))
            ->call('analyze')
            ->assertSee('BARU (perlu keputusan)')
            ->assertSee('Sealion 8')
            ->assertSee('model baru di BYD');

        // DRY-RUN: tidak ada yang ditulis ke stats (tidak ada tabel stats yang disentuh).
        $this->assertDatabaseCount('sales_imports', 0);
    }

    public function test_tanpa_file_validasi_ditampilkan(): void
    {
        Livewire::actingAs($this->admin)
            ->test(VehicleSalesPreviewImport::class)
            ->call('analyze')
            ->assertHasErrors(['csvFile' => 'required']);
    }

    public function test_tombol_connecting_menaikkan_match_setelah_analisis_ulang(): void
    {
        $page = Livewire::actingAs($this->admin)
            ->test(VehicleSalesPreviewImport::class)
            ->set('csvFile', UploadedFile::fake()->createWithContent('laporan.csv', $this->csvContent()))
            ->call('analyze');

        // Awal: 3 kombinasi baru (Atto 1 Dynamic, Sealion 8, Agya) — 0 match.
        $page->assertSet('result.summary.matched', 0)
            ->assertSet('result.summary.new', 3);

        // Tautkan "BYD Sealion 8" (index 1) dgn kategori SUV.
        $page->set('newRowForms.1.category', 'SUV')
            ->set('newRowForms.1.size', 'Medium')
            ->call('addToConnecting', 1);

        // CONNECTING terisi nama TERPISAH (bukan string mentah utuh) dgn FK
        // lengkap — prasyarat connectingHit agar analisis ulang mengenali.
        $this->assertDatabaseHas('vehicle_connectings', [
            'raw_gabungan' => 'BYD Sealion 8',
            'brand_name' => 'BYD',
            'model_name' => 'Sealion 8',
            'type_name' => 'Sealion 8',
            'category' => 'SUV',
            'size_class' => 'Medium',
        ]);

        $connecting = VehicleConnecting::query()
            ->where('raw_gabungan', 'BYD Sealion 8')->first();

        $this->assertNotNull($connecting->brand_vehicle_id);
        $this->assertNotNull($connecting->model_vehicle_id);

        // Analisis ulang file yang sama: Sealion 8 pindah ke Ter-match.
        Livewire::actingAs($this->admin)
            ->test(VehicleSalesPreviewImport::class)
            ->set('csvFile', UploadedFile::fake()->createWithContent('laporan.csv', $this->csvContent()))
            ->call('analyze')
            ->assertSet('result.summary.matched', 1)
            ->assertSet('result.summary.new', 2);
    }
}
