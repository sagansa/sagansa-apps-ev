<?php

namespace Tests\Feature;

use App\Models\BrandVehicle;
use App\Models\ModelVehicle;
use App\Models\ObdVehicleProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresi insiden 2026-11-22: bug name-resolve substring di obd:seed-profiles
 * salah-map ratusan profil katalog ke Seres 3. Command obd:audit-profile-mapping
 * mengidentifikasi dan mereset profil katalog yang brand-nya tidak cocok dengan
 * brand model tujuan — seed resmi dan yang brand-nya cocok dipertahankan.
 */
class AuditObdProfileMappingTest extends TestCase
{
    use RefreshDatabase;

    private ModelVehicle $seres3;

    protected function setUp(): void
    {
        parent::setUp();

        $seres = BrandVehicle::create(['name' => 'Seres']);
        $this->seres3 = ModelVehicle::create(['name' => '3', 'brand_vehicle_id' => $seres->id]);
    }

    private function makeProfile(string $hash, string $platform, string $brand, string $name, string $grade = 'community'): ObdVehicleProfile
    {
        $p = ObdVehicleProfile::create([
            'platform' => $platform,
            'grade' => $grade,
            'version' => '1',
            'status' => 'active',
            'pid_list' => ['metrics' => []],
            'metadata' => ['source' => 'obd:seed-profiles', 'brand' => $brand, 'model_name' => $name],
        ]);
        // Simulasi salah-map: link pivot ke Seres 3 + primary.
        $p->modelVehicles()->sync([$this->seres3->id]);
        $p->update(['model_vehicle_id' => $this->seres3->id]);

        return $p;
    }

    public function test_fix_resets_wrong_brand_and_keeps_matching_and_official(): void
    {
        $wrongKia = $this->makeProfile('cs-kia', 'CS-aaaakey1', 'Kia', 'Soul PS 1.6 MPI (2013-2018)');
        $wrongByd = $this->makeProfile('cs-byd', 'CS-aaaakey2', 'BYD', 'Atto 2 / Atto 3 / Dolphin');
        $genuineSeres = $this->makeProfile('cs-seres', 'CS-aaaakey3', 'Seres', 'Seres 3 Pro');
        $officialMapped = $this->makeProfile('cs-official', 'CS-aaaakey4', 'Kia', 'Soul EV 27 kWh', 'official');

        $this->artisan('obd:audit-profile-mapping', ['--fix' => true])->assertSuccessful();

        $this->assertSame([], $wrongKia->refresh()->modelVehicles()->pluck('model_vehicles.id')->all());
        $this->assertNull($wrongKia->model_vehicle_id);
        $this->assertSame([], $wrongByd->refresh()->modelVehicles()->pluck('model_vehicles.id')->all());
        // Brand cocok + seed resmi: keduanya dipertahankan.
        $this->assertSame([$this->seres3->id], $genuineSeres->refresh()->modelVehicles()->pluck('model_vehicles.id')->all());
        $this->assertSame([$this->seres3->id], $officialMapped->refresh()->modelVehicles()->pluck('model_vehicles.id')->all());
    }

    public function test_without_fix_only_reports(): void
    {
        $wrongKia = $this->makeProfile('cs-kia', 'CS-bbbakey1', 'Kia', 'Juke 1.5dci K9K 636 [EN]');

        $this->artisan('obd:audit-profile-mapping')->assertSuccessful();

        $this->assertSame([$this->seres3->id], $wrongKia->refresh()->modelVehicles()->pluck('model_vehicles.id')->all());
    }

    public function test_case_insensitive_brand_match(): void
    {
        // brand metadata beda kapitalisasi → tetap cocok, tidak di-reset.
        $p = $this->makeProfile('cs-caps', 'CS-bbbakey2', 'SERES', 'Seres 3');

        $this->artisan('obd:audit-profile-mapping', ['--fix' => true])->assertSuccessful();

        $this->assertSame([$this->seres3->id], $p->refresh()->modelVehicles()->pluck('model_vehicles.id')->all());
    }
}
