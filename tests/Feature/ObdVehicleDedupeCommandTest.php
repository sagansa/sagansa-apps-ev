<?php

namespace Tests\Feature;

use App\Models\ObdVehicle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `obd:dedupe-vehicles` — pembersihan kendaraan OBD2 ganda di server
 * (2026-11-24): wajib punya dry-run, menulis cadangan JSON kondisi awal
 * sebelum mengubah data, dan bisa dibatasi ke satu user.
 */
class ObdVehicleDedupeCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function seedDuplikat(User $owner): void
    {
        $base = ['nickname' => 'OBD2 Standar', 'brand_name' => 'Universal', 'model_name' => 'OBD2 Standar'];
        $owner->obdVehicles()->create($base + ['last_used_at' => 100]);
        $owner->obdVehicles()->create($base + ['last_used_at' => 900]);
    }

    public function test_dryRun_tidakMengubahData_danTidakMenulisCadangan(): void
    {
        $this->seedDuplikat(User::factory()->create());
        Storage::fake('local');

        $this->artisan('obd:dedupe-vehicles', ['--dry-run' => true])
            ->expectsOutputToContain('DRY-RUN')
            ->assertSuccessful();

        $this->assertSame(2, ObdVehicle::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_jalanNyata_menulisCadanganKondisiAwal_laluMengubah(): void
    {
        $this->seedDuplikat(User::factory()->create());
        Storage::fake('local');

        $this->artisan('obd:dedupe-vehicles')->assertSuccessful();

        $this->assertSame(1, ObdVehicle::query()->count(), 'duplikat digabung');
        $files = Storage::disk('local')->allFiles();
        $this->assertCount(1, $files, 'tepat satu berkas cadangan');
        $this->assertStringStartsWith('obd-vehicles-backup-', $files[0]);

        $backup = json_decode(Storage::disk('local')->get($files[0]), true);
        $this->assertSame('obd-vehicles-backup', $backup['kind']);
        $this->assertCount(2, $backup['rows'], 'kondisi awal SEMUA baris yang disentuh tersimpan');
    }

    public function test_batasiUser_barisUserLainTidakDisentuh(): void
    {
        $sasaran = User::factory()->create();
        $lain = User::factory()->create();
        $this->seedDuplikat($sasaran);
        $this->seedDuplikat($lain);
        Storage::fake('local');

        $this->artisan('obd:dedupe-vehicles', ['--user' => [$sasaran->id]])->assertSuccessful();

        $this->assertSame(1, ObdVehicle::query()->where('user_id', $sasaran->id)->count());
        $this->assertSame(2, ObdVehicle::query()->where('user_id', $lain->id)->count());
    }

    public function test_tanpaDuplikat_tidakMenulisCadangan(): void
    {
        $owner = User::factory()->create();
        $owner->obdVehicles()->create(['nickname' => 'IONIQ 5']);
        Storage::fake('local');

        $this->artisan('obd:dedupe-vehicles')
            ->expectsOutputToContain('Tidak ada')
            ->assertSuccessful();

        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
