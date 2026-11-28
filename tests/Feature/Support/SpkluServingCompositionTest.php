<?php

namespace Tests\Feature\Support;

use App\Models\ChargingStation;
use App\Support\SpkluServing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpkluServingCompositionTest extends TestCase
{
    use RefreshDatabase;

    private function station(string $source, string $nama, array $overrides = []): ChargingStation
    {
        return ChargingStation::create(array_merge([
            'source' => $source,
            'source_station_id' => uniqid(),
            'nama_lokasi' => $nama,
            'latitude' => -6.2,
            'longitude' => 106.8,
            'availability_level' => 'unknown',
        ], $overrides));
    }

    public function test_slice_voltron_disembunyikan_dan_titik_fetch_tampil(): void
    {
        $this->station('pln', '(VOLTRON) Suvarna Golf Club');
        $this->station('pln', '(VOLTRON) Sequis Tower');
        $plnLain = $this->station('pln', 'SPKLU PLN UID Jakarta');
        $voltron = $this->station('voltron', 'BYD Haka Auto Balikpapan', ['source_station_id' => 500138]);
        $this->station('voltron', 'Tanpa koordinat', ['source_station_id' => 500139, 'latitude' => null, 'longitude' => null]);

        $baris = SpkluServing::baseQuery()->get();

        $this->assertEqualsCanonicalizing(
            [$plnLain->id, $voltron->id],
            $baris->pluck('id')->all()
        );
    }

    public function test_marker_count_sama_dengan_baris_tersaji(): void
    {
        $this->station('pln', '(VOLTRON) Sequis Tower');
        $this->station('pln', 'SPKLU PLN UID Jakarta');
        $this->station('voltron', 'BYD Haka Auto', ['source_station_id' => 500138]);

        $markers = SpkluServing::computeSyncMarkers();

        $this->assertSame(2, $markers['count']);
        $this->assertSame(SpkluServing::baseQuery()->count(), $markers['count']);
    }

    public function test_data_version_mengabaikan_prefix_slice_sebelum_ada_data_fetch(): void
    {
        // Sebelum adaptor aktif: slice tetap terhitung di data_version? TIDAK —
        // komposisi sama persis di semua tiga helper, jadi slice di-exclude
        // juga di data_version (identity probe menyertai serving).
        $this->station('pln', '(VOLTRON) Sequis Tower');
        $v1 = SpkluServing::computeSyncMarkers()['data_version'];

        // Titik voltron masuk → data_version berubah (full fetch klien).
        $this->station('voltron', 'BYD Haka Auto', ['source_station_id' => 500138]);
        $v2 = SpkluServing::computeSyncMarkers()['data_version'];

        $this->assertNotSame($v1, $v2);
    }

    public function test_prefix_config_kosong_perilaku_lama(): void
    {
        config(['spklu.pln_slice_prefixes' => []]);
        $this->station('pln', '(VOLTRON) Sequis Tower');

        $this->assertSame(1, SpkluServing::baseQuery()->count());
    }
}
