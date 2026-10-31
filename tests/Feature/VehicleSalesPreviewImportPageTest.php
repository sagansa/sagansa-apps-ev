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

    /** Tahun periode SELALU mengikuti file terbaru; manual hanya utk file tanpa tahun. */
    public function test_tahun_selalu_mengikuti_file_terbaru(): void
    {
        // Nilai basi '2024' (file sebelumnya) HARUS tertimpa oleh file baru.
        Livewire::actingAs($this->admin)
            ->test(VehicleSalesPreviewImport::class)
            ->set('importYear', '2024')
            ->set('csvFile', UploadedFile::fake()->createWithContent('3_GAIKINDO_wholesales_data_jansep2026.csv', $this->csvContent()))
            ->call('analyze')
            ->assertSet('importYear', '2026');

        // Nama file tanpa tahun → nilai manual dipertahankan.
        Livewire::actingAs($this->admin)
            ->test(VehicleSalesPreviewImport::class)
            ->set('importYear', '2024')
            ->set('csvFile', UploadedFile::fake()->createWithContent('laporan.csv', $this->csvContent()))
            ->call('analyze')
            ->assertSet('importYear', '2024');
    }

    public function test_tombol_connecting_menaikkan_match_otomatis(): void
    {
        $page = Livewire::actingAs($this->admin)
            ->test(VehicleSalesPreviewImport::class)
            ->set('csvFile', UploadedFile::fake()->createWithContent('laporan.csv', $this->csvContent()))
            ->call('analyze');

        // Awal: 3 kombinasi baru (Atto 1 Dynamic, Sealion 8, Agya) — 0 match.
        $page->assertSet('result.summary.matched', 0)
            ->assertSet('result.summary.new', 3);

        // Tautkan keluarga "BYD Sealion 8" (kunci grup squashed) dgn SUV.
        $page->set('newRowForms.BYDSEALION8.category', 'SUV')
            ->set('newRowForms.BYDSEALION8.size', 'Medium')
            ->call('addToConnecting', 'BYDSEALION8');

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

        // Analisis menyegarkan OTOMATIS setelah penautan — Sealion 8 pindah
        // ke Ter-match TANPA klik "Mulai Analisis Laporan" ulang, sehingga
        // gerbang impor permanen langsung memantapkan hitungan bersih.
        $page->assertSet('result.summary.matched', 1)
            ->assertSet('result.summary.new', 2);
    }

    /**
     * Varian trim dari model YANG SAMA dikelompokkan jadi SATU baris
     * keluarga — satu pilihan kategori menautkan semua varian sekaligus
     * (anti inkonsistensi kategori antar varian, mis. 6 varian Creta).
     */
    public function test_varian_satu_keluarga_dikelompokkan_satu_baris(): void
    {
        $csv = implode("\n", [
            'BRAND,TYPE MODEL,CC,TRANS,FUEL,JAN,FEB,MAR,APR,MAY,JUN,JUL,AUG,SEP,OCT,NOV,DEC,TOTAL',
            'BYD,Atto 1 Active 1.5 MT, ,MT,G,7,-, , , , , , 7',
            'BYD,Atto 1 Trend 1.5 MT, ,MT,G,9,-, , , , , , 9',
            'BYD,Atto 1 Prime 1.5 IVT, ,IVT,G,11,-, , , , , , 11',
        ]);

        $page = Livewire::actingAs($this->admin)
            ->test(VehicleSalesPreviewImport::class)
            ->set('csvFile', UploadedFile::fake()->createWithContent('laporan.csv', $csv))
            ->call('analyze');

        // 3 kombinasi BARU, tetapi hanya SATU keluarga form (BYD Atto 1).
        $this->assertSame(3, $page->get('result')['summary']['new']);
        $this->assertSame(['BYDATTO1'], array_keys($page->get('newRowForms')));

        // Satu keputusan kategori → tiga varian tertaut sekali jalan.
        $page->set('newRowForms.BYDATTO1.category', 'SUV')
            ->set('newRowForms.BYDATTO1.size', 'Medium')
            ->call('addToConnecting', 'BYDATTO1');

        $this->assertSame(3, VehicleConnecting::query()
            ->where('raw_gabungan', 'like', 'BYD Atto 1%')->count());

        $model = ModelVehicle::query()
            ->whereHas('brandVehicle', fn ($q) => $q->where('name', 'BYD'))
            ->where('name', 'Atto 1')->first();

        $this->assertNotNull($model, 'semua varian menempel di satu keluarga Atto 1');
        $this->assertSame('SUV', $model->category);
        $this->assertSame('Medium', $model->size_class);
        $this->assertSame(3, $model->typeVehicles()->count(), '3 varian type di bawah satu model');
    }
}
