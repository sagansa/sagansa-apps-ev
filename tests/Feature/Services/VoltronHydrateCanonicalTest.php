<?php

namespace Tests\Feature\Services;

use App\Models\ChargingStation;
use App\Models\ChargingStationCharger;
use App\Models\Provider;
use App\Models\VoltronPoint;
use App\Services\CanonicalStationHydrateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoltronHydrateCanonicalTest extends TestCase
{
    use RefreshDatabase;

    private function seedTitik(int $externalId, array $overrides = []): VoltronPoint
    {
        return VoltronPoint::create(array_merge([
            'external_id' => $externalId,
            'nama' => 'BYD Haka Auto Balikpapan',
            'alamat' => 'Jl. Ruhui Rahayu No.124',
            'kota' => 'Kota Balikpapan',
            'provinsi' => 'KALIMANTAN TIMUR',
            'latitude' => -1.2418181,
            'longitude' => 116.8770488,
            'operator' => 'Voltron',
            'status' => 'Available',
            'jumlah_poin' => 3,
            'konektor' => [
                ['nama' => 'CCS 2', 'daya_kw' => 60.0, 'jumlah' => 2, 'status' => 'Available'],
                ['nama' => 'AC Tipe 2', 'daya_kw' => 22.0, 'jumlah' => 1, 'status' => 'Charging'],
            ],
            'raw_payload' => ['external_id' => $externalId],
            'diambil_pada' => '2026-11-28T01:00:00Z',
        ], $overrides));
    }

    public function test_hydrate_membuat_stasiun_kanonik_dan_charger(): void
    {
        $this->seedTitik(500138);

        $stats = app(CanonicalStationHydrateService::class)->hydrateFromVoltron();

        $this->assertSame(1, $stats['created']);
        $this->assertSame(0, $stats['pruned']);

        $station = ChargingStation::query()
            ->where('source', 'voltron')->where('source_station_id', 500138)->first();

        $this->assertNotNull($station);
        $this->assertSame('BYD Haka Auto Balikpapan', $station->nama_lokasi);
        // Tier tertinggi di antara konektornya: 60 kW → Ultra Fast.
        $this->assertSame('Ultra Fast Charging', $station->type_charge);
        $this->assertSame('>50 kW DC', $station->watt);
        $this->assertSame('Voltron', $station->provider_name);
        $this->assertNotNull($station->provider_id);
        // avail=2 (CCS Available×2), charging=1 (AC), total=3 → available.
        $this->assertSame('available', $station->availability_level);
        $this->assertSame(2, $station->available_count);
        $this->assertSame(1, $station->charging_count);
        $this->assertSame(3, $station->total_konektor);

        // Child charger: grup (CCS 2, 60 kW) ×2 dan (AC Tipe 2, 22 kW) ×1.
        $chargers = ChargingStationCharger::where('station_id', $station->id)->get();
        $this->assertCount(2, $chargers);
        $this->assertSame('Ultra Fast Charging', $chargers->firstWhere('nama', 'CCS 2')->type_charge);
        // number_format(60.0,1)='60.0' → rtrim('0')='60.' → rtrim('.')='60'
        $this->assertSame('60 kW', $chargers->firstWhere('nama', 'CCS 2')->watt);
        $this->assertSame(2, $chargers->firstWhere('nama', 'CCS 2')->jumlah_konektor);
        $this->assertSame('Medium Charging', $chargers->firstWhere('nama', 'AC Tipe 2')->type_charge);

        // Provider auto-create.
        $this->assertNotNull(Provider::where('name', 'Voltron')->first());
    }

    public function test_status_tanpa_slot_bebas_adalah_occupied(): void
    {
        $this->seedTitik(500139, ['konektor' => [
            ['nama' => 'CCS 2', 'daya_kw' => 60.0, 'jumlah' => 2, 'status' => 'Charging'],
        ]]);

        app(CanonicalStationHydrateService::class)->hydrateFromVoltron();

        $station = ChargingStation::where('source_station_id', 500139)->first();
        $this->assertSame('occupied', $station->availability_level);
    }

    public function test_rehydrate_idempoten_dan_mendeteksi_perubahan_master(): void
    {
        $service = app(CanonicalStationHydrateService::class);
        $this->seedTitik(500140);
        $service->hydrateFromVoltron();

        $station = ChargingStation::where('source_station_id', 500140)->first();
        $updatedAt = $station->updated_at->toISOString();
        $stats = $service->hydrateFromVoltron();

        $this->assertSame(0, $stats['created']);
        $this->assertSame(0, $stats['updated']);
        $this->assertSame(1, $stats['unchanged']);
        $this->assertSame($updatedAt,
            ChargingStation::where('source_station_id', 500140)->first()->updated_at->toISOString());
    }

    public function test_perubahan_master_menaikkan_updated_at_dan_mengganti_charger(): void
    {
        $service = app(CanonicalStationHydrateService::class);
        $point = $this->seedTitik(500141);
        $service->hydrateFromVoltron();

        // Kolom timestamps presisi 1 detik — tanpa backdate, create & re-hydrate
        // bisa jatuh di detik yang sama sehingga assertNotSame flaky.
        ChargingStation::where('source_station_id', 500141)
            ->update(['updated_at' => now()->subSeconds(2)]);
        $updatedAt = ChargingStation::where('source_station_id', 500141)->first()->updated_at->toISOString();

        // Master berubah + konektor berkurang (1 CCS Available saja).
        $point->update([
            'nama' => 'BYD Haka Auto Balikpapan Selatan',
            'konektor' => [['nama' => 'CCS 2', 'daya_kw' => 60.0, 'jumlah' => 1, 'status' => 'Available']],
        ]);

        $stats = $service->hydrateFromVoltron();

        $this->assertSame(1, $stats['updated']);
        $station = ChargingStation::where('source_station_id', 500141)->first();
        $this->assertSame('BYD Haka Auto Balikpapan Selatan', $station->nama_lokasi);
        $this->assertNotSame($updatedAt, $station->updated_at->toISOString());
        $this->assertSame(1, ChargingStationCharger::where('station_id', $station->id)->count());
    }

    public function test_titik_yang_hilang_dari_adaptor_diprune(): void
    {
        $service = app(CanonicalStationHydrateService::class);
        $point = $this->seedTitik(500142);
        $service->hydrateFromVoltron();

        $point->delete();
        $stats = $service->hydrateFromVoltron();

        $this->assertSame(1, $stats['pruned']);
        $this->assertNull(ChargingStation::where('source_station_id', 500142)->first());
    }

    public function test_tanpa_koordinat_dilewati(): void
    {
        $this->seedTitik(500143, ['latitude' => null, 'longitude' => null]);

        $stats = app(CanonicalStationHydrateService::class)->hydrateFromVoltron();

        $this->assertSame(0, $stats['processed']);
        $this->assertSame(0, $stats['created']);
    }
}
