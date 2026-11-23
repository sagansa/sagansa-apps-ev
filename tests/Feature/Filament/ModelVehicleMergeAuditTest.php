<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ModelVehicleMergeAudit;
use App\Models\BrandVehicle;
use App\Models\ModelVehicle;
use App\Models\TypeVehicle;
use App\Models\User;
use App\Models\Vehicle;
use Filament\Notifications\Livewire\Notifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
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

    /**
     * Snapshot seluruh notifikasi yang terkirim selama test (mengosongkan
     * antrean sesi). assertNotified() bawaan Filament mem-pull antrean itu di
     * tiap pemanggilan, jadi hanya assertion pertama yang bermakna — mekanisme
     * yang sama dipakai di sini untuk menguji daftar lengkapnya sekaligus.
     *
     * @return Collection<int, \Filament\Notifications\Notification>
     */
    protected function pullSentNotifications(): Collection
    {
        $component = new Notifications;
        $component->mount();

        return $component->notifications->values();
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
        // Tanpa rename: hanya notifikasi sukses, tanpa peringatan bentrok nama.
        $this->assertSame(['success'], $this->pullSentNotifications()->map(fn ($n) => $n->getStatus())->all());
    }

    public function test_preview_mereset_rename_dari_pasangan_sebelumnya(): void
    {
        $this->seedPair();

        // Nama yang tertinggal di input preview pasangan lain tidak boleh
        // ikut terbawa saat admin mem-preview pasangan ini.
        Livewire::test(ModelVehicleMergeAudit::class)
            ->set('renameTo', 'Nama Bocor')
            ->call('previewMerge', 0)
            ->assertSet('previewIndex', 0)
            ->assertSet('renameTo', '');
    }

    public function test_merge_gagal_menampilkan_notifikasi_bahaya_tanpa_perubahan(): void
    {
        // Anomali: varian dup "Standar" dipakai kendaraan model KETIGA → guard
        // pre-delete melempar RuntimeException. Halaman harus menampilkannya
        // sebagai notifikasi bahaya (bukan error 500) dan DB tetap utuh.
        [$mg, $keeper, $dup] = $this->seedPair();
        TypeVehicle::create(['model_vehicle_id' => $keeper->id, 'name' => 'Standar', 'type_charger' => []]);
        $dupStandar = TypeVehicle::where('model_vehicle_id', $dup->id)->firstOrFail();
        $other = ModelVehicle::create(['brand_vehicle_id' => $mg->id, 'name' => 'MG ZS EV']);
        $vOther = Vehicle::create([
            'user_id' => User::factory()->create()->id,
            'brand_vehicle_id' => $mg->id,
            'model_vehicle_id' => $other->id,
            'type_vehicle_id' => $dupStandar->id,
            'status' => 1,
        ]);

        Livewire::test(ModelVehicleMergeAudit::class)
            ->call('previewMerge', 0)
            ->set('keeperChoice.0', $keeper->id)
            ->call('mergePair', 0)
            ->assertSet('previewIndex', 0); // state halaman tidak berubah

        // Tepat satu notifikasi: bahaya "Merge gagal" (tanpa "Merge selesai"),
        // berisi pesan penyebab dari service agar admin tahu apa yang harus dibereskan.
        $sent = $this->pullSentNotifications();
        $this->assertSame(['Merge gagal'], $sent->map(fn ($n) => $n->getTitle())->all());
        $this->assertSame('danger', $sent->first()->getStatus());
        $this->assertStringContainsString('masih dipakai kendaraan di luar model', (string) $sent->first()->getBody());

        $this->assertDatabaseHas('model_vehicles', ['id' => $dup->id]);
        $this->assertDatabaseHas('vehicles', ['model_vehicle_id' => $dup->id]);
        // Kendaraan model ketiga tetap ada dan masih menunjuk variannya.
        $this->assertDatabaseHas('vehicles', [
            'id' => $vOther->id,
            'model_vehicle_id' => $other->id,
            'type_vehicle_id' => $dupStandar->id,
        ]);
    }

    public function test_rename_ke_nama_model_lain_memberi_peringatan(): void
    {
        [$mg, $keeper, $dup] = $this->seedPair();
        $other = ModelVehicle::create(['brand_vehicle_id' => $mg->id, 'name' => 'MG 4']);

        // Merge tetap jalan (notifikasi sukses), lalu admin diperingatkan bahwa
        // nama barunya sama dengan model lain di brand yang sama — beda
        // kapitalisasi pun tetap terdeteksi.
        Livewire::test(ModelVehicleMergeAudit::class)
            ->set('keeperChoice.0', $keeper->id)
            ->set('renameTo', 'mg 4')
            ->call('mergePair', 0);

        $sent = $this->pullSentNotifications();
        $this->assertSame(['success', 'warning'], $sent->map(fn ($n) => $n->getStatus())->all());
        $this->assertSame('Nama baru bentrok dengan model lain', $sent->last()->getTitle());
        $this->assertStringContainsString(sprintf('#%d MG 4', $other->id), (string) $sent->last()->getBody());

        $this->assertDatabaseHas('model_vehicles', ['id' => $keeper->id, 'name' => 'mg 4']);
        $this->assertDatabaseMissing('model_vehicles', ['id' => $dup->id]);
    }
}
