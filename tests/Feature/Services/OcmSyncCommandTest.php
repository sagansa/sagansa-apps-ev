<?php

namespace Tests\Feature\Services;

use App\Models\OpenchargemapPoi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ocm:sync — sinkronisasi POI OpenChargeMap per negara langsung dari API v3.
 *
 * Port tools/fetch_openchargemap.py ke Laravel: paginasi + backoff 429 +
 * deteksi halaman duplikat (API OCM diam-diam mengabaikan offset utk query
 * tertentu) + incremental modifiedsince dgn state per negara di tabel
 * ocm_sync_state.
 */
class OcmSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // .env.testing sudah punya dummy key; eksplisit di sini agar test
        // tidak bergantung pada env lokal.
        config(['services.openchargemap.key' => 'testing-dummy-key']);
    }

    public function test_full_sync_paginates_and_upserts_pois_with_country(): void
    {
        Http::fakeSequence('api.openchargemap.io/*')
            ->push([$this->rawPoi(101, -6.2, 106.8), $this->rawPoi(102, -6.3, 106.9)], 200)
            ->push([$this->rawPoi(103, -7.0, 112.7)], 200) // batch < maxresults → selesai
            ->whenEmpty(Http::response([], 200));

        $this->artisan('ocm:sync', [
            '--country' => 'ID',
            '--maxresults' => 2,
            '--delay' => 0,
            '--backoff-init' => 0,
        ])->assertSuccessful();

        $this->assertSame(3, OpenchargemapPoi::count());
        $this->assertDatabaseHas('openchargemap_pois', [
            'ocm_id' => 101,
            'country_code' => 'ID',
        ]);

        // State sync tercatat untuk incremental berikutnya.
        $this->assertDatabaseHas('ocm_sync_state', [
            'country' => 'ID',
            'mode' => 'penuh',
            'poi_count' => 3,
        ]);
    }

    public function test_incremental_uses_modifiedsince_from_previous_state(): void
    {
        $this->seedSyncState('ID', '2026-10-01T00:00:00Z');

        Http::fake([
            'api.openchargemap.io/*' => Http::response([$this->rawPoi(201, -6.2, 106.8)], 200),
        ]);

        $this->artisan('ocm:sync', [
            '--country' => 'ID',
            '--delay' => 0,
            '--backoff-init' => 0,
        ])->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains(
            urldecode($request->url()),
            'modifiedsince=2026-10-01T00:00:00Z'
        ));

        $this->assertDatabaseHas('ocm_sync_state', [
            'country' => 'ID',
            'mode' => 'incremental',
        ]);
    }

    public function test_429_rate_limit_backs_off_and_retries_same_offset(): void
    {
        Http::fakeSequence('api.openchargemap.io/*')
            ->push([], 429)
            ->push([$this->rawPoi(301, -6.2, 106.8)], 200)
            ->whenEmpty(Http::response([], 200));

        $this->artisan('ocm:sync', [
            '--country' => 'ID',
            '--maxresults' => 2,
            '--delay' => 0,
            '--backoff-init' => 0,
            '--backoff-max' => 0,
        ])->assertSuccessful();

        // Offset yang sama diulang (bukan di-skip) setelah backoff.
        $this->assertSame(1, OpenchargemapPoi::count());
        $this->assertDatabaseHas('openchargemap_pois', ['ocm_id' => 301]);
    }

    public function test_duplicate_page_stops_pagination_instead_of_looping(): void
    {
        $page = [$this->rawPoi(401, -6.2, 106.8)];

        Http::fakeSequence('api.openchargemap.io/*')
            ->push($page, 200) // penuh (== maxresults) → lanjut
            ->push($page, 200) // ID sama persis → bug offset → stop
            ->whenEmpty(Http::response([], 200));

        $this->artisan('ocm:sync', [
            '--country' => 'ID',
            '--maxresults' => 1,
            '--delay' => 0,
            '--backoff-init' => 0,
        ])->assertSuccessful();

        Http::assertSentCount(2);
        $this->assertSame(1, OpenchargemapPoi::count());
    }

    public function test_defaults_to_all_countries_from_config(): void
    {
        config(['services.openchargemap.countries' => 'ID,SG']);

        Http::fake(function ($request) {
            $isSg = str_contains(urldecode($request->url()), 'countrycode=SG');

            return Http::response(
                [$isSg
                    ? $this->rawPoi(501, 1.35, 103.82)
                    : $this->rawPoi(500, -6.2, 106.8)],
                200
            );
        });

        $this->artisan('ocm:sync', [
            '--delay' => 0,
            '--backoff-init' => 0,
        ])->assertSuccessful();

        $this->assertSame(2, OpenchargemapPoi::count());
        $this->assertDatabaseHas('openchargemap_pois', ['ocm_id' => 500, 'country_code' => 'ID']);
        $this->assertDatabaseHas('openchargemap_pois', ['ocm_id' => 501, 'country_code' => 'SG']);
        $this->assertSame(2, \DB::table('ocm_sync_state')->count());
    }

    public function test_missing_api_key_fails_with_clear_message(): void
    {
        config(['services.openchargemap.key' => null]);

        $this->artisan('ocm:sync', ['--country' => 'ID'])
            ->expectsOutputToContain('OCM_API_KEY')
            ->assertFailed();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function seedSyncState(string $country, string $lastSyncUtc): void
    {
        \DB::table('ocm_sync_state')->insert([
            'country' => $country,
            'last_sync_utc' => $lastSyncUtc,
            'poi_count' => 100,
            'mode' => 'penuh',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Bentuk respons API OCM mentah (compact) — sama dgn OcmNearbyTest. */
    private function rawPoi(int $id, float $lat, float $lng): array
    {
        return [
            'ID' => $id,
            'UUID' => 'uuid-'.$id,
            'AddressInfo' => [
                'ID' => $id + 500000,
                'Title' => 'OCM POI '.$id,
                'AddressLine1' => 'Jl. Sync No. '.$id,
                'Town' => 'Jakarta Selatan',
                'StateOrProvince' => 'DKI Jakarta',
                'Latitude' => $lat,
                'Longitude' => $lng,
            ],
            'Connections' => [[
                'ConnectionTypeID' => 33,
                'LevelID' => 3,
                'PowerKW' => 120,
                'Quantity' => 2,
            ]],
            'OperatorInfo' => ['ID' => 3664, 'Title' => 'Terra Charge'],
            'UsageCost' => 'IDR 2467/kWh',
            'StatusType' => ['ID' => 50, 'Title' => 'Operational', 'IsOperational' => true],
            'NumberOfPoints' => 2,
            'DataQualityLevel' => 1,
            'DateLastStatusUpdate' => '2026-10-20T10:00:00Z',
        ];
    }
}
