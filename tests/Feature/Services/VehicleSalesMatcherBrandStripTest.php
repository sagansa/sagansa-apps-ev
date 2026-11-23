<?php

namespace Tests\Feature\Services;

use App\Models\BrandVehicle;
use App\Models\ModelVehicle;
use App\Services\VehicleSalesMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleSalesMatcherBrandStripTest extends TestCase
{
    use RefreshDatabase;

    public function test_raw_tanpa_prefix_match_katalog_ber_prefix_skor_95(): void
    {
        $mg = BrandVehicle::create(['name' => 'MG']);
        ModelVehicle::create(['brand_vehicle_id' => $mg->id, 'name' => 'MG S5 EV']);

        $preview = app(VehicleSalesMatcher::class)->preview('MG', 'S5 EV');

        $this->assertFalse($preview['model_new']);
        $this->assertSame('MG S5 EV', $preview['model_name']);
        $this->assertSame(95, $preview['match_score']);
    }

    public function test_exact_match_tanpa_prefix_tetap_skor_100(): void
    {
        $wuling = BrandVehicle::create(['name' => 'Wuling']);
        ModelVehicle::create(['brand_vehicle_id' => $wuling->id, 'name' => 'Cloud EV']);

        $preview = app(VehicleSalesMatcher::class)->preview('WULING', 'CLOUD EV');

        $this->assertSame(100, $preview['match_score']);
    }

    public function test_katalog_ber_prefix_brand_panjang_skor_95_menang_atas_containment(): void
    {
        $wuling = BrandVehicle::create(['name' => 'Wuling']);
        ModelVehicle::create(['brand_vehicle_id' => $wuling->id, 'name' => 'Wuling Cloud EV']);

        $preview = app(VehicleSalesMatcher::class)->preview('WULING', 'CLOUD EV');

        $this->assertSame('Wuling Cloud EV', $preview['model_name']);
        $this->assertSame(95, $preview['match_score']);
    }

    public function test_model_brand_lain_tidak_terkontaminasi_strip_brand(): void
    {
        // Brand BYD punya katalog "BYD Atto 3"; raw "ATTO 3" tetap match (95),
        // tapi raw brand "MG" tidak boleh mencuri katalog BYD.
        $byd = BrandVehicle::create(['name' => 'BYD']);
        ModelVehicle::create(['brand_vehicle_id' => $byd->id, 'name' => 'BYD Atto 3']);
        $mg = BrandVehicle::create(['name' => 'MG']);
        ModelVehicle::create(['brand_vehicle_id' => $mg->id, 'name' => 'MG4']);

        $preview = app(VehicleSalesMatcher::class)->preview('MG', 'ATTO 3');

        $this->assertTrue($preview['model_new']);
    }
}
