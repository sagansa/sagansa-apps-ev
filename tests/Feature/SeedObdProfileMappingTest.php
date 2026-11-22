<?php

namespace Tests\Feature;

use App\Models\BrandVehicle;
use App\Models\ModelVehicle;
use App\Models\ObdVehicleProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proteksi mapping admin: re-seed katalog CS tidak boleh menimpa
 * model_vehicle_id yang di-set admin via Filament, dan profil katalog
 * tidak boleh terduplikasi. Profil manual (platform non-CS) tetap
 * di-upsert dengan kunci komposit platform+model.
 */
class SeedObdProfileMappingTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;
    private string $relativePath;

    protected function setUp(): void
    {
        parent::setUp();

        // Seeder me-resolve path via base_path() → katalog uji harus di dalam
        // app dan dikirim sebagai path relatif (lihat SeedCarScannerCatalogTest).
        $this->relativePath = 'storage/framework/testing/obd-mapping-test-' . uniqid();
        $this->dir = base_path($this->relativePath);
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    private function writeProfile(string $hash, array $overrides = []): void
    {
        $json = array_merge([
            'platform' => 'CS-' . substr($hash, 0, 8),
            'brand' => null,
            'modelName' => 'Katalog CS ' . substr($hash, 0, 8),
            'modelVehicleId' => null,
            'vinPattern' => null,
            'yearMin' => null,
            'yearMax' => null,
            'grade' => 'community',
            'version' => 1,
            'metrics' => [
                [
                    'key' => 'soc_bms', 'mode' => 34, 'pid' => '0101', 'ecuHeader' => '7E4',
                    'bytes' => 1, 'formula' => 'A*0.5', 'unit' => '%', 'priority' => 2,
                    'description' => 'Battery SoC BMS', 'minValue' => 0.0, 'maxValue' => 100.0,
                    'category' => 'EV_SPECIFIC', 'byteOffset' => 4, 'udsStatusByte' => false,
                    'signed' => false, 'cellArray' => null, 'cyclePeriod' => 1, 'cyclePhase' => 0,
                ],
            ],
            'initCommands' => [],
            'usableKwh' => null,
            'efficiencyKmPerKwh' => null,
            'dtcEcuHeaders' => [],
        ], $overrides);

        file_put_contents(
            $this->dir . '/' . $hash . '.json',
            json_encode($json, JSON_UNESCAPED_UNICODE),
        );
    }

    private function seedProfiles(array $extraOptions = []): void
    {
        $this->artisan('obd:seed-profiles', array_merge([
            'path' => $this->relativePath,
            '--grade' => 'community',
            '--no-resolve' => true,
        ], $extraOptions));
    }

    public function test_reseed_preserves_admin_model_vehicle_assignment(): void
    {
        $brand = BrandVehicle::create(['name' => 'Hyundai']);
        $model = ModelVehicle::create(['name' => 'IONIQ 5', 'brand_vehicle_id' => $brand->id]);

        $this->writeProfile('aaaabbbbccccdddd');
        $this->seedProfiles();

        $profile = ObdVehicleProfile::where('platform', 'CS-aaaabbbb')->sole();
        $profile->update(['model_vehicle_id' => $model->id]); // simulasi assign admin

        // Re-seed katalog "versi baru" (version 2): assignment bertahan,
        // kolom konten tetap ter-update, tidak ada baris duplikat.
        $this->writeProfile('aaaabbbbccccdddd', ['version' => 2]);
        $this->seedProfiles();

        $this->assertSame(1, ObdVehicleProfile::where('platform', 'CS-aaaabbbb')->count());
        $profile->refresh();
        $this->assertSame($model->id, $profile->model_vehicle_id);
        $this->assertSame('2', $profile->version);
    }

    public function test_explicit_model_option_overrides_assignment(): void
    {
        $brand = BrandVehicle::create(['name' => 'Hyundai']);
        $model = ModelVehicle::create(['name' => 'IONIQ 5', 'brand_vehicle_id' => $brand->id]);

        $this->writeProfile('aaaabbbbccccdddd');
        $this->seedProfiles();

        $profile = ObdVehicleProfile::where('platform', 'CS-aaaabbbb')->sole();

        // Resolve eksplisit (--model) menang atas nilai existing.
        $this->seedProfiles(['--no-resolve' => false, '--model' => 'Hyundai IONIQ 5']);

        $this->assertSame($model->id, $profile->refresh()->model_vehicle_id);
    }

    public function test_manual_platform_profiles_kept_apart_by_model(): void
    {
        $hyundai = BrandVehicle::create(['name' => 'Hyundai']);
        $ioniq = ModelVehicle::create(['name' => 'IONIQ 5', 'brand_vehicle_id' => $hyundai->id]);
        $kia = BrandVehicle::create(['name' => 'Kia']);
        $ev6 = ModelVehicle::create(['name' => 'EV6', 'brand_vehicle_id' => $kia->id]);

        // Platform generik (E-GMP) dipakai dua model — kunci komposit harus
        // menjaga keduanya tetap terpisah.
        $this->writeProfile('xxxxyyyyzzzz1111', ['platform' => 'E-GMP', 'modelName' => 'IONIQ 5 Profile']);
        $this->seedProfiles(['--no-resolve' => false, '--model' => 'Hyundai IONIQ 5']);

        unlink($this->dir . '/xxxxyyyyzzzz1111.json');
        $this->writeProfile('aaaabbbbccccdddd', ['platform' => 'E-GMP', 'modelName' => 'EV6 Profile']);
        $this->seedProfiles(['--no-resolve' => false, '--model' => 'Kia EV6']);

        $this->assertSame(2, ObdVehicleProfile::where('platform', 'E-GMP')->count());
        $this->assertSame(
            $ioniq->id,
            ObdVehicleProfile::where('platform', 'E-GMP')
                ->where('metadata->model_name', 'IONIQ 5 Profile')->sole()->model_vehicle_id,
        );
        $this->assertSame(
            $ev6->id,
            ObdVehicleProfile::where('platform', 'E-GMP')
                ->where('metadata->model_name', 'EV6 Profile')->sole()->model_vehicle_id,
        );
    }

    // --- Regresi insiden 2026-11-22: 392 profil → Seres 3 via substring ---

    private function createSeres3(): ModelVehicle
    {
        $brand = BrandVehicle::create(['name' => 'Seres']);
        // Nama model pendek "3" — pencocokan substring lama mencocokkan SEMUA
        // nama profil yang mengandung karakter "3".
        return ModelVehicle::create(['name' => '3', 'brand_vehicle_id' => $brand->id]);
    }

    public function test_catalog_profile_never_auto_resolves_from_name(): void
    {
        $seres3 = $this->createSeres3();

        // Nama profil mengandung "3" (2013) DAN nama profil exact sama dengan
        // katalog — keduanya TIDAK boleh menghasilkan auto-resolve: mapping
        // profil CS-* adalah tugas admin.
        $this->writeProfile('aaaabbbbccccdddd', [
            'brand' => 'Kia',
            'modelName' => 'Soul PS 1.6 MPI (2013-2018)',
        ]);
        $this->writeProfile('1111222233334444', [
            'brand' => 'Seres',
            'modelName' => '3',
        ]);
        $this->seedProfiles(['--no-resolve' => false]);

        $this->assertNull(
            ObdVehicleProfile::where('platform', 'CS-aaaabbbb')->sole()->model_vehicle_id,
        );
        $this->assertNull(
            ObdVehicleProfile::where('platform', 'CS-11112222')->sole()->model_vehicle_id,
        );
        $this->assertSame(0, ObdVehicleProfile::where('model_vehicle_id', $seres3->id)->count());
    }

    public function test_official_profile_exact_name_resolves(): void
    {
        $brand = BrandVehicle::create(['name' => 'Wuling']);
        $model = ModelVehicle::create(['name' => 'Air EV', 'brand_vehicle_id' => $brand->id]);

        // Profil resmi (non-CS): nama exact "Wuling Air EV" dicocokkan otomatis.
        $this->writeProfile('xxxxyyyyzzzz1111', [
            'platform' => 'BEV',
            'brand' => 'Wuling',
            'modelName' => 'Air EV',
        ]);
        $this->seedProfiles(['--no-resolve' => false]);

        $this->assertSame(
            $model->id,
            ObdVehicleProfile::where('platform', 'BEV')->sole()->model_vehicle_id,
        );
    }

    public function test_official_profile_substring_name_does_not_resolve(): void
    {
        $seres3 = $this->createSeres3();

        // Profil resmi dengan nama panjang yang mengandung "3" — strict exact
        // match tidak boleh meng-cocokkan model "3".
        $this->writeProfile('xxxxyyyyzzzz1111', [
            'platform' => 'BEV',
            'brand' => 'Kia',
            'modelName' => 'Soul PS 1.6 MPI (2013-2018)',
        ]);
        $this->seedProfiles(['--no-resolve' => false]);

        $this->assertNull(
            ObdVehicleProfile::where('platform', 'BEV')->sole()->model_vehicle_id,
        );
        $this->assertSame(0, ObdVehicleProfile::where('model_vehicle_id', $seres3->id)->count());
    }

    public function test_explicit_model_ambiguous_fails_loudly_and_skips(): void
    {
        $brandA = BrandVehicle::create(['name' => 'Seres']);
        $seres3 = ModelVehicle::create(['name' => '3', 'brand_vehicle_id' => $brandA->id]);
        $brandB = BrandVehicle::create(['name' => 'Zeekr']);
        ModelVehicle::create(['name' => '3', 'brand_vehicle_id' => $brandB->id]);

        $this->writeProfile('aaaabbbbccccdddd');
        // --model "3" cocok dua model katalog → gagal jelas (RuntimeException
        // ditangkap seeder → file di-skip TANPA membuat baris), bukan pick acak.
        $this->seedProfiles(['--no-resolve' => false, '--model' => '3']);

        $this->assertSame(0, ObdVehicleProfile::where('platform', 'CS-aaaabbbb')->count());
        $this->assertSame(0, ObdVehicleProfile::where('model_vehicle_id', $seres3->id)->count());
    }
}
