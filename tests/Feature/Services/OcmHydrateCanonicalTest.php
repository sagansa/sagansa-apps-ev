<?php

namespace Tests\Feature\Services;

use App\Models\ChargingStation;
use App\Models\ChargingStationCharger;
use App\Models\OpenchargemapPoi;
use App\Models\Provider;
use App\Services\CanonicalStationHydrateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ocm:hydrate-canonical — hydrate charging_stations (source='ocm') dari
 * openchargemap_pois, pola sama dgn esdm:/pln:hydrate-canonical.
 *
 * Data OCM TIDAK di-serving di Indonesia (spklu.serving_source tetap pln) —
 * row hanya tersimpan di DB utk diverifikasi via backend/Filament.
 */
class OcmHydrateCanonicalTest extends TestCase
{
    use RefreshDatabase;

    public function test_hydrates_pois_into_canonical_stations(): void
    {
        $this->seedPoi([
            'ocm_id' => 101,
            'title' => 'Mall Taman Anggrek DC',
            'latitude' => -6.195,
            'longitude' => 106.79,
            'address_line1' => 'Jl. S. Parman No. 1',
            'town' => 'Jakarta Barat',
            'state_or_province' => 'DKI JAKARTA',
            'operator' => 'PT PLN (Persero)',
            'usage_cost' => 'IDR 2467/kWh',
            'country_code' => 'ID',
            // Bentuk normalisasi tools/fetch_openchargemap.py
            'connections' => [
                ['nama' => 'CCS2', 'daya_kw' => 120, 'arus' => 'DC', 'jumlah' => 2],
                ['nama' => 'AC Tipe 2', 'daya_kw' => 22, 'arus' => 'AC', 'jumlah' => 1],
            ],
            'raw_payload' => ['ID' => 101, 'OperatorInfo' => ['ID' => 1, 'Title' => 'PT PLN (Persero)']],
        ]);

        $this->seedPoi([
            'ocm_id' => 102,
            'title' => 'Terra Charge Sudirman',
            'latitude' => -6.21,
            'longitude' => 106.81,
            'town' => 'Jakarta Selatan',
            'state_or_province' => 'Jakarta Raya',
            'operator' => 'Terra Charge',
            'country_code' => 'SG',
            // Bentuk raw API (hasil ocm:sync / harvest nearby)
            'connections' => [
                ['ConnectionType' => ['Title' => 'CHAdeMO'], 'PowerKW' => 50, 'Quantity' => 1, 'CurrentType' => ['Title' => 'DC']],
            ],
            'raw_payload' => ['ID' => 102, 'OperatorInfo' => ['ID' => 2, 'Title' => 'Terra Charge']],
        ]);

        // POI tanpa koordinat tidak layak — dilewati.
        $this->seedPoi([
            'ocm_id' => 103,
            'title' => 'Tanpa Koordinat',
            'latitude' => null,
            'longitude' => null,
            'connections' => [],
        ]);

        $this->artisan('ocm:hydrate-canonical')->assertSuccessful();

        // 103 tanpa koordinat → skipped; hanya 2 stasiun.
        $this->assertSame(2, ChargingStation::where('source', 'ocm')->count());

        $mall = ChargingStation::query()
            ->where('source', 'ocm')
            ->where('source_station_id', 101)
            ->firstOrFail();

        $this->assertSame('Mall Taman Anggrek DC', $mall->nama_lokasi);
        $this->assertEquals(-6.195, $mall->latitude);
        $this->assertSame('ID', $mall->country_code);
        $this->assertSame('Dki Jakarta', $mall->provinsi);        // title-case normalize
        $this->assertSame('Jakarta Barat', $mall->kabupaten_kota);
        $this->assertSame('Ultra Fast Charging', $mall->type_charge); // tier tertinggi (120 kW)
        $this->assertSame(3, $mall->total_konektor);
        $this->assertSame('IDR 2467/kWh', $mall->harga_pengisian);
        $this->assertSame('unknown', $mall->availability_level);

        // Provider belum ter-match (tabel ocm_operator_matches belum ada isian)
        // → nama operator mentah tetap tersimpan.
        $this->assertNull($mall->provider_id);
        $this->assertSame('PT PLN (Persero)', $mall->provider_name);

        // Charger child: satu baris per tipe konektor OCM.
        $this->assertSame(2, $mall->chargers()->count());
        $ccs = $mall->chargers()->where('nama', 'CCS2')->firstOrFail();
        $this->assertSame('Ultra Fast Charging', $ccs->type_charge);
        $this->assertSame(2, $ccs->jumlah_konektor);

        // Bentuk raw API ikut terbaca: POI 102 → Fast Charging (50 kW DC).
        $terra = ChargingStation::query()
            ->where('source', 'ocm')
            ->where('source_station_id', 102)
            ->firstOrFail();
        $this->assertSame('Fast Charging', $terra->type_charge);
        $this->assertSame('SG', $terra->country_code);
        $this->assertSame('Terra Charge', $terra->provider_name);
    }

