<?php

namespace Tests\Feature\Services;

use App\Models\BrandVehicle;
use App\Models\ModelVehicle;
use App\Models\ObdVehicleProfile;
use App\Models\SalesImport;
use App\Models\TypeVehicle;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleConnecting;
use App\Models\VehicleNameMapping;
use App\Models\VehicleSalesStat;
use App\Services\ModelVehicleMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelVehicleMergeServiceTest extends TestCase
{
    use RefreshDatabase;

    protected BrandVehicle $mg;
    protected ModelVehicle $keeper;   // "MG S5 EV"
    protected ModelVehicle $dup;      // "S5 EV"

    protected function setUp(): void
    {
        parent::setUp();

        $this->mg = BrandVehicle::create(['name' => 'MG']);
        $this->keeper = ModelVehicle::create([
            'brand_vehicle_id' => $this->mg->id,
            'name' => 'MG S5 EV',
            'category' => 'SUV',
            'size_class' => 'Medium',
        ]);
        $this->dup = ModelVehicle::create([
            'brand_vehicle_id' => $this->mg->id,
            'name' => 'S5 EV',
        ]);
    }

    protected function makeType(ModelVehicle $model, string $name, ?float $battery = null): TypeVehicle
    {
        return TypeVehicle::create([
            'model_vehicle_id' => $model->id,
            'name' => $name,
            'type_charger' => [],
            'battery_capacity' => $battery,
        ]);
    }

    protected function makeVehicle(ModelVehicle $model, ?TypeVehicle $type = null, bool $trashed = false): Vehicle
    {
        $vehicle = Vehicle::create([
            'user_id' => User::factory()->create()->id,
            'brand_vehicle_id' => $model->brand_vehicle_id,
            'model_vehicle_id' => $model->id,
            'type_vehicle_id' => $type?->id,
            'status' => 1,
        ]);
        if ($trashed) {
            $vehicle->delete();
        }

        return $vehicle;
    }

    protected function makeStat(ModelVehicle $model, string $rawBrand = 'MG', string $rawModel = 'S5 EV', int $units = 5): VehicleSalesStat
    {
        $import = SalesImport::create([
            'file_name' => 'test.xlsx',
            'source' => 'gaikindo',
            'year' => 2026,
            'status' => 'processed',
            'meta' => [],
        ]);

        return VehicleSalesStat::create([
            'sales_import_id' => $import->id,
            'raw_brand' => $rawBrand,
            'raw_model' => $rawModel,
            'brand_vehicle_id' => $model->brand_vehicle_id,
            'model_vehicle_id' => $model->id,
            'powertrain' => 'BEV',
            'year' => 2026,
            'month' => 3,
            'units' => $units,
        ]);
    }

    public function test_dry_run_menghitung_dampak_tanpa_menulis(): void
    {
        $keeperType = $this->makeType($this->keeper, 'Standar'); // battery null
        $dupStandar = $this->makeType($this->dup, 'Standar', 61.4);
        $dupLuxury = $this->makeType($this->dup, 'Luxury');
        $this->makeVehicle($this->dup, $dupStandar);
        $this->makeVehicle($this->dup);
        $this->makeVehicle($this->dup, $dupStandar, trashed: true);
        $this->makeVehicle($this->keeper, $keeperType);
        $this->makeStat($this->dup);
        VehicleConnecting::create([
            'raw_gabungan' => 'MG S5 EV Comfort',
            'brand_vehicle_id' => $this->mg->id,
            'model_vehicle_id' => $this->dup->id,
            'type_vehicle_id' => null,
        ]);

        $report = app(ModelVehicleMergeService::class)->dryRun($this->keeper, $this->dup);

        $this->assertTrue($report->dryRun);
        $this->assertSame(3, $report->vehiclesMoved); // termasuk 1 soft-deleted
        $this->assertCount(1, $report->typeMerges);   // Standar gabung
        $this->assertSame(2, $report->typeMerges[0]['vehicles_remapped']);
        $this->assertTrue($report->typeMerges[0]['battery_filled']);
        $this->assertCount(1, $report->typesMoved);   // Luxury pindah utuh
        $this->assertSame(1, $report->statsRowsMoved);
        $this->assertSame(1, $report->connectingsMoved);
        $this->assertCount(1, $report->mappingsCreated); // usulan (MG, S5 EV)
        $this->assertSame('S5 EV', $report->mappingsCreated[0]['raw_model']);

        // Tidak ada yang berubah di DB
        $this->assertDatabaseHas('vehicles', ['model_vehicle_id' => $this->dup->id]);
        $this->assertDatabaseHas('model_vehicles', ['id' => $this->dup->id]);
        $this->assertDatabaseHas('type_vehicles', ['id' => $dupStandar->id, 'model_vehicle_id' => $this->dup->id]);
    }

    public function test_guard_model_sama_ditolak(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(ModelVehicleMergeService::class)->dryRun($this->keeper, $this->keeper);
    }

    public function test_guard_lintas_brand_ditolak(): void
    {
        $byd = BrandVehicle::create(['name' => 'BYD']);
        $bydModel = ModelVehicle::create(['brand_vehicle_id' => $byd->id, 'name' => 'Atto 3']);

        $this->expectException(\InvalidArgumentException::class);
        app(ModelVehicleMergeService::class)->dryRun($this->keeper, $bydModel);
    }
}
