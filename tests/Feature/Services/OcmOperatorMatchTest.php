<?php

namespace Tests\Feature\Services;

use App\Models\ChargingStation;
use App\Models\OpenchargemapPoi;
use App\Models\Provider;
use App\Services\CanonicalStationHydrateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ocm:import-operators — entity-resolution operator OCM → providers.
 *
 * Operator OCM (string bebas + ID stabil) tidak boleh dikelola manual via
 * Filament (ribuan operator worldwide). Jalur: auto-match via guessProvider,
 * sisanya auto-provision provider minimal. Hasilnya dipakai hydrateFromOcm
 * utk mengisi provider_id stasiun canonical source='ocm'.
 */
class OcmOperatorMatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // .env.testing sudah punya dummy key; eksplisit di sini agar test
        // tidak bergantung pada env lokal.
        config(['services.openchargemap.key' => 'testing-dummy-key']);

        // Default: katalog referencedata KOSONG tapi segar — ensureFresh tidak
        // melakukan network call. Test yang butuh isinya men-seed scope-nya
        // sendiri (jalur yang sama dgn produksi: katalog dibaca dari tabel).
        foreach (['operators', 'connection_types', 'levels', 'current_types', 'countries'] as $scope) {
            \DB::table('ocm_reference_data')->insert([
                'scope' => $scope,
                'payload' => json_encode([]),
                'fetched_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function test_import_operators_matches_and_provisions(): void
    {
        $pln = Provider::create(['name' => 'PLN Mobile', 'status' => 1]);

        // Jalur raw API (ocm:sync / harvest) — OperatorInfo.ID stabil.
        $this->seedPoi([
            'ocm_id' => 101,
            'operator' => 'PT PLN (Persero)',
            'raw_payload' => ['ID' => 101, 'OperatorInfo' => ['ID' => 1, 'Title' => 'PT PLN (Persero)']],
        ]);

        // Operator tanpa padanan di providers → auto-provision provider baru.
        $this->seedPoi([
            'ocm_id' => 102,
            'operator' => 'Terra Charge',
            'raw_payload' => ['ID' => 102, 'OperatorInfo' => ['ID' => 2, 'Title' => 'Terra Charge']],
        ]);

        // Noise operator OCM → diabaikan, jangan dibuatkan provider.
        $this->seedPoi([
            'ocm_id' => 103,
            'operator' => '(Unknown Operator)',
            'raw_payload' => ['ID' => 103, 'OperatorInfo' => ['ID' => 3, 'Title' => '(Unknown Operator)']],
        ]);

        $this->artisan('ocm:import-operators')->assertSuccessful();

        // 'PT PLN (Persero)' → PLN Mobile via token PLN (strong alias).
        $this->assertDatabaseHas('ocm_operator_matches', [
            'ocm_operator_id' => 1,
            'provider_id' => $pln->id,
            'match_status' => 'auto',
            'match_method' => 'guess',
        ]);

        // 'Terra Charge' → provider baru ter-provision + match ter-catat.
        $terra = Provider::where('name', 'Terra Charge')->first();
        $this->assertNotNull($terra, 'provider Terra Charge harus ter-provision');
        $this->assertDatabaseHas('ocm_operator_matches', [
            'ocm_operator_id' => 2,
            'provider_id' => $terra->id,
            'match_status' => 'auto',
            'match_method' => 'auto_provision',
        ]);

        $this->assertDatabaseMissing('providers', ['name' => '(Unknown Operator)']);

        // Integrasi hydrate: stasiun OCM dapat provider_id dari match.
        app(CanonicalStationHydrateService::class)->hydrateFromOcm();
        $this->assertSame(
            $pln->id,
            ChargingStation::query()->where('source', 'ocm')->where('source_station_id', 101)->value('provider_id')
        );
        $this->assertSame(
            $terra->id,
            ChargingStation::query()->where('source', 'ocm')->where('source_station_id', 102)->value('provider_id')
        );
    }

    public function test_import_operators_handles_compact_shape_via_referencedata(): void
    {
        // Bentuk respons live ocm:sync (compact=true): OperatorID int TANPA
        // OperatorInfo — judul operator hanya tersedia dari katalog
        // referencedata yang sudah tersimpan (diisi ocm:import-operators).
        \DB::table('ocm_reference_data')->where('scope', 'operators')->update([
            'payload' => json_encode([
                ['id' => 3664, 'title' => 'Terra Charge'],
                ['id' => 1, 'title' => '(Unknown Operator)'],
            ]),
        ]);

        $this->seedPoi([
            'ocm_id' => 501,
            'operator' => null, // compact shape tidak mengisi kolom operator
            'connections' => [],
            'raw_payload' => [
                'ID' => 501,
                'OperatorID' => 3664,
                'UsageCost' => 'IDR 2467/kWh',
            ],
        ]);

        $this->artisan('ocm:import-operators')->assertSuccessful();

        // Operator 3664 di-resolve via referencedata → match + provider baru.
        $terra = Provider::where('name', 'Terra Charge')->first();
        $this->assertNotNull($terra);
        $this->assertDatabaseHas('ocm_operator_matches', [
            'ocm_operator_id' => 3664,
            'operator_title' => 'Terra Charge',
            'provider_id' => $terra->id,
        ]);

        // Kolom operator POI di-backfill agar browse Filament informatif.
        $this->assertDatabaseHas('openchargemap_pois', [
            'ocm_id' => 501,
            'operator' => 'Terra Charge',
        ]);

        // Operator noise dari referencedata tidak di-provision.
        $this->assertDatabaseMissing('providers', ['name' => '(Unknown Operator)']);
    }

    public function test_referencedata_fetch_stores_catalog(): void
    {
        Http::fake([
            'api.openchargemap.io/v3/referencedata/*' => Http::response([
                // Bentuk asli respons live: PascalCase; level OCM di ChargerTypes.
                'Operators' => [['ID' => 3664, 'Title' => 'Terra Charge']],
                'ConnectionTypes' => [['ID' => 33, 'Title' => 'CCS (Type 2)']],
                'ChargerTypes' => [['ID' => 2, 'Title' => 'Level 2 : Medium (Over 2kW)']],
                'CurrentTypes' => [['ID' => 20, 'Title' => 'DC (Direct Current)']],
                'Countries' => [
                    ['ID' => 107, 'Title' => 'Indonesia', 'ISOCode' => 'ID'],
                    ['ID' => 4, 'Title' => 'United States', 'ISOCode' => 'US'],
                ],
            ], 200),
        ]);

        $service = app(\App\Services\OcmReferenceDataService::class);
        $result = $service->fetchAndStore();

        $this->assertSame(5, $result['fetched']);
        $this->assertNull($result['error']);
        $this->assertSame('Terra Charge', $service->operatorTitle(3664));
        $this->assertSame('CCS (Type 2)', $service->connectionTypeTitle(33));
        $this->assertSame('DC (Direct Current)', $service->currentTypeTitle(20));
        $this->assertSame('ID', $service->countryIso(107));
        $this->assertNull($service->operatorTitle(999));
    }

    public function test_countries_catalog_maps_country_id_to_iso(): void
    {
        \DB::table('ocm_reference_data')->where('scope', 'countries')->update([
            'payload' => json_encode([
                ['id' => 107, 'title' => 'Indonesia', 'iso' => 'ID'],
                ['id' => 196, 'title' => 'Cyprus', 'iso' => 'CY'],
            ]),
        ]);

        $service = app(\App\Services\OcmReferenceDataService::class);

        $this->assertSame('ID', $service->countryIso(107));
        $this->assertSame('CY', $service->countryIso(196));
        $this->assertSame('Indonesia', $service->countryTitle(107));
        $this->assertNull($service->countryIso(999));
    }

    public function test_import_operators_does_not_provision_non_served_countries(): void
    {
        // Kurasi provider hanya utk negara yang dilayani (OCM_COUNTRIES).
        config(['services.openchargemap.countries' => 'ID']);

        \DB::table('ocm_reference_data')->where('scope', 'operators')->update([
            'payload' => json_encode([
                ['id' => 3664, 'title' => 'Terra Charge'],
                ['id' => 7777, 'title' => 'Op Nusantara'],
            ]),
        ]);

        // POI world (SG) — operator TIDAK boleh di-provision ke providers
        // (tabel providers = daftar terkurasi; jangan banjir operator asing).
        $this->seedPoi([
            'ocm_id' => 801,
            'operator' => null,
            'country_code' => 'SG',
            'raw_payload' => ['ID' => 801, 'OperatorID' => 3664],
        ]);

        // POI ID (legacy tanpa country_code) — diproses normal.
        $this->seedPoi([
            'ocm_id' => 802,
            'operator' => null,
            'raw_payload' => ['ID' => 802, 'OperatorID' => 7777],
        ]);

        $this->artisan('ocm:import-operators')->assertSuccessful();

        // SG: match pending TANPA provider — nama operator tetap ter-backfill.
        $this->assertDatabaseHas('ocm_operator_matches', [
            'ocm_operator_id' => 3664,
            'provider_id' => null,
            'match_status' => 'pending',
        ]);
        $this->assertDatabaseHas('openchargemap_pois', ['ocm_id' => 801, 'operator' => 'Terra Charge']);
        $this->assertNull(Provider::where('name', 'Terra Charge')->first());

        // ID: provision berjalan seperti biasa.
        $this->assertNotNull(Provider::where('name', 'Op Nusantara')->first());
        $this->assertDatabaseHas('ocm_operator_matches', [
            'ocm_operator_id' => 7777,
            'match_status' => 'auto',
        ]);
    }

    public function test_import_operators_is_idempotent(): void
    {
        $this->seedPoi([
            'ocm_id' => 201,
            'operator' => 'Terra Charge',
            'raw_payload' => ['OperatorInfo' => ['ID' => 2, 'Title' => 'Terra Charge']],
        ]);

        $this->artisan('ocm:import-operators')->assertSuccessful();
        $this->artisan('ocm:import-operators')->assertSuccessful();

        $this->assertSame(1, Provider::where('name', 'Terra Charge')->count());
        $this->assertSame(1, DB::table('ocm_operator_matches')->where('ocm_operator_id', 2)->count());
    }

    public function test_hydrate_resolves_provider_by_id_and_by_title(): void
    {
        $shell = Provider::create(['name' => 'Shell', 'status' => 1]);
        DB::table('ocm_operator_matches')->insert([
            'ocm_operator_id' => 3664,
            'operator_title' => 'Terra Charge',
            'provider_id' => $shell->id,
            'match_status' => 'manual',
            'match_method' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Bentuk raw API — match via OperatorInfo.ID.
        $this->seedPoi([
            'ocm_id' => 301,
            'operator' => 'Terra Charge',
            'raw_payload' => ['OperatorInfo' => ['ID' => 3664, 'Title' => 'Terra Charge']],
        ]);

        // Bentuk file import lama — tanpa OperatorInfo, match via title.
        $this->seedPoi([
            'ocm_id' => 302,
            'operator' => 'Terra Charge',
            'raw_payload' => ['source' => 'openchargemap', 'external_id' => 302, 'operator' => 'Terra Charge'],
        ]);

        app(CanonicalStationHydrateService::class)->hydrateFromOcm();

        $this->assertSame(
            $shell->id,
            ChargingStation::query()->where('source', 'ocm')->where('source_station_id', 301)->value('provider_id')
        );
        $this->assertSame(
            $shell->id,
            ChargingStation::query()->where('source', 'ocm')->where('source_station_id', 302)->value('provider_id')
        );
    }

    public function test_pending_match_is_not_applied(): void
    {
        $provider = Provider::create(['name' => 'Some Provider', 'status' => 1]);
        DB::table('ocm_operator_matches')->insert([
            'ocm_operator_id' => 99,
            'operator_title' => 'Terra Charge',
            'provider_id' => $provider->id,
            'match_status' => 'pending',
            'match_method' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seedPoi([
            'ocm_id' => 401,
            'operator' => 'Terra Charge',
            'raw_payload' => ['OperatorInfo' => ['ID' => 99, 'Title' => 'Terra Charge']],
        ]);

        app(CanonicalStationHydrateService::class)->hydrateFromOcm();

        $station = ChargingStation::query()->where('source', 'ocm')->where('source_station_id', 401)->first();
        $this->assertNull($station->provider_id);
        $this->assertSame('Terra Charge', $station->provider_name); // fallback nama mentah
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function seedPoi(array $attributes): void
    {
        OpenchargemapPoi::create(array_merge([
            'uuid' => 'uuid-'.($attributes['ocm_id'] ?? 0),
            'latitude' => -6.2,
            'longitude' => 106.8,
            'data_quality_level' => 1,
        ], $attributes));
    }
}