    public function test_hydrates_compact_shape_with_referencedata_names(): void
    {
        // Katalog referensi OCM (hasil ocm:import-operators) — nama tipe
        // konektor hanya ada di referencedata, bukan di respons compact.
        \DB::table('ocm_reference_data')->insert([
            'scope' => 'connection_types',
            'payload' => json_encode([
                ['id' => 33, 'title' => 'CCS (Type 2)'],
                ['id' => 1036, 'title' => 'CCS (Type 2) - Low Power'],
            ]),
            'fetched_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('ocm_reference_data')->insert([
            'scope' => 'operators',
            'payload' => json_encode([['id' => 3664, 'title' => 'Terra Charge']]),
            'fetched_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('ocm_operator_matches')->insert([
            'ocm_operator_id' => 3664,
            'operator_title' => 'Terra Charge',
            'provider_id' => Provider::create(['name' => 'Terra Charge', 'status' => 1])->id,
            'match_status' => 'auto',
            'match_method' => 'auto_provision',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // POI bentuk compact live: Connections ber-ID int, OperatorID int.
        $this->seedPoi([
            'ocm_id' => 601,
            'title' => 'Compact Shape POI',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'operator' => null,
            'country_code' => 'ID',
            'connections' => [
                ['ConnectionTypeID' => 33, 'LevelID' => 3, 'PowerKW' => 120, 'Quantity' => 2, 'CurrentTypeID' => 20],
                ['ConnectionTypeID' => 1036, 'LevelID' => 2, 'PowerKW' => 22, 'Quantity' => 1],
            ],
            'raw_payload' => [
                'ID' => 601,
                'OperatorID' => 3664,
                'Connections' => [
                    ['ConnectionTypeID' => 33, 'LevelID' => 3, 'PowerKW' => 120, 'Quantity' => 2, 'CurrentTypeID' => 20],
                    ['ConnectionTypeID' => 1036, 'LevelID' => 2, 'PowerKW' => 22, 'Quantity' => 1],
                ],
            ],
        ]);

        $this->artisan('ocm:hydrate-canonical')->assertSuccessful();

        $station = ChargingStation::query()
            ->where('source', 'ocm')
            ->where('source_station_id', 601)
            ->firstOrFail();

        // Provider ter-resolve via match OperatorID meski tanpa OperatorInfo.
        $this->assertNotNull($station->provider_id);
        $this->assertSame('Terra Charge', $station->provider_name);

        // Nama charger dari katalog referencedata; daya tetap dari PowerKW.
        $ccs = $station->chargers()->where('nama', 'CCS (Type 2)')->firstOrFail();
        $this->assertSame('Ultra Fast Charging', $ccs->type_charge);
        $this->assertSame(2, $ccs->jumlah_konektor);
        $this->assertSame(3, $station->total_konektor);
    }

    public function test_delisted_pois_are_excluded_and_pruned(): void
    {
        // POI aktif biasa — tetap di-hydrate.
        $this->seedPoi([
            'ocm_id' => 701,
            'title' => 'Stasiun Aktif',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'connections' => [],
        ]);

        // POI delisted compact (decommissioned) — SubmissionStatusTypeID >= 1000.
        $this->seedPoi([
            'ocm_id' => 702,
            'title' => 'Stasiun Bekas',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'connections' => [],
            'raw_payload' => ['ID' => 702, 'SubmissionStatusTypeID' => 1002],
        ]);

        // POI delisted verbose — SubmissionStatus.ID >= 1000.
        $this->seedPoi([
            'ocm_id' => 703,
            'title' => 'Stasiun Duplikat',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'connections' => [],
            'raw_payload' => ['ID' => 703, 'SubmissionStatus' => ['ID' => 1001]],
        ]);

        // Kondisi awal: canonical 702 pernah dibuat sebelum POI di-delist.
        ChargingStation::create([
            'source' => 'ocm',
            'source_station_id' => 702,
            'nama_lokasi' => 'Stasiun Bekas',
            'country_code' => 'ID',
        ]);

        $stats = app(CanonicalStationHydrateService::class)->hydrateFromOcm();

        // Baris raw tetap ada (audit), tapi canonical di-prune.
        $this->assertDatabaseHas('openchargemap_pois', ['ocm_id' => 702]);
        $this->assertDatabaseHas('charging_stations', ['source' => 'ocm', 'source_station_id' => 701]);
        $this->assertDatabaseMissing('charging_stations', ['source' => 'ocm', 'source_station_id' => 702]);
        $this->assertDatabaseMissing('charging_stations', ['source' => 'ocm', 'source_station_id' => 703]);
        $this->assertSame(1, $stats['pruned']);
    }

    public function test_idempotent_second_run_writes_nothing(): void
    {
        $this->seedPoi([
            'ocm_id' => 201,
            'title' => 'Idempotent POI',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'connections' => [['nama' => 'CCS2', 'daya_kw' => 60, 'jumlah' => 1]],
        ]);

        $service = app(CanonicalStationHydrateService::class);

        $first = $service->hydrateFromOcm();
        $this->assertSame(1, $first['created']);

        $second = $service->hydrateFromOcm();
        $this->assertSame(0, $second['created']);
        $this->assertSame(0, $second['updated']);
        $this->assertSame(1, $second['unchanged']);

        $this->assertSame(1, ChargingStation::where('source', 'ocm')->count());
        $this->assertSame(1, ChargingStationCharger::count());
    }

    public function test_prunes_stale_ocm_stations_whose_poi_is_gone(): void
    {
        $this->seedPoi([
            'ocm_id' => 301,
            'title' => 'Active POI',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'connections' => [],
        ]);

        // Stasiun canonical ocm yang POI-nya sudah tidak ada (terhapus oleh
        // ocm:import --replace / sync) → di-prune.
        ChargingStation::create([
            'source' => 'ocm',
            'source_station_id' => 999,
            'nama_lokasi' => 'Stasiun Yatim',
            'country_code' => 'ID',
        ]);

        $stats = app(CanonicalStationHydrateService::class)->hydrateFromOcm();

        $this->assertSame(1, $stats['pruned']);
        $this->assertSame(1, ChargingStation::where('source', 'ocm')->count());
        $this->assertDatabaseHas('charging_stations', ['source' => 'ocm', 'source_station_id' => 301]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function seedPoi(array $attributes): void
    {
        OpenchargemapPoi::create(array_merge([
            'uuid' => 'uuid-'.($attributes['ocm_id'] ?? 0),
            'data_quality_level' => 1,
        ], $attributes));
    }
}
