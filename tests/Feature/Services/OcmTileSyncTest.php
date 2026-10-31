<?php

namespace Tests\Feature\Services;

use App\Models\OcmSyncState;
use App\Models\OpenchargemapPoi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * OcmTileSyncService — bootstrap worldwide via quadtree bbox tiling.
 *
 * Paginasi offset OCM rusak (satu query mentok ~1000 POI), maka koleksi
 * seluruh dunia dilakukan dengan memecah kotak: tile yang penuh dipecah
 * jadi 4 anak sampai tiap tile < tileMaxResults. Negara tiap POI
 * diturunkan dari AddressInfo.CountryID via katalog ocm_reference_data.
 */
class OcmTileSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.openchargemap.key' => 'testing-dummy-key']);
    }

    public function test_bootstrap_subdivides_full_tiles_and_writes_state(): void
    {
        $this->seedCountriesCatalog([107 => 'ID']);

        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            // Tile awal (besar) penuh (== tileMaxResults 2) → subdivisi;
            // 4 anak masing-masing 1 POI (< 2) → leaf.
            if ($calls === 1) {
                return Http::response([$this->tilePoi(1), $this->tilePoi(2)], 200);
            }

            return Http::response([$this->tilePoi(100 + $calls)], 200);
        });

        $stats = $this->tileService()->bootstrapWorld();

        // 1 tile awal + 4 anak = 5 request.
        $this->assertSame(5, $stats['requests']);
        $this->assertSame(1, $stats['subdivided']);
        $this->assertSame(6, $stats['unique']);

        // Format bbox wajib berkurung — CSV polos dibungkam senyap oleh OCM.
        Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'boundingbox=('));

        // Country_code diturunkan dari CountryID via katalog (107 → ID).
        $this->assertSame(6, OpenchargemapPoi::count());
        $this->assertDatabaseHas('openchargemap_pois', ['ocm_id' => 1, 'country_code' => 'ID']);

        // State per negara ditulis — incremental harian siap lanjut.
        $this->assertDatabaseHas('ocm_sync_state', ['country' => 'ID', 'mode' => 'penuh']);
        $this->assertSame(6, OcmSyncState::where('country', 'ID')->value('poi_count'));
    }

    public function test_tile_skips_pois_with_unknown_country(): void
    {
        // Katalog kosong → CountryID tak bisa dipetakan → POI dilewati
        // (jangan sampai salah default ke negara lain).
        $this->seedCountriesCatalog([]);

        Http::fake(fn () => Http::response([$this->tilePoi(501, 9999)], 200));

        $stats = $this->tileService()->bootstrapWorld();

        $this->assertSame(1, $stats['skipped_unknown_country']);
        $this->assertSame(0, OpenchargemapPoi::count());
        $this->assertSame(0, OcmSyncState::count());
    }

    public function test_rate_limited_tile_retries_after_backoff(): void
    {
        $this->seedCountriesCatalog([107 => 'ID']);

        Http::fakeSequence('api.openchargemap.io/*')
            ->push([], 429)
            ->push([$this->tilePoi(601)], 200)
            ->whenEmpty(Http::response([], 200));

        $stats = $this->tileService()->bootstrapWorld();

        $this->assertNull($stats['error']);
        $this->assertSame(1, $stats['unique']);
        $this->assertDatabaseHas('openchargemap_pois', ['ocm_id' => 601, 'country_code' => 'ID']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Grid uji: 1 tile awal saja (latStep menutup -60..80, lonStep 360°)
     * dgn tileMaxResults 2 agar subdivisi mudah dipicu.
     */
    private function tileService(): \App\Services\OcmTileSyncService
    {
        return app(\App\Services\OcmTileSyncService::class, [
            'latStep' => 140.0,
            'lonStep' => 360.0,
            'tileMaxResults' => 2,
            'requestDelaySec' => 0,
            'backoffInitialSec' => 0,
            'backoffMaxSec' => 0,
        ]);
    }

    /** @param array<int, string> $isoByCountryId */
    private function seedCountriesCatalog(array $isoByCountryId): void
    {
        $rows = [];
        foreach ($isoByCountryId as $countryId => $iso) {
            $rows[] = ['id' => $countryId, 'title' => 'Country '.$iso, 'iso' => $iso];
        }

        \DB::table('ocm_reference_data')->insert([
            'scope' => 'countries',
            'payload' => json_encode($rows),
            'fetched_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** POI bentuk compact live (bbox query tanpa countrycode). */
    private function tilePoi(int $id, int $countryId = 107): array
    {
        return [
            'ID' => $id,
            'UUID' => 'uuid-'.$id,
            'AddressInfo' => [
                'ID' => $id + 500000,
                'Title' => 'Tile POI '.$id,
                'CountryID' => $countryId,
                'Latitude' => 1.35,
                'Longitude' => 103.8,
            ],
            'Connections' => [['ConnectionTypeID' => 33, 'PowerKW' => 120, 'Quantity' => 1]],
            'OperatorID' => 3664,
            'SubmissionStatusTypeID' => 200,
            'NumberOfPoints' => 1,
            'DataQualityLevel' => 1,
        ];
    }
}
