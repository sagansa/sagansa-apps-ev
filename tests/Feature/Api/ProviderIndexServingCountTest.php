<?php

namespace Tests\Feature\Api;

use App\Models\ChargingStation;
use App\Models\Provider;
use App\Models\SpkluLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/v1/providers — "lokasi" di daftar provider harus = stasiun yang
 * benar-benar TAYANG di peta (komposisi SpkluServing), bukan baris master
 * spklu_locations. Kasus nyata: slice (VOLTRON) master masih 238 baris di
 * spklu_locations padahal serving sudah diganti data fetch (281) — layar
 * "Daftar Provider SPKLU" mobile menampilkan angka basi bila count masih
 * dari withCount('spkluLocations').
 */
class ProviderIndexServingCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_location_count_dari_komposisi_serving_bukan_master(): void
    {
        $pln = Provider::create(['name' => 'PLN Mobile', 'status' => 1]);
        $voltron = Provider::create(['name' => 'Voltron', 'status' => 1]);

        // Master spklu_locations (data lama) — 5 baris Voltron: TIDAK boleh dihitung.
        foreach (range(1, 5) as $i) {
            SpkluLocation::create([
                'provider_id' => $voltron->id,
                'provinsi' => 'DKI Jakarta',
                'nama_lokasi' => "(VOLTRON) Master Lama $i",
                'latitude' => -6.2,
                'longitude' => 106.8,
            ]);
        }

        // Kanonik: slice (VOLTRON) di source pln (tersembunyi dari serving),
        // stasiun pln normal, dan stasiun hasil fetch voltron.
        $this->kanonik('pln', 1, '(VOLTRON) Slice Tersembunyi', $voltron->id);
        $this->kanonik('pln', 2, 'SPKLU PLN Normal', $pln->id);
        $this->kanonik('voltron', 500138, 'BYD Haka Auto Balikpapan', $voltron->id);
        $this->kanonik('voltron', 500139, 'Tanpa koordinat', $voltron->id, null, null);

        $response = $this->getJson('/api/v1/providers');

        $response->assertOk()->assertJsonPath('status', 'success');

        $perNama = collect($response->json('data'))
            ->pluck('location_count', 'name');

        $this->assertSame(1, $perNama['Voltron'] ?? 0,
            'Voltron harus dihitung dari serving (1 stasiun fetch ber-koordinat), bukan 5 baris master.');
        $this->assertSame(1, $perNama['PLN Mobile'] ?? 0);
    }

    private function kanonik(
        string $source,
        int|string $sourceStationId,
        string $nama,
        ?string $providerId,
        ?float $lat = -6.2,
        ?float $lng = 106.8,
    ): ChargingStation {
        return ChargingStation::create([
            'source' => $source,
            'source_station_id' => $sourceStationId,
            'nama_lokasi' => $nama,
            'provider_id' => $providerId,
            'latitude' => $lat,
            'longitude' => $lng,
            'availability_level' => 'unknown',
        ]);
    }
}
