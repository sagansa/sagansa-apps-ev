<?php

namespace Tests\Feature;

use App\Models\BrandVehicle;
use App\Models\ModelVehicle;
use App\Models\SalesImport;
use App\Models\TypeVehicle;
use App\Models\VehicleConnecting;
use App\Models\VehicleSalesStat;
use App\Services\VehicleSalesStatsRelinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleSalesStatsRelinkerTest extends TestCase
{
    use RefreshDatabase;

    protected function seedCombination(): array
    {
        $brand = BrandVehicle::create(['name' => 'BYD']);
        $benar = ModelVehicle::create(['name' => 'Seal', 'brand_vehicle_id' => $brand->id]);
        $salah = ModelVehicle::create(['name' => 'Seal Performance', 'brand_vehicle_id' => $brand->id]);
        $type = TypeVehicle::create([
            'name' => 'Seal Performance',
            'model_vehicle_id' => $benar->id,
            'type_charger' => [],
            'powertrain' => 'BEV',
        ]);

        VehicleConnecting::create([
            'raw_gabungan' => 'BYD Seal Performance',
            'brand_name' => 'BYD',
            'model_name' => 'Seal',
            'type_name' => 'Seal Performance',
            'brand_vehicle_id' => $brand->id,
            'model_vehicle_id' => $benar->id,
            'type_vehicle_id' => $type->id,
            'powertrain' => 'BEV',
        ]);

        return [$brand, $benar, $salah, $type];
    }

    protected function createStat(array $overrides = []): VehicleSalesStat
    {
        $import = SalesImport::create([
            'file_name' => 'uji.xlsx',
            'source' => 'gaikindo-csv',
            'year' => 2024,
            'status' => 'processed',
        ]);

        return VehicleSalesStat::create([
            'sales_import_id' => $import->id,
            'raw_brand' => 'BYD',
            'raw_model' => 'Seal Performance',
            'year' => 2024,
            'month' => 5,
            'units' => 10,
            'powertrain' => 'BEV',
            ...$overrides,
        ]);
    }

    public function test_sweep_memperbaiki_salah_link_dan_mengisi_null_link(): void
    {
        [$brand, $benar, $salah, $type] = $this->seedCombination();

        // Baris 1: SALAH-link (menempel family terpisah), baris 2: NULL-link.
        $wrong = $this->createStat(['model_vehicle_id' => $salah->id, 'type_vehicle_id' => null]);
        $null = $this->createStat(['month' => 6, 'model_vehicle_id' => null]);

        $report = app(VehicleSalesStatsRelinker::class)->relinkAll();

        $this->assertSame(1, $report['fixed']);
        $this->assertSame(1, $report['filled']);

        $wrong->refresh();
        $null->refresh();

        $this->assertSame($benar->id, $wrong->model_vehicle_id);
        $this->assertSame($type->id, $wrong->type_vehicle_id);
        $this->assertSame($benar->id, $null->model_vehicle_id);
    }

    public function test_sweep_dry_run_tidak_menulis(): void
    {
        [$brand, $benar, $salah, $type] = $this->seedCombination();
        $wrong = $this->createStat(['model_vehicle_id' => $salah->id]);

        $report = app(VehicleSalesStatsRelinker::class)->relinkAll(null, dryRun: true);

        $this->assertSame(1, $report['fixed']);
        $this->assertSame($salah->id, $wrong->refresh()->model_vehicle_id, 'dry-run tidak boleh menulis');
    }

    public function test_relink_kombinasi_tunggal_dari_master(): void
    {
        [$brand, $benar, $salah, $type] = $this->seedCombination();
        $wrong = $this->createStat(['model_vehicle_id' => $salah->id, 'type_vehicle_id' => null]);

        $connecting = VehicleConnecting::query()->where('raw_gabungan', 'BYD Seal Performance')->first();
        $count = app(VehicleSalesStatsRelinker::class)->relinkCombination($connecting);

        $this->assertSame(1, $count);
        $this->assertSame($benar->id, $wrong->refresh()->model_vehicle_id);
        $this->assertSame($type->id, $wrong->refresh()->type_vehicle_id);
    }
}
