<?php

namespace Tests\Feature\Api;

use App\Models\BrandVehicle;
use App\Models\ModelVehicle;
use App\Models\SalesImport;
use App\Models\TypeVehicle;
use App\Models\VehicleSalesStat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleOptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_options_diperkaya_sales_units_dan_powertrain(): void
    {
        $byd = BrandVehicle::create(['name' => 'BYD']);
        $honda = BrandVehicle::create(['name' => 'Honda']);
        $atto = ModelVehicle::create(['brand_vehicle_id' => $byd->id, 'name' => 'Atto 1']);
        $brio = ModelVehicle::create(['brand_vehicle_id' => $honda->id, 'name' => 'Brio Satya']);
        TypeVehicle::create(['model_vehicle_id' => $atto->id, 'name' => 'Standar', 'powertrain' => 'BEV', 'type_charger' => [], 'battery_capacity' => 51.8]);

        $import = SalesImport::create([
            'file_name' => 'x.xlsx', 'source' => 'gaikindo', 'year' => 2026,
            'period_start' => '2026-01-01', 'period_end' => '2026-07-31', 'status' => 'processed', 'meta' => [],
        ]);
        VehicleSalesStat::create([
            'sales_import_id' => $import->id, 'raw_brand' => 'BYD', 'raw_model' => 'Atto 1 Dynamic',
            'brand_vehicle_id' => $byd->id, 'model_vehicle_id' => $atto->id, 'segment' => 'Sedan', 'powertrain' => 'BEV', 'year' => 2026, 'month' => null, 'units' => 14300,
        ]);

        $this->actingAs(\App\Models\User::factory()->create(), 'sanctum');

        $res = $this->getJson('/api/v1/vehicles/options');
        $res->assertOk();

        $brands = collect($res->json('data.brands'))->keyBy('name');
        $this->assertEquals(14300, $brands['BYD']['sales_units']);
        $this->assertEquals(0, $brands['Honda']['sales_units']);

        $models = collect($res->json('data.models'))->keyBy('name');
        $this->assertEquals(14300, $models['Atto 1']['sales_units']);
        $this->assertArrayNotHasKey('powertrain', $models['Atto 1']);
        $this->assertEquals(0, $models['Brio Satya']['sales_units']);

        // Type tetap terkirim (auto-fill baterai mobile) dan kini membawa powertrain.
        $types = collect($res->json('data.types'));
        $this->assertCount(1, $types);
        $this->assertEquals(51.8, (float) $types->first()['battery_capacity']);
        $this->assertSame('BEV', $types->first()['powertrain']);
    }

    public function test_options_tanpa_data_penjualan_tetap_berfungsi(): void
    {
        BrandVehicle::create(['name' => 'Wuling']);
        $this->actingAs(\App\Models\User::factory()->create(), 'sanctum');

        $res = $this->getJson('/api/v1/vehicles/options');
        $res->assertOk();
        $this->assertEquals(0, $res->json('data.brands.0.sales_units'));
    }

    public function test_options_membawa_obd_support_per_model(): void
    {
        \Illuminate\Support\Facades\Config::set('obd.enabled', true);

        $byd = BrandVehicle::create(['name' => 'BYD']);
        $wuling = BrandVehicle::create(['name' => 'Wuling']);
        $atto = ModelVehicle::create(['brand_vehicle_id' => $byd->id, 'name' => 'Atto 3']);
        $air = ModelVehicle::create(['brand_vehicle_id' => $wuling->id, 'name' => 'Air EV']);

        $user = \App\Models\User::factory()->create();
        $base = [
            'user_id' => $user->id,
            'model_vehicle_id' => $atto->id,
            'adapter_type' => 'BLE',
            'profile_match' => 'universal',
        ];
        \App\Models\ObdScanReport::create($base + ['supported_count' => 3, 'unsupported_count' => 0, 'captured_at' => '2026-11-20 08:00:00']);
        \App\Models\ObdScanReport::create($base + ['supported_count' => 1, 'unsupported_count' => 2, 'captured_at' => '2026-11-21 09:30:00']);

        $this->actingAs($user, 'sanctum');
        $res = $this->getJson('/api/v1/vehicles/options');
        $res->assertOk();

        $models = collect($res->json('data.models'))->keyBy('name');
        $support = $models['Atto 3']['obd_support'];
        $this->assertSame(2, $support['n_reports']);
        $this->assertSame(4, $support['supported']);
        $this->assertSame(2, $support['unsupported']);
        $this->assertStringContainsString('2026-11-21', $support['last_tested_at']);
        // Model tanpa laporan → null (mobile: penanda "belum ada data").
        $this->assertNull($models['Air EV']['obd_support']);
    }

    public function test_options_obd_support_dihilangkan_bila_kill_switch_mati(): void
    {
        \Illuminate\Support\Facades\Config::set('obd.enabled', false);

        $byd = BrandVehicle::create(['name' => 'BYD']);
        $atto = ModelVehicle::create(['brand_vehicle_id' => $byd->id, 'name' => 'Atto 3']);
        $user = \App\Models\User::factory()->create();
        \App\Models\ObdScanReport::create([
            'user_id' => $user->id,
            'model_vehicle_id' => $atto->id,
            'adapter_type' => 'BLE',
            'profile_match' => 'universal',
            'supported_count' => 2,
        ]);

        $this->actingAs($user, 'sanctum');
        $res = $this->getJson('/api/v1/vehicles/options');
        $res->assertOk();

        $this->assertArrayNotHasKey('obd_support', $res->json('data.models.0'));
    }
}
