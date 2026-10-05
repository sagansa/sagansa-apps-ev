<?php

namespace Tests\Feature\Api;

use App\Models\BrandVehicle;
use App\Models\ModelVehicle;
use App\Models\SalesImport;
use App\Models\VehicleSalesStat;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VehicleMarketTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Revisi 2, poin 4: semua endpoint auth required
        $user = \App\Models\User::factory()->create();
        Sanctum::actingAs($user, abilities: ['*']);

        $byd = BrandVehicle::create(['name' => 'BYD']);
        $wuling = BrandVehicle::create(['name' => 'Wuling']);
        $atto = ModelVehicle::create(['brand_vehicle_id' => $byd->id, 'name' => 'Atto 1']);
        $binguo = ModelVehicle::create(['brand_vehicle_id' => $wuling->id, 'name' => 'Binguo']);

        // 2025: tahun penuh (12 bulan berisi).
        $import2025 = SalesImport::create([
            'file_name' => '2025.xlsx', 'source' => 'gaikindo', 'year' => 2025,
            'period_start' => '2025-01-01', 'period_end' => '2025-12-31', 'status' => 'processed',
            'meta' => ['official' => ['grand' => ['label' => 'DOMESTIC SALES TOTAL', 'total' => 800000, 'months' => []]]],
        ]);
        foreach (range(1, 12) as $m) {
            VehicleSalesStat::create([
                'sales_import_id' => $import2025->id, 'raw_brand' => 'BYD', 'raw_model' => 'Atto 1',
                'brand_vehicle_id' => $byd->id, 'model_vehicle_id' => $atto->id, 'segment' => 'Sedan', 'powertrain' => 'BEV', 'year' => 2025, 'month' => $m, 'units' => 1000,
            ]);
        }
        VehicleSalesStat::create([
            'sales_import_id' => $import2025->id, 'raw_brand' => 'LAIN', 'raw_model' => 'Lain-lain',
            'segment' => null, 'powertrain' => 'ICE', 'year' => 2025, 'month' => 1, 'units' => 50000,
        ]);
        VehicleSalesStat::create([
            'sales_import_id' => $import2025->id, 'raw_brand' => 'BYD', 'raw_model' => 'Atto 1',
            'brand_vehicle_id' => $byd->id, 'model_vehicle_id' => $atto->id, 'segment' => 'Sedan', 'powertrain' => 'BEV', 'year' => 2025, 'month' => null, 'units' => 12000,
        ]);
        VehicleSalesStat::create([
            'sales_import_id' => $import2025->id, 'raw_brand' => 'LAIN', 'raw_model' => 'Lain-lain',
            'segment' => null, 'powertrain' => 'ICE', 'year' => 2025, 'month' => null, 'units' => 50000,
        ]);

        // 2026: partial (3 bulan), official meta grand total + bulanan.
        $import2026 = SalesImport::create([
            'file_name' => '2026.xlsx', 'source' => 'gaikindo', 'year' => 2026,
            'period_start' => '2026-01-01', 'period_end' => '2026-03-31', 'status' => 'processed',
            'meta' => ['official' => ['grand' => [
                'label' => 'DOMESTIC SALES TOTAL', 'total' => 90000,
                'months' => [1 => 30000, 2 => 30000, 3 => 30000],
            ]]],
        ]);
        foreach (range(1, 3) as $m) {
            VehicleSalesStat::create([
                'sales_import_id' => $import2026->id, 'raw_brand' => 'WULING', 'raw_model' => 'Binguo',
                'brand_vehicle_id' => $wuling->id, 'model_vehicle_id' => $binguo->id, 'segment' => 'LCGC', 'powertrain' => 'BEV', 'year' => 2026, 'month' => $m, 'units' => 2000,
            ]);
        }
        VehicleSalesStat::create([
            'sales_import_id' => $import2026->id, 'raw_brand' => 'WULING', 'raw_model' => 'Binguo',
            'brand_vehicle_id' => $wuling->id, 'model_vehicle_id' => $binguo->id, 'segment' => 'LCGC', 'powertrain' => 'BEV', 'year' => 2026, 'month' => null, 'units' => 6000,
        ]);
        VehicleSalesStat::create([
            'sales_import_id' => $import2026->id, 'raw_brand' => 'TOYOTA', 'raw_model' => 'Avanza',
            'segment' => 'MPV', 'powertrain' => 'ICE', 'year' => 2026, 'month' => null, 'units' => 20000,
        ]);
    }

    public function test_summary_publik_tanpa_login(): void
    {
        $res = $this->getJson('/api/v1/vehicle-market/summary');
        $res->assertOk()->assertJsonPath('success', true);

        $years = collect($res->json('data.years'))->keyBy('year');
        $this->assertEquals(12000, $years[2025]['bev_units']);
        // bev_share memakai total resmi (12000/800000).
        $this->assertEquals(0.015, $years[2025]['bev_share']);
        $this->assertTrue($years[2025]['is_full_year']);
        $this->assertFalse($years[2026]['is_full_year']);

        // Tahun terbaru 2026 partial → YoY growth tidak dihitung.
        $this->assertNull($res->json('data.latest.bev_yoy_growth'));
        $this->assertEquals(2026, $res->json('data.latest.year'));
        $this->assertEquals(6000, $res->json('data.latest.bev_units'));
    }

    public function test_trend_bulanan_dengan_market_total_resmi(): void
    {
        $res = $this->getJson('/api/v1/vehicle-market/trend?year=2026');
        $res->assertOk();

        $months = collect($res->json('data.months'));
        $this->assertCount(3, $months);
        $this->assertEquals(2000, $months->firstWhere('month', 1)['bev_units']);
        $this->assertEquals(30000, $months->firstWhere('month', 1)['market_total']);
    }

    public function test_top_default_bev(): void
    {
        $res = $this->getJson('/api/v1/vehicle-market/top?year=2026');
        $res->assertOk();

        $this->assertEquals('BEV', $res->json('data.powertrain'));
        $models = collect($res->json('data.models'));
        $this->assertSame('Binguo', $models->first()['model']);
        $this->assertEquals(6000, $models->first()['units']);
    }

    public function test_reimport_tahun_sama_menggantikan_angka_import_lama(): void
    {
        // Import kedua (lebih baru) utk tahun 2025: angka BEV beda.
        $reimport = SalesImport::create([
            'file_name' => '2025-clean.xlsx', 'source' => 'gaikindo', 'year' => 2025,
            'period_start' => '2025-01-01', 'period_end' => '2025-12-31', 'status' => 'processed',
            'meta' => ['official' => ['grand' => ['label' => 'DOMESTIC SALES TOTAL', 'total' => 900000, 'months' => []]]],
        ]);
        VehicleSalesStat::create([
            'sales_import_id' => $reimport->id, 'raw_brand' => 'BYD', 'raw_model' => 'Atto 1',
            'brand_vehicle_id' => null, 'model_vehicle_id' => null, 'segment' => 'Sedan', 'powertrain' => 'BEV', 'year' => 2025, 'month' => null, 'units' => 47100,
        ]);

        // Hanya angka dari import terbaru yang dihitung — 12.000 (import lama)
        // tidak ikut disum.
        $years = collect($this->getJson('/api/v1/vehicle-market/summary')->json('data.years'))->keyBy('year');
        $this->assertEquals(47100, $years[2025]['bev_units']);

        // Trend tahun lama juga hanya dari import terbaru (bulan tidak ada lagi).
        $trendMonths = collect($this->getJson('/api/v1/vehicle-market/trend?year=2025')->json('data.months'));
        $this->assertCount(0, $trendMonths);
    }

    public function test_trend_tanpa_year_pakai_tahun_data_bulanan_terbaru(): void
    {
        // Hapus import 2026 → tahun bulanan terbaru tinggal 2025. Default
        // TIDAK boleh now()->year (tahun berjalan kosong = chart rusak).
        SalesImport::where('year', 2026)->delete();

        $res = $this->getJson('/api/v1/vehicle-market/trend');
        $res->assertOk();
        $this->assertEquals(2025, $res->json('data.year'));
        $this->assertCount(12, $res->json('data.months'));
    }

    public function test_trend_filter_brand_dan_model(): void
    {
        $res = $this->getJson('/api/v1/vehicle-market/trend?year=2026&brand=WULING&model=Binguo');
        $res->assertOk();

        $months = collect($res->json('data.months'));
        $this->assertCount(3, $months);
        $this->assertEquals(2000, $months->firstWhere('month', 1)['bev_units']);
        // market_total saat terfilter = hasil parse (2000), BUKAN total resmi
        // nasional (30000) — angka resmi hanya berlaku utk scope nasional.
        $this->assertEquals(2000, $months->firstWhere('month', 1)['market_total']);
    }

    public function test_trend_default_year_ikut_brand_filter(): void
    {
        // BYD hanya punya baris bulanan di 2025 → default = 2025.
        $res = $this->getJson('/api/v1/vehicle-market/trend?brand=BYD');
        $res->assertOk();
        $this->assertEquals(2025, $res->json('data.year'));
        $this->assertCount(12, $res->json('data.months'));
    }

    public function test_top_tanpa_year_pakai_tahun_punya_data_model(): void
    {
        // 2026 punya baris level model → default 2026 (perilaku lama max(year)).
        $res = $this->getJson('/api/v1/vehicle-market/top');
        $res->assertOk();
        $this->assertEquals(2026, $res->json('data.year'));
        $this->assertNotSame([], $res->json('data.models'));
    }

    public function test_top_tanpa_year_fallback_bila_tahun_baru_tanpa_model(): void
    {
        // Skenario produksi: 2026 baru punya rekap, belum ada baris level
        // model → default harus 2025 (yang punya model), BUKAN 2026 kosong.
        VehicleSalesStat::where('year', 2026)->whereNotNull('model_vehicle_id')->delete();

        $res = $this->getJson('/api/v1/vehicle-market/top');
        $res->assertOk();
        $this->assertEquals(2025, $res->json('data.year'));
        $this->assertNotSame([], $res->json('data.models'));
    }

    public function test_top_filter_brand(): void
    {
        $res = $this->getJson('/api/v1/vehicle-market/top?year=2025&brand=BYD');
        $res->assertOk();

        $models = collect($res->json('data.models'));
        $this->assertCount(1, $models);
        $this->assertSame('Atto 1', $models->first()['model']);

        $brands = collect($res->json('data.brands'));
        $this->assertCount(1, $brands);
        $this->assertSame('BYD', $brands->first()['brand']);
    }

    public function test_catalog_peta_brand_ke_model(): void
    {
        $res = $this->getJson('/api/v1/vehicle-market/catalog?year=2025');
        $res->assertOk()->assertJsonPath('data.year', 2025);

        $brands = collect($res->json('data.brands'))->keyBy('brand');
        $this->assertEquals(12000, $brands['BYD']['units']);
        $this->assertSame('Atto 1', $brands['BYD']['models'][0]['model']);
        // Katalog BEV/PHEV-only: brand ICE murni (LAIN) tidak masuk; satu-
        // satunya brand EV tersisa otomatis jadi urutan pertama.
        $this->assertArrayNotHasKey('LAIN', $brands);
        $this->assertSame('BYD', $res->json('data.brands.0.brand'));
    }

    public function test_catalog_tanpa_year_pakai_tahun_data_terbaru(): void
    {
        $res = $this->getJson('/api/v1/vehicle-market/catalog');
        $res->assertOk();
        $this->assertEquals(2026, $res->json('data.year'));

        // Brand kini dari KATALOG (nama katalog, bukan raw) — "Wuling".
        $brands = collect($res->json('data.brands'))->keyBy('brand');
        $this->assertEquals(6000, $brands['Wuling']['units']);
        // Katalog BEV/PHEV-only: TOYOTA (ICE murni) tidak ikut katalog.
        $this->assertArrayNotHasKey('TOYOTA', $brands);
        $this->assertArrayNotHasKey('WULING', $brands);
    }

    public function test_top_all_years_mengagregasikan_unit_dan_kembalikan_year_null(): void
    {
        $res = $this->getJson('/api/v1/vehicle-market/top?year=all');
        $res->assertOk();
        $this->assertNull($res->json('data.year'));
        $this->assertSame('BEV', $res->json('data.powertrain'));

        $brands = collect($res->json('data.brands'))->keyBy('brand');
        $this->assertEquals(12000, $brands['BYD']['units']);
        $this->assertEquals(6000, $brands['WULING']['units']);

        $models = collect($res->json('data.models'))->keyBy('model');
        $this->assertEquals(12000, $models['Atto 1']['units']);
        $this->assertEquals(6000, $models['Binguo']['units']);
    }

    public function test_catalog_all_years_mengagregasikan_unit_dan_mengurutkan_brand_desc(): void
    {
        $res = $this->getJson('/api/v1/vehicle-market/catalog?year=all');
        $res->assertOk();
        $this->assertNull($res->json('data.year'));

        $brands = collect($res->json('data.brands'));
        $this->assertCount(2, $brands);
        // BYD (12000) harus sebelum Wuling (6000)
        $this->assertSame('BYD', $brands[0]['brand']);
        $this->assertEquals(12000, $brands[0]['units']);
        $this->assertSame('Wuling', $brands[1]['brand']);
        $this->assertEquals(6000, $brands[1]['units']);
    }

    public function test_top_year_invalid_fallback_ke_tahun_terbaru(): void
    {
        $res = $this->getJson('/api/v1/vehicle-market/top?year=foo');
        $res->assertOk();
        $this->assertEquals(2026, $res->json('data.year'));
    }

    public function test_top_dan_catalog_menyertakan_logo_url_brand(): void
    {
        // Set logo pada BrandVehicle BYD
        $byd = \App\Models\BrandVehicle::where('name', 'BYD')->first();
        if ($byd) {
            $byd->update(['image' => 'images/brand/byd.png']);
            app(\App\Services\VehicleMarketService::class)->flush();
        }

        $topRes = $this->getJson('/api/v1/vehicle-market/top?year=2025&powertrain=BEV');
        $topRes->assertOk();
        $bydTop = collect($topRes->json('data.brands'))->firstWhere('brand', 'BYD');
        $this->assertNotNull($bydTop);
        $this->assertSame('/storage/images/brand/byd.png', $bydTop['logo_url']);

        $bydModel = collect($topRes->json('data.models'))->firstWhere('brand', 'BYD');
        $this->assertNotNull($bydModel);
        $this->assertSame('/storage/images/brand/byd.png', $bydModel['brand_logo_url']);

        $catRes = $this->getJson('/api/v1/vehicle-market/catalog?year=2025');
        $catRes->assertOk();
        $bydCat = collect($catRes->json('data.brands'))->firstWhere('brand', 'BYD');
        $this->assertNotNull($bydCat);
        $this->assertSame('/storage/images/brand/byd.png', $bydCat['logo_url']);

        $histRes = $this->getJson('/api/v1/vehicle-market/model-history?brand=BYD&model=Atto%201');
        $histRes->assertOk();
        $this->assertSame('/storage/images/brand/byd.png', $histRes->json('data.brand_logo_url'));
    }

    public function test_model_terpisah_per_powertrain_dengan_image_per_tipe(): void
    {
        $toyota = \App\Models\BrandVehicle::create(['name' => 'Toyota']);
        $alphard = \App\Models\ModelVehicle::create(['brand_vehicle_id' => $toyota->id, 'name' => 'Alphard']);
        $hevType = \App\Models\TypeVehicle::create([
            'model_vehicle_id' => $alphard->id, 'name' => 'Alphard 2.5 HEV', 'type_charger' => [], 'image' => 'images/type/alphard-hev.webp',
        ]);
        $iceType = \App\Models\TypeVehicle::create([
            'model_vehicle_id' => $alphard->id, 'name' => 'Alphard 2.4 G', 'type_charger' => [], 'image' => 'images/type/alphard-ice.webp',
        ]);
        $import = SalesImport::create([
            'file_name' => '2026-hev.xlsx', 'source' => 'gaikindo', 'year' => 2026, 'status' => 'processed', 'meta' => [],
        ]);
        foreach ([[$hevType, 'HEV', 300], [$iceType, 'ICE', 200]] as [$type, $pt, $units]) {
            VehicleSalesStat::create([
                'sales_import_id' => $import->id, 'raw_brand' => 'TOYOTA', 'raw_model' => 'Alphard',
                'brand_vehicle_id' => $toyota->id, 'model_vehicle_id' => $alphard->id,
                'type_vehicle_id' => $type->id, 'powertrain' => $pt, 'year' => 2026, 'month' => null, 'units' => $units,
            ]);
        }
        app(\App\Services\VehicleMarketService::class)->flush();

        $models = collect($this->getJson('/api/v1/vehicle-market/top?year=2026&powertrain=ALL')->json('data.models'))
            ->filter(fn ($m) => $m['model'] === 'Alphard')->values();

        $this->assertCount(2, $models); // HEV dan ICE terpisah, tidak tergabung
        $hev = $models->firstWhere('powertrain', 'HEV');
        $ice = $models->firstWhere('powertrain', 'ICE');
        $this->assertNotNull($hev);
        $this->assertNotNull($ice);
        $this->assertEquals(300, $hev['units']);
        $this->assertEquals(200, $ice['units']);
        $this->assertSame('/storage/images/type/alphard-hev.webp', $hev['image_url']);
        $this->assertSame('/storage/images/type/alphard-ice.webp', $ice['image_url']);
    }

    /** Revisi 2, poin 7: forecast default scope BEV; param powertrain mengubah scope. */
    public function test_forecast_default_bev_dan_ikut_param_powertrain(): void
    {
        // Tambah HEV bulanan 100×3 ke import 2026 yang SAMA (import baru akan
        // menggeser latestImports dan membuang baris BEV bulanannya).
        $import2026 = SalesImport::where('year', 2026)->first();
        foreach (range(1, 3) as $m) {
            VehicleSalesStat::create([
                'sales_import_id' => $import2026->id, 'raw_brand' => 'TOYOTA', 'raw_model' => 'Alphard',
                'powertrain' => 'HEV', 'year' => 2026, 'month' => $m, 'units' => 100,
            ]);
        }
        app(\App\Services\VehicleMarketService::class)->flush();

        // Default: BEV. YTD BEV = 2000×3, satu-satunya tahun penuh (2025, BEV
        // 1000×12) memberi w_m = 1/12 → Σw(Jan-Mar) = 0.25 → 6000/0.25 = 24000.
        $res = $this->getJson('/api/v1/vehicle-market/trend?year=2026');
        $res->assertOk();
        $fc = $res->json('data.forecast');
        $this->assertSame('BEV', $fc['powertrain']);
        $this->assertSame('seasonal', $fc['method']);
        $this->assertEquals(24000, $fc['projected_total']);

        // Param powertrain=ALL: YTD = 6300 (BEV 2000×3 + HEV 100×3) → 6300/0.25 = 25200.
        $fcAll = $this->getJson('/api/v1/vehicle-market/trend?year=2026&powertrain=ALL')->json('data.forecast');
        $this->assertSame('ALL', $fcAll['powertrain']);
        $this->assertEquals(25200, $fcAll['projected_total']);
    }

    /**
     * Revisi lanjutan: model-history menyertakan rincian BULANAN tahun
     * terakhir yang berdata (chart "Bulanan" di detail model).
     */
    public function test_model_history_menyertakan_rincian_bulanan(): void
    {
        $toyota = \App\Models\BrandVehicle::create(['name' => 'Toyota']);
        $alphard = \App\Models\ModelVehicle::create(['brand_vehicle_id' => $toyota->id, 'name' => 'Alphard']);
        $alphardHev = \App\Models\TypeVehicle::create(['model_vehicle_id' => $alphard->id, 'name' => 'Alphard HEV', 'type_charger' => []]);
        $import = SalesImport::create([
            'file_name' => '2026-bulanan.xlsx', 'source' => 'gaikindo', 'year' => 2026, 'status' => 'processed', 'meta' => [],
        ]);
        foreach ([[1, 'BEV', 100], [2, 'BEV', 150], [3, 'BEV', 200], [3, 'PHEV', 50]] as [$m, $pt, $units]) {
            VehicleSalesStat::create([
                'sales_import_id' => $import->id, 'raw_brand' => 'TOYOTA', 'raw_model' => 'Alphard',
                'brand_vehicle_id' => $toyota->id, 'model_vehicle_id' => $alphard->id,
                'powertrain' => $pt, 'year' => 2026, 'month' => $m, 'units' => $units,
            ]);
        }
        // Baris bulanan ber-type: Jan 30 + Mar 40 utk Alphard HEV — drill-down
        // bulanan brand → model → type di detail EV Market. Scope sama dgn
        // total type ($base): BEV/PHEV saja.
        foreach ([[1, 30], [3, 40]] as [$m, $units]) {
            VehicleSalesStat::create([
                'sales_import_id' => $import->id, 'raw_brand' => 'TOYOTA', 'raw_model' => 'Alphard',
                'brand_vehicle_id' => $toyota->id, 'model_vehicle_id' => $alphard->id,
                'type_vehicle_id' => $alphardHev->id,
                'powertrain' => 'BEV', 'year' => 2026, 'month' => $m, 'units' => $units,
            ]);
        }
        VehicleSalesStat::create([
            'sales_import_id' => $import->id, 'raw_brand' => 'TOYOTA', 'raw_model' => 'Alphard',
            'brand_vehicle_id' => $toyota->id, 'model_vehicle_id' => $alphard->id,
            'powertrain' => 'BEV', 'year' => 2026, 'month' => null, 'units' => 450,
        ]);
        app(\App\Services\VehicleMarketService::class)->flush();

        $res = $this->getJson('/api/v1/vehicle-market/model-history?brand=Toyota&model=Alphard');
        $res->assertOk();
        $this->assertSame(2026, $res->json('data.monthly_year'));

        $months = collect($res->json('data.months'));
        $this->assertCount(4, $months); // 3 bulan BEV + 1 baris PHEV bulan 3
        $this->assertEquals(150, $months->firstWhere('month', 2)['units']);
        $phevMar = $months->first(fn ($r) => (int) $r['month'] === 3 && $r['powertrain'] === 'PHEV');
        $this->assertNotNull($phevMar);
        $this->assertEquals(50, $phevMar['units']);

        // Rincian bulanan ikut ke dalam tiap type (additive "months").
        $types = collect($res->json('data.types'));
        $hev = $types->firstWhere('name', 'Alphard HEV');
        $this->assertNotNull($hev);
        $hevMonths = collect($hev['months']);
        $this->assertCount(2, $hevMonths);
        $this->assertEquals(30, $hevMonths->firstWhere('month', 1)['units']);
        $this->assertSame('BEV', $hevMonths->firstWhere('month', 1)['powertrain']);
    }
}

