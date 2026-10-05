<?php

namespace Tests\Feature\Api;

use App\Models\Charger;
use App\Models\ChargerLocation;
use App\Models\ChargingStation;
use App\Models\ChargingStationCharger;
use App\Models\ChargingStationConnector;

/**
 * Endpoint POST /my/charging-locations/from-station — aksi eksplisit user
 * "buat lokasi dari All Map" saat pencatatan sesi charging: data station map
 * (sumber sama dengan map SPKLU di apps) dipindahkan ke charger_locations +
 * chargers secara transaksional; reuse dulu yang sudah ada, charger box baru
 * ikut ditambahkan. Sesi berikutnya menunjuk charger_location_id + charger_id
 * (alur seperti form Filament ChargeResource).
 */
class UserChargerLocationFromStationTest extends ApiTestCase
{
    private function makeStationWithBoxes(): array
    {
        $station = ChargingStation::create([
            'source' => 'esdm',
            'nama_lokasi' => 'SPKLU PLN Senayan',
            'alamat' => 'Jl. Asia Afrika',
            'latitude' => -6.22,
            'longitude' => 106.80,
            'provider_name' => 'PLN',
            'provinsi' => 'DKI Jakarta',
            'kabupaten_kota' => 'Jakarta Selatan',
        ]);

        $dcBox = ChargingStationCharger::create([
            'station_id' => $station->id,
            'chargerbox_id' => 'CB-0001',
            'type_charge' => 'ultra_fast',
            'nama' => 'ABB Terra 184',
            'watt' => '50 kW',
            'jumlah_charger' => 2,
            'jumlah_konektor' => '2',
        ]);
        ChargingStationConnector::create([
            'charger_id' => $dcBox->id,
            'nama_konektor' => 'CCS2',
        ]);

        $acBox = ChargingStationCharger::create([
            'station_id' => $station->id,
            'chargerbox_id' => 'CB-0002',
            'type_charge' => 'medium',
            'nama' => 'Wallbox Pulsar',
            'watt' => '22 kW',
            'jumlah_charger' => 1,
            'jumlah_konektor' => '1',
        ]);
        ChargingStationConnector::create([
            'charger_id' => $acBox->id,
            'nama_konektor' => 'Type 2',
        ]);

        return [$station, $dcBox, $acBox];
    }

