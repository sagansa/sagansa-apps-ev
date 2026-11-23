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
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    protected function makeStat(ModelVehicle $model, string $rawBrand = 'MG', string $rawModel = 'S5 EV', int $units = 5, ?TypeVehicle $type = null): VehicleSalesStat
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
            'type_vehicle_id' => $type?->id,
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
        $this->makeStat($this->dup, type: $dupStandar);
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
        $this->assertSame(1, $report->typeMerges[0]['stats_type_remapped']);
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

    public function test_merge_guard_lintas_brand_ditolak_tanpa_perubahan(): void
    {
        $keeperVehicle = $this->makeVehicle($this->keeper);
        $byd = BrandVehicle::create(['name' => 'BYD']);
        $bydModel = ModelVehicle::create(['brand_vehicle_id' => $byd->id, 'name' => 'Atto 3']);

        try {
            app(ModelVehicleMergeService::class)->merge($this->keeper, $bydModel);
            $this->fail('Merge lintas brand harus ditolak.');
        } catch (\InvalidArgumentException) {
        }

        // Guard melempar sebelum transaksi dibuka — DB harus tetap utuh.
        $this->assertDatabaseHas('model_vehicles', ['id' => $this->dup->id]);
        $this->assertDatabaseHas('model_vehicles', ['id' => $bydModel->id]);
        $this->assertDatabaseHas('vehicles', [
            'id' => $keeperVehicle->id,
            'model_vehicle_id' => $this->keeper->id,
        ]);
    }

    public function test_merge_pindahkan_semua_relasi_dan_hapus_duplikat(): void
    {
        $keeperStandar = $this->makeType($this->keeper, 'Standar'); // battery null
        $dupStandar = $this->makeType($this->dup, 'Standar', 61.4);
        $dupLuxury = $this->makeType($this->dup, 'Luxury');

        $v1 = $this->makeVehicle($this->dup, $dupStandar);
        $v2 = $this->makeVehicle($this->dup);
        $v3 = $this->makeVehicle($this->dup, $dupStandar, trashed: true);
        $vKeeper = $this->makeVehicle($this->keeper, $keeperStandar);
        $this->makeStat($this->dup, type: $dupStandar);
        $connecting = VehicleConnecting::create([
            'raw_gabungan' => 'MG S5 EV Comfort',
            'brand_vehicle_id' => $this->mg->id,
            'model_vehicle_id' => $this->dup->id,
            'type_vehicle_id' => $dupStandar->id,
        ]);

        // Pivot: profil ter-link ke dup DAN keeper → baris dup harus dihapus.
        $profile = ObdVehicleProfile::create([
            'model_vehicle_id' => $this->dup->id,
            'platform' => 'CS-test0001',
            'grade' => 'community',
            'version' => '1',
            'status' => 'active',
            'pid_list' => ['metrics' => []],
            'metadata' => ['source' => 'test'],
        ]);
        DB::table('obd_profile_model_vehicles')->insert([
            ['obd_vehicle_profile_id' => $profile->id, 'model_vehicle_id' => $this->dup->id],
            ['obd_vehicle_profile_id' => $profile->id, 'model_vehicle_id' => $this->keeper->id],
        ]);

        // Mapping yang menunjuk dup (unique pair belum dipegang keeper).
        VehicleNameMapping::create([
            'raw_brand' => 'MG', 'raw_model' => 'S5 EV',
            'raw_brand_norm' => 'MG', 'raw_model_norm' => 'S5 EV',
            'brand_vehicle_id' => $this->mg->id,
            'model_vehicle_id' => $this->dup->id,
            'type_vehicle_id' => null,
        ]);

        $report = app(ModelVehicleMergeService::class)->merge($this->keeper, $this->dup);

        $this->assertFalse($report->dryRun);
        $this->assertDatabaseMissing('model_vehicles', ['id' => $this->dup->id]);
        $this->assertDatabaseMissing('type_vehicles', ['id' => $dupStandar->id]);      // gabung → hapus
        $this->assertDatabaseHas('type_vehicles', ['id' => $dupLuxury->id, 'model_vehicle_id' => $this->keeper->id]); // pindah utuh
        $this->assertSame($keeperStandar->id, $v1->fresh()->type_vehicle_id);          // remap varian gabung
        $this->assertSame(61.4, $keeperStandar->fresh()->battery_capacity);            // fill baterai dari dup
        $this->assertSame($this->keeper->id, $v1->fresh()->model_vehicle_id);
        $this->assertSame($this->keeper->id, $v2->fresh()->model_vehicle_id);
        $this->assertSame($this->keeper->id, Vehicle::withTrashed()->find($v3->id)->model_vehicle_id);
        $this->assertSame($this->keeper->id, VehicleSalesStat::where('raw_model', 'S5 EV')->first()->model_vehicle_id);
        $this->assertSame($keeperStandar->id, VehicleSalesStat::where('raw_model', 'S5 EV')->first()->type_vehicle_id); // remap type hint
        $this->assertSame($this->keeper->id, $connecting->fresh()->model_vehicle_id);
        $this->assertSame($keeperStandar->id, $connecting->fresh()->type_vehicle_id); // remap type hint (sebelum hapus type, FK nullOnDelete)
        $this->assertSame($this->keeper->id, $profile->fresh()->model_vehicle_id);
        $this->assertSame(1, DB::table('obd_profile_model_vehicles')->where('obd_vehicle_profile_id', $profile->id)->count());
        $this->assertDatabaseHas('vehicle_name_mappings', [
            'raw_brand_norm' => 'MG', 'raw_model_norm' => 'S5 EV', 'model_vehicle_id' => $this->keeper->id,
        ]);
        // Auto-mapping dari raw stats → import berikutnya kena LAYER 1.
        $this->assertDatabaseHas('vehicle_name_mappings', [
            'raw_model_norm' => 'S5 EV', 'model_vehicle_id' => $this->keeper->id,
        ]);
        $this->assertSame(3, $report->vehiclesMoved);
        $this->assertSame(1, $report->pivotRowsDeleted);
    }

    public function test_mapping_unik_keeper_menang_saat_bentrok(): void
    {
        // Unique index (ada sejak migrasi pendirian tabel 2026_08_30) membuat
        // norm-pair ganda mustahil di produksi — drop index utk mensimulasikan
        // anomali constraint demi mencabang jalur defensif superseded-delete
        // (tidak terjangkau di produksi; dipertahankan sebagai proteksi).
        Schema::table('vehicle_name_mappings', function (Blueprint $table) {
            $table->dropUnique('vehicle_name_mappings_raw_unique');
        });

        VehicleNameMapping::create([
            'raw_brand' => 'MG', 'raw_model' => 'S5 EV',
            'raw_brand_norm' => 'MG', 'raw_model_norm' => 'S5 EV',
            'brand_vehicle_id' => $this->mg->id,
            'model_vehicle_id' => $this->dup->id,
            'type_vehicle_id' => null,
        ]);
        VehicleNameMapping::create([
            'raw_brand' => 'MG', 'raw_model' => 'S5 EV',
            'raw_brand_norm' => 'MG', 'raw_model_norm' => 'S5 EV',
            'brand_vehicle_id' => $this->mg->id,
            'model_vehicle_id' => $this->keeper->id,
            'type_vehicle_id' => null,
        ]);

        app(ModelVehicleMergeService::class)->merge($this->keeper, $this->dup);

        // Baris dup dihapus; keputusan keeper tetap satu-satunya.
        $rows = VehicleNameMapping::where('raw_model_norm', 'S5 EV')->get();
        $this->assertCount(1, $rows);
        $this->assertSame($this->keeper->id, $rows[0]->model_vehicle_id);
    }

    public function test_rename_keeper_opsional(): void
    {
        app(ModelVehicleMergeService::class)->merge($this->keeper, $this->dup, 'S5 EV');

        $this->assertDatabaseHas('model_vehicles', ['id' => $this->keeper->id, 'name' => 'S5 EV']);
    }

    public function test_audit_candidates_menemukan_pasangan_duplikat(): void
    {
        $wuling = BrandVehicle::create(['name' => 'Wuling']);
        $cloud = ModelVehicle::create(['brand_vehicle_id' => $wuling->id, 'name' => 'Cloud']);
        $cloudEv = ModelVehicle::create(['brand_vehicle_id' => $wuling->id, 'name' => 'Cloud EV']);
        $byd = BrandVehicle::create(['name' => 'BYD']);
        ModelVehicle::create(['brand_vehicle_id' => $byd->id, 'name' => 'Atto 3']); // tidak berpasangan

        // Keeper S5 EV "lebih kaya": kategori + size terisi.
        $this->keeper->update(['category' => 'SUV', 'size_class' => 'Medium']);

        $pairs = app(ModelVehicleMergeService::class)->auditCandidates();

        $this->assertCount(2, $pairs);

        $mgPair = collect($pairs)->first(fn ($p) => $p['brand'] === 'MG');
        $this->assertNotNull($mgPair);
        $this->assertSame($this->keeper->id, $mgPair['keeper_id']); // metadata lengkap menang
        $this->assertSame($this->keeper->id, min($mgPair['a']['model']->id, $mgPair['b']['model']->id));

        $wulingPair = collect($pairs)->first(fn ($p) => $p['brand'] === 'Wuling');
        $this->assertNotNull($wulingPair);
        $ids = [$wulingPair['a']['model']->id, $wulingPair['b']['model']->id];
        $this->assertEqualsCanonicalizing([$cloud->id, $cloudEv->id], $ids);
    }

    public function test_audit_candidates_tidak_menggandakan_pasangan(): void
    {
        $pairs = app(ModelVehicleMergeService::class)->auditCandidates();

        $this->assertCount(1, $pairs); // satu pasangan MG S5 EV ↔ S5 EV, bukan dua
    }

    public function test_audit_tier_keeper_ketat_obd_dan_user_mengalahkan_metadata(): void
    {
        // (a) 1 link OBD di sisi dup mengalahkan keeper bermetadata lengkap.
        $profile = \App\Models\ObdVehicleProfile::create([
            'model_vehicle_id' => $this->dup->id,
            'platform' => 'CS-tier0001',
            'grade' => 'community',
            'version' => '1',
            'status' => 'active',
            'pid_list' => ['metrics' => []],
            'metadata' => ['source' => 'test'],
        ]);
        DB::table('obd_profile_model_vehicles')->insert([
            'obd_vehicle_profile_id' => $profile->id,
            'model_vehicle_id' => $this->dup->id,
        ]);
        $this->keeper->update(['category' => 'SUV', 'size_class' => 'Medium']);
        $pairs = app(ModelVehicleMergeService::class)->auditCandidates();
        $mgPair = collect($pairs)->first(fn ($p) => $p['brand'] === 'MG');
        $this->assertSame($this->dup->id, $mgPair['keeper_id']); // tier OBD menang

        // (b) Tanpa OBD: 1 user di sisi keeper mengalahkan metadata dup maksimal.
        DB::table('obd_profile_model_vehicles')->where('obd_vehicle_profile_id', $profile->id)->delete();
        $this->makeVehicle($this->keeper);
        $pairs = app(ModelVehicleMergeService::class)->auditCandidates();
        $mgPair = collect($pairs)->first(fn ($p) => $p['brand'] === 'MG');
        $this->assertSame($this->keeper->id, $mgPair['keeper_id']); // tier user menang
    }
}