    public function test_it_moves_all_map_data_into_location_and_chargers(): void
    {
        [$station] = $this->makeStationWithBoxes();

        $response = $this->postJson('/api/v1/my/charging-locations/from-station', [
            'charging_station_id' => $station->id,
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.name', 'SPKLU PLN Senayan')
            ->assertJsonCount(2, 'data.chargers');

        $this->assertDatabaseHas('charger_locations', [
            'user_id' => $this->authUser->id,
            'charging_station_id' => $station->id,
            'name' => 'SPKLU PLN Senayan',
            'data_source' => 'charging_station',
            'location_on' => 1,
        ]);

        // Spesifikasi charger diturunkan dari data box map: DC + CCS2 + 50 kW.
        $dc = Charger::where('station_chargerbox_id', 'CB-0001')->first();
        $this->assertNotNull($dc);
        $this->assertSame('DC', $dc->currentCharger->name);
        $this->assertSame('CCS2', $dc->typeCharger->name);
        $this->assertSame('50 kW', $dc->powerCharger->name);
        $this->assertSame(2, $dc->unit);

        // Box AC: AC + Type 2 + 22 kW.
        $ac = Charger::where('station_chargerbox_id', 'CB-0002')->first();
        $this->assertNotNull($ac);
        $this->assertSame('AC', $ac->currentCharger->name);
        $this->assertSame('Type 2', $ac->typeCharger->name);
        $this->assertSame('22 kW', $ac->powerCharger->name);

        // Semua charger menempel ke lokasi yang sama.
        $this->assertSame($dc->charger_location_id, $ac->charger_location_id);
    }

    public function test_it_reuses_existing_location_and_chargers_on_second_call(): void
    {
        [$station] = $this->makeStationWithBoxes();

        $first = $this->postJson('/api/v1/my/charging-locations/from-station', [
            'charging_station_id' => $station->id,
        ]);
        $first->assertStatus(201);

        // Panggilan kedua untuk station yang sama → lokasi & charger yang
        // sama dipakai ulang, tidak ada duplikat.
        $second = $this->postJson('/api/v1/my/charging-locations/from-station', [
            'charging_station_id' => $station->id,
        ]);

        $second->assertStatus(201)
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonCount(2, 'data.chargers');

        $this->assertDatabaseCount('charger_locations', 1);
        $this->assertDatabaseCount('chargers', 2);
    }

    public function test_it_adds_new_chargerbox_from_updated_map_data(): void
    {
        [$station] = $this->makeStationWithBoxes();

        $first = $this->postJson('/api/v1/my/charging-locations/from-station', [
            'charging_station_id' => $station->id,
        ]);
        $first->assertStatus(201);

        // Map menambah charger box baru di station yang sama → panggilan
        // berikutnya menambahkan charger baru, lokasi tetap dipakai ulang.
        ChargingStationCharger::create([
            'station_id' => $station->id,
            'chargerbox_id' => 'CB-0003',
            'type_charge' => 'fast',
            'nama' => 'Starco 120',
            'watt' => '120 kW',
            'jumlah_charger' => 1,
            'jumlah_konektor' => '1',
        ]);

        $second = $this->postJson('/api/v1/my/charging-locations/from-station', [
            'charging_station_id' => $station->id,
        ]);

        $second->assertStatus(201)
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonCount(3, 'data.chargers');

        $this->assertDatabaseCount('charger_locations', 1);
        $this->assertDatabaseCount('chargers', 3);
        $this->assertDatabaseHas('chargers', [
            'station_chargerbox_id' => 'CB-0003',
            'charger_location_id' => $first->json('data.id'),
        ]);
    }

    public function test_it_keeps_location_of_other_users_separate(): void
    {
        // Lokasi hasil pindahan milik user A tidak dipakai user B — tiap
        // user punya charger location sendiri (owner-scoped).
        [$station] = $this->makeStationWithBoxes();

        $otherLocation = ChargerLocation::factory()->create([
            'user_id' => $this->authUser->id + 1,
            'charging_station_id' => $station->id,
            'data_source' => 'charging_station',
        ]);

        $response = $this->postJson('/api/v1/my/charging-locations/from-station', [
            'charging_station_id' => $station->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.id', function ($id) use ($otherLocation) {
                return $id !== $otherLocation->id;
            });

        $this->assertDatabaseCount('charger_locations', 2);
    }

    public function test_it_returns_404_for_unknown_station(): void
    {
        $this->postJson('/api/v1/my/charging-locations/from-station', [
            'charging_station_id' => 999999,
        ])->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    public function test_show_serves_location_with_chargers_to_owner(): void
    {
        // Regresi: form mobile mengambil daftar charger via GET
        // /charging-locations/{id} legacy yang resource-nya TIDAK menyertakan
        // chargers → list selalu kosong. Endpoint my-scoped ini harus
        // menyajikan chargers + nama arus/tipe/daya.
        [$station] = $this->makeStationWithBoxes();

        $created = $this->postJson('/api/v1/my/charging-locations/from-station', [
            'charging_station_id' => $station->id,
        ]);
        $created->assertStatus(201);
        $locationId = $created->json('data.id');

        $response = $this->getJson("/api/v1/my/charging-locations/{$locationId}");

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonPath('data.id', $locationId)
            ->assertJsonCount(2, 'data.chargers')
            ->assertJsonPath('data.chargers.0.current_charger.name', 'DC')
            ->assertJsonPath('data.chargers.0.type_charger.name', 'CCS2')
            ->assertJsonPath('data.chargers.0.power_charger.name', '50 kW');
    }

    public function test_show_forbidden_for_other_users_location(): void
    {
        [$station] = $this->makeStationWithBoxes();

        $otherLocation = ChargerLocation::factory()->create([
            'user_id' => $this->authUser->id + 1,
            'charging_station_id' => $station->id,
        ]);

        $this->getJson("/api/v1/my/charging-locations/{$otherLocation->id}")
            ->assertStatus(403)
            ->assertJson(['success' => false]);
    }
}
