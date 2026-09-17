<?php

namespace Tests\Feature\Api;

use App\Models\Battery;
use App\Models\Charge;
use App\Models\Charger;
use App\Models\ChargerLocation;
use App\Models\ChargingStation;
use App\Models\ChargingStationCharger;
use App\Models\ModelVehicle;
use App\Models\TypeCharger;
use App\Models\TypeVehicle;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Validation\ValidationException;

class ChargingSessionTest extends ApiTestCase
{
    public function test_it_lists_only_authenticated_users_charging_sessions(): void
    {
        $otherUser = User::factory()->create();

        Charge::create([
            'user_id' => $this->authUser->id,
            'station_name_snapshot' => 'SPKLU Kebon Jeruk',
            'date' => now()->toDateString(),
            'kWh' => 15.5,
            'total_cost' => 45000,
        ]);

        Charge::create([
            'user_id' => $otherUser->id,
            'station_name_snapshot' => 'SPKLU Orang Lain',
            'date' => now()->toDateString(),
            'kWh' => 30.0,
            'total_cost' => 90000,
        ]);

        $response = $this->getJson('/api/v1/charging-sessions');

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['station_name' => 'SPKLU Kebon Jeruk'])
            ->assertJsonMissing(['station_name' => 'SPKLU Orang Lain']);
    }

    public function test_it_creates_charging_session_with_station_snapshot(): void
    {
        $station = ChargingStation::create([
            'source' => 'esdm',
            'nama_lokasi' => 'SPKLU Senayan',
            'alamat' => 'Jl. Asia Afrika',
            'latitude' => -6.22,
            'longitude' => 106.80,
            'provider_name' => 'PLN',
        ]);

        $response = $this->postJson('/api/v1/charging-sessions', [
            'charging_station_id' => $station->id,
            'date' => '2026-08-04',
            'kwh' => 20.0,
            'total_cost' => 60000,
            'parking' => 5000,
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'station_name' => 'SPKLU Senayan',
                    'station_provider' => 'PLN',
                    'kwh' => 20.0,
                    'total_cost' => 60000,
                    'parking_cost' => 5000,
                ],
            ]);

        $this->assertDatabaseHas('charges', [
            'user_id' => $this->authUser->id,
            'charging_station_id' => $station->id,
            'station_name_snapshot' => 'SPKLU Senayan',
        ]);
    }

    public function test_it_creates_charging_session_with_vehicle_without_fatal(): void
    {
        // Regression: store() memakai Vehicle::find() utk auto-fill km_before —
        // tanpa `use App\Models\Vehicle;` PHP me-resolve ke
        // App\Http\Controllers\Api\V1\Vehicle → fatal 500 "Class not found"
        // pada SEMUA sesi user yang punya kendaraan.
        $vehicle = Vehicle::factory()->for($this->authUser)->create();

        $response = $this->postJson('/api/v1/charging-sessions', [
            'vehicle_id' => $vehicle->id,
            'date' => now()->toDateString(),
            'kwh' => 12.5,
            'total_cost' => 25000,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.vehicle_id', $vehicle->id);
    }

    public function test_it_rejects_battery_percent_out_of_range(): void
    {
        // Persentase baterai wajib 0–100 (min:0|max:100) — nilai >100/<0
        // ditolak validasi, bukan tersimpan. ApiTestCase memakai
        // withoutExceptionHandling → ValidationException ditangkap manual.
        foreach ([150, -5] as $invalid) {
            try {
                $this->postJson('/api/v1/charging-sessions', [
                    'date' => now()->toDateString(),
                    'kwh' => 10,
                    'total_cost' => 30000,
                    'start_charging_now' => $invalid,
                ]);
                $this->fail("Expected ValidationException for start_charging_now={$invalid}.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('start_charging_now', $e->errors());
            }
        }
    }

    public function test_it_returns_analytics_summary(): void
    {
        Charge::create([
            'user_id' => $this->authUser->id,
            'date' => '2026-08-01',
            'kWh' => 25.0,
            'total_cost' => 70000,
            'km_before' => 1000,
            'km_now' => 1150,
        ]);

        Charge::create([
            'user_id' => $this->authUser->id,
            'date' => '2026-08-02',
            'kWh' => 35.0,
            'total_cost' => 95000,
            'km_before' => 1150,
            'km_now' => 1350,
        ]);

        $response = $this->getJson('/api/v1/charging-sessions/analytics');

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'total_sessions' => 2,
                    'total_energy_kwh' => 60.0,
                    'total_cost' => 165000,
                    'total_distance_km' => 350.0,
                ],
            ]);
    }

    public function test_analytics_attributes_energy_for_efficiency_metrics(): void
    {
        // Sesi A: finish == finish_charging_before → rasio 1 (seluruh kWh dipakai jarak).
        Charge::create([
            'user_id' => $this->authUser->id,
            'date' => '2026-08-01',
            'kWh' => 25.0,
            'total_cost' => 70000,
            'km_before' => 1000,
            'km_now' => 1150,
            'start_charging_now' => 20,
            'finish_charging_now' => 100,
            'finish_charging_before' => 100,
        ]);

        // Sesi B: finish 100 > finish_charging_before 80 → rasio (80−20)/(100−20) = 0.75.
        // Atribusi kWh = 35 × 0.75 = 26.25; atribusi cost = 95000 × 0.75 = 71250.
        Charge::create([
            'user_id' => $this->authUser->id,
            'date' => '2026-08-02',
            'kWh' => 35.0,
            'total_cost' => 95000,
            'km_before' => 1150,
            'km_now' => 1350,
            'start_charging_now' => 20,
            'finish_charging_now' => 100,
            'finish_charging_before' => 80,
        ]);

        $response = $this->getJson('/api/v1/charging-sessions/analytics');

        // Total display tetap mentah (60 kWh / 165.000), tapi metrik efisiensi
        // pakai atribusi: total 51.25 kWh & 141.250 cost utk jarak 350 km.
        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'total_energy_kwh' => 60.0,
                    'total_cost' => 165000,
                    'total_distance_km' => 350.0,
                    'km_per_kwh' => 6.83,
                    'cost_per_km' => 404,
                    'cost_per_kwh' => 2756,
                    'kwh_per_100km' => 14.64,
                ],
            ]);
    }

    public function test_it_updates_session_with_owned_vehicle_and_battery(): void
    {
        // Regression Bug #1: array-form rule yg berisi elemen "sometimes|nullable"
        // tidak di-split oleh Laravel → "validateSometimes|nullable does not exist"
        // pada PUT (partial=true). Vehicle/battery milik user harus valid.
        $vehicle = Vehicle::factory()->for($this->authUser)->create();
        $battery = Battery::factory()->create([
            'user_id' => $this->authUser->id,
            'vehicle_id' => $vehicle->id,
        ]);
        $session = Charge::create([
            'user_id' => $this->authUser->id,
            'date' => now()->toDateString(),
            'kWh' => 10,
        ]);

        $response = $this->putJson("/api/v1/charging-sessions/{$session->id}", [
            'vehicle_id' => $vehicle->id,
            'battery_id' => $battery->id,
            'is_finish_charging' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.vehicle_id', $vehicle->id)
            ->assertJsonPath('data.battery_id', $battery->id);
        $this->assertDatabaseHas('charges', [
            'id' => $session->id,
            'vehicle_id' => $vehicle->id,
            'battery_id' => $battery->id,
        ]);
    }

    public function test_it_updates_session_rejects_foreign_vehicle(): void
    {
        // Ownership closure tetap jalan setelah fix: vehicle user lain ditolak.
        $otherUser = User::factory()->create();
        $foreignVehicle = Vehicle::factory()->for($otherUser)->create();
        $session = Charge::create([
            'user_id' => $this->authUser->id,
            'date' => now()->toDateString(),
            'kWh' => 10,
        ]);

        try {
            $this->putJson("/api/v1/charging-sessions/{$session->id}", [
                'vehicle_id' => $foreignVehicle->id,
            ]);
            $this->fail('Expected ValidationException for foreign vehicle.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('vehicle_id', $e->errors());
            $this->assertStringContainsString('Unauthorized access to vehicle.', $e->errors()['vehicle_id'][0]);
        }
    }

    public function test_it_persists_and_serves_meter_before_and_tariff_id(): void
    {
        // Bug #1b: client mengirim meter_before + tariff_id utk resume sesi
        // belum-selesai — sebelumnya di-drop diam-diam (tidak di fillable).
        $session = Charge::create([
            'user_id' => $this->authUser->id,
            'date' => now()->toDateString(),
            'is_finish_charging' => false,
        ]);

        $this->putJson("/api/v1/charging-sessions/{$session->id}", [
            'meter_before' => 1245.5,
            'tariff_id' => 'r1-1300-2200',
        ])->assertOk()
            ->assertJsonPath('data.meter_before', 1245.5)
            ->assertJsonPath('data.tariff_id', 'r1-1300-2200');

        $this->getJson('/api/v1/charging-sessions')
            ->assertOk()
            ->assertJsonPath('data.0.meter_before', 1245.5)
            ->assertJsonPath('data.0.tariff_id', 'r1-1300-2200');
    }

    public function test_it_deletes_charging_session(): void
    {
        $session = Charge::create([
            'user_id' => $this->authUser->id,
            'date' => now()->toDateString(),
            'kWh' => 10,
        ]);

        $response = $this->deleteJson("/api/v1/charging-sessions/{$session->id}");

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertSoftDeleted('charges', ['id' => $session->id]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Filter kombinasi model + AC/DC — repro bug "tidak terjadi apapun".
    // ─────────────────────────────────────────────────────────────────────

    public function test_filter_by_model_vehicle_id_returns_only_matching_sessions(): void
    {
        [$matchingModel, $otherModel] = ModelVehicle::factory()->count(2)->create();

        $vehicleA = Vehicle::factory()->for($this->authUser)->create([
            'model_vehicle_id' => $matchingModel->id,
        ]);
        $vehicleB = Vehicle::factory()->for($this->authUser)->create([
            'model_vehicle_id' => $otherModel->id,
        ]);

        Charge::create([
            'user_id' => $this->authUser->id,
            'vehicle_id' => $vehicleA->id,
            'date' => now()->toDateString(),
            'kWh' => 10,
        ]);
        Charge::create([
            'user_id' => $this->authUser->id,
            'vehicle_id' => $vehicleB->id,
            'date' => now()->toDateString(),
            'kWh' => 20,
        ]);

        $response = $this->getJson("/api/v1/charging-sessions?model_vehicle_id={$matchingModel->id}");

        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.vehicle_id', $vehicleA->id);
    }

    public function test_filter_by_charging_type_dc_uses_snapshot_columns(): void
    {
        // Sesi DC — nama station snapshot mengandung token DC.
        Charge::create([
            'user_id' => $this->authUser->id,
            'station_name_snapshot' => 'SPKLU PLN DC Fast',
            'date' => now()->toDateString(),
            'kWh' => 10,
        ]);
        // Sesi AC — nama station snapshot generik (tidak mengandung token DC).
        Charge::create([
            'user_id' => $this->authUser->id,
            'station_name_snapshot' => 'SPKLU Rumah',
            'date' => now()->toDateString(),
            'kWh' => 5,
        ]);

        $response = $this->getJson('/api/v1/charging-sessions?charging_type=DC');

        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.station_name', 'SPKLU PLN DC Fast');
    }

    public function test_filter_by_charging_type_ac_excludes_dc_sessions(): void
    {
        Charge::create([
            'user_id' => $this->authUser->id,
            'station_name_snapshot' => 'SPKLU PLN DC Fast',
            'date' => now()->toDateString(),
            'kWh' => 10,
        ]);
        Charge::create([
            'user_id' => $this->authUser->id,
            'station_name_snapshot' => 'SPKLU Rumah',
            'date' => now()->toDateString(),
            'kWh' => 5,
        ]);

        $response = $this->getJson('/api/v1/charging-sessions?charging_type=AC');

        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.station_name', 'SPKLU Rumah');
    }

    public function test_filter_combination_model_and_charging_type(): void
    {
        // Skenario user: "DC + Wuling Air Ev" → hanya sesi yang cocok keduanya.
        $airEv = ModelVehicle::factory()->create(['name' => 'Air Ev']);
        $tesla = ModelVehicle::factory()->create(['name' => 'Model 3']);
        $vehicleA = Vehicle::factory()->for($this->authUser)->create(['model_vehicle_id' => $airEv->id]);
        $vehicleB = Vehicle::factory()->for($this->authUser)->create(['model_vehicle_id' => $tesla->id]);

        Charge::create(['user_id' => $this->authUser->id, 'vehicle_id' => $vehicleA->id, 'station_name_snapshot' => 'SPKLU DC', 'date' => now()->toDateString(), 'kWh' => 10]);
        Charge::create(['user_id' => $this->authUser->id, 'vehicle_id' => $vehicleA->id, 'station_name_snapshot' => 'SPKLU AC Rumah', 'date' => now()->toDateString(), 'kWh' => 5]);
        Charge::create(['user_id' => $this->authUser->id, 'vehicle_id' => $vehicleB->id, 'station_name_snapshot' => 'SPKLU DC', 'date' => now()->toDateString(), 'kWh' => 20]);

        $response = $this->getJson("/api/v1/charging-sessions?model_vehicle_id={$airEv->id}&charging_type=DC");

        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.vehicle.model_vehicle.name', 'Air Ev')
            ->assertJsonPath('data.0.station_name', 'SPKLU DC');
    }

    public function test_filter_by_charging_type_dc_via_canonical_station_type_charge(): void
    {
        // Skenario REAL: sesi terhubung ke charging_station canonical yang punya
        // type_charge eksplisit. Nama station TIDAK mengandung token "DC/FAST/CCS"
        // (mis. "SPKLU PLN Senayan") — heuristic substring akan salah klasifikasi,
        // tapi type_charge canonical harus tetap jadi sumber kebenaran.
        $dcStation = ChargingStation::create([
            'source' => 'esdm', 'nama_lokasi' => 'SPKLU PLN Senayan',
            'latitude' => -6.2, 'longitude' => 106.8, 'type_charge' => 'ultra_fast',
        ]);
        ChargingStationCharger::create([
            'station_id' => $dcStation->id, 'type_charge' => 'ultra_fast',
            'nama' => 'ABB Terra 184', 'jumlah_charger' => 1, 'jumlah_konektor' => 2,
        ]);
        $acStation = ChargingStation::create([
            'source' => 'esdm', 'nama_lokasi' => 'SPKLU Hotel',
            'latitude' => -6.2, 'longitude' => 106.8, 'type_charge' => 'medium',
        ]);
        ChargingStationCharger::create([
            'station_id' => $acStation->id, 'type_charge' => 'medium',
            'nama' => 'Wallbox Pulsar', 'jumlah_charger' => 1, 'jumlah_konektor' => 1,
        ]);

        Charge::create([
            'user_id' => $this->authUser->id, 'charging_station_id' => $dcStation->id,
            'station_name_snapshot' => 'SPKLU PLN Senayan',
            'date' => now()->toDateString(), 'kWh' => 30,
        ]);
        Charge::create([
            'user_id' => $this->authUser->id, 'charging_station_id' => $acStation->id,
            'station_name_snapshot' => 'SPKLU Hotel',
            'date' => now()->toDateString(), 'kWh' => 7,
        ]);

        // Filter DC → hanya sesi di station DC canonical.
        $dc = $this->getJson('/api/v1/charging-sessions?charging_type=DC');
        $dc->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.station_name', 'SPKLU PLN Senayan');

        // Filter AC → hanya sesi di station AC canonical.
        $ac = $this->getJson('/api/v1/charging-sessions?charging_type=AC');
        $ac->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.station_name', 'SPKLU Hotel');
    }

    public function test_filter_by_charging_type_via_charger_type_charger_enum(): void
    {
        // Skenario REAL: user memilih Charger spesifik (charger_id) saat catat
        // sesi (presisi input Filament admin). TypeCharger.name di produksi
        // menyimpan NAMA KONEKTOR (bukan enum AC/DC): CCS2/Chademo/DC GBT = DC,
        // Type 2/AC GBT = AC. Nama station snapshot tidak relevan.
        $dcType = TypeCharger::factory()->create(['name' => 'CCS2']);
        $acType = TypeCharger::factory()->create(['name' => 'Type 2']);
        $dcCharger = Charger::factory()->create(['type_charger_id' => $dcType->id]);
        $acCharger = Charger::factory()->create(['type_charger_id' => $acType->id]);

        Charge::create([
            'user_id' => $this->authUser->id, 'charger_id' => $dcCharger->id,
            'station_name_snapshot' => 'SPKLU Pertamina',
            'date' => now()->toDateString(), 'kWh' => 40,
        ]);
        Charge::create([
            'user_id' => $this->authUser->id, 'charger_id' => $acCharger->id,
            'station_name_snapshot' => 'SPKLU Mall',
            'date' => now()->toDateString(), 'kWh' => 6,
        ]);

        // Filter DC → hanya sesi dgn connector DC (CCS2).
        $this->getJson('/api/v1/charging-sessions?charging_type=DC')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.station_name', 'SPKLU Pertamina');

        // Filter AC → hanya sesi dgn connector AC (Type 2).
        $this->getJson('/api/v1/charging-sessions?charging_type=AC')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.station_name', 'SPKLU Mall');
    }

    public function test_resource_exposes_charging_type_derived_from_type_charger(): void
    {
        // Resource mengekspos charging_type deterministik (AC/DC) — turunan
        // cascade yang sama dgn filter. UI pakai ini utk badge tanpa heuristic.
        $dcType = TypeCharger::factory()->create(['name' => 'Chademo']);
        $dcCharger = Charger::factory()->create(['type_charger_id' => $dcType->id]);

        Charge::create([
            'user_id' => $this->authUser->id, 'charger_id' => $dcCharger->id,
            'station_name_snapshot' => 'SPKLU Pertamina',
            'date' => now()->toDateString(), 'kWh' => 40,
        ]);

        $this->getJson('/api/v1/charging-sessions')
            ->assertOk()
            ->assertJsonPath('data.0.charging_type', 'DC');
    }

    public function test_it_creates_session_with_chargerbox_snapshot_and_filters_by_it(): void
    {
        // Skenario REAL: stasiun campuran (AC + DC charger box). User memilih
        // charger box spesifik (mobile picker) → type_charge per-sesi harus
        // mengalahkan tipe stasiun saat filter AC/DC.
        $mixedStation = ChargingStation::create([
            'source' => 'esdm', 'nama_lokasi' => 'SPKLU Grand Indonesia',
            'latitude' => -6.2, 'longitude' => 106.8, 'type_charge' => 'medium',
        ]);
        ChargingStationCharger::create([
            'station_id' => $mixedStation->id, 'type_charge' => 'ultra_fast',
            'nama' => 'ABB Terra 184', 'jumlah_charger' => 1, 'jumlah_konektor' => 2,
        ]);
        ChargingStationCharger::create([
            'station_id' => $mixedStation->id, 'type_charge' => 'medium',
            'nama' => 'Wallbox Pulsar', 'jumlah_charger' => 1, 'jumlah_konektor' => 1,
        ]);

        // Sesi A: user charge di charger box DC (ABB) di stasiun bertipe medium.
        $response = $this->postJson('/api/v1/charging-sessions', [
            'charging_station_id' => $mixedStation->id,
            'station_chargerbox_id' => 'CB-0001',
            'station_chargerbox_name' => 'ABB Terra 184',
            'station_chargerbox_type' => 'ultra_fast',
            'date' => '2026-08-06',
            'kwh' => 30.0,
            'total_cost' => 90000,
        ]);
        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'station_chargerbox_name' => 'ABB Terra 184',
                    'station_chargerbox_type' => 'ultra_fast',
                    'charging_type' => 'DC',
                ],
            ]);

        // Sesi B: user charge di charger box AC (Wallbox) di stasiun yang sama.
        $this->postJson('/api/v1/charging-sessions', [
            'charging_station_id' => $mixedStation->id,
            'station_chargerbox_id' => 'CB-0002',
            'station_chargerbox_name' => 'Wallbox Pulsar',
            'station_chargerbox_type' => 'medium',
            'date' => '2026-08-06',
            'kwh' => 7.0,
            'total_cost' => 20000,
        ])->assertStatus(201)
            ->assertJsonPath('data.charging_type', 'AC');

        // Filter DC → HANYA sesi yg memilih charger box DC, walau stasiun
        // canonical-nya medium (station-level saja tidak akan membedakan).
        $this->getJson('/api/v1/charging-sessions?charging_type=DC')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.station_chargerbox_name', 'ABB Terra 184');

        // Filter AC → HANYA sesi charger box AC.
        $this->getJson('/api/v1/charging-sessions?charging_type=AC')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.station_chargerbox_name', 'Wallbox Pulsar');
    }

    public function test_chargerbox_snapshot_wins_over_station_snapshot_name(): void
    {
        // Sesi manual custom (tanpa charging_station_id) tapi dgn charger box
        // type snapshot. Nama station generik (tidak ada token DC) — snapshot
        // charger box harus jadi sumber kebenaran, bukan heuristic nama.
        Charge::create([
            'user_id' => $this->authUser->id,
            'station_name_snapshot' => 'Rumah',
            'station_chargerbox_id_snapshot' => 'HOME-01',
            'station_chargerbox_name_snapshot' => 'Wallbox Home',
            'station_chargerbox_type_snapshot' => 'medium',
            'date' => now()->toDateString(), 'kWh' => 5,
        ]);

        $this->getJson('/api/v1/charging-sessions?charging_type=DC')
            ->assertOk()->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/charging-sessions?charging_type=AC')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.charging_type', 'AC');
    }

    public function test_charging_session_resource_includes_model_vehicle(): void
    {
        $model = ModelVehicle::factory()->create(['name' => 'Air Ev']);
        $type = TypeVehicle::factory()->create(['model_vehicle_id' => $model->id]);
        $vehicle = Vehicle::factory()->for($this->authUser)->create([
            'model_vehicle_id' => $model->id,
            'type_vehicle_id' => $type->id,
        ]);
        Charge::create([
            'user_id' => $this->authUser->id,
            'vehicle_id' => $vehicle->id,
            'date' => now()->toDateString(),
            'kWh' => 10,
        ]);

        $response = $this->getJson('/api/v1/charging-sessions');

        $response->assertOk();
        // Client (VehicleDto.modelVehicle) memerlukan field ini — tanpa ini,
        // filter modelVehicleId di mobile tidak pernah match (selalu null).
        $response->assertJsonPath('data.0.vehicle.model_vehicle.id', $model->id);
        $response->assertJsonPath('data.0.vehicle.model_vehicle.name', 'Air Ev');
    }

    public function test_journey_aggregates_locations_and_chronological_sessions(): void
    {
        // Lokasi A dikunjungi 2x, lokasi B 1x — hanya utk user terautentikasi.
        $otherUser = User::factory()->create();

        foreach (['2026-07-01', '2026-07-10'] as $i => $date) {
            Charge::create([
                'user_id' => $this->authUser->id,
                'station_name_snapshot' => 'SPKLU Rumah',
                'station_address_snapshot' => 'Jl. Mangga 1',
                'station_provider_snapshot' => 'PLN',
                'station_lat_snapshot' => -6.20,
                'station_lng_snapshot' => 106.82,
                'date' => $date,
                'kWh' => 15 + $i,
                'total_cost' => 45000 + $i * 1000,
            ]);
        }
        Charge::create([
            'user_id' => $this->authUser->id,
            'station_name_snapshot' => 'SPKLU Kantor',
            'station_lat_snapshot' => -6.30,
            'station_lng_snapshot' => 106.90,
            'date' => '2026-07-15',
            'kWh' => 25.0,
            'total_cost' => 90000,
        ]);
        Charge::create([
            'user_id' => $otherUser->id,
            'station_name_snapshot' => 'SPKLU Orang Lain',
            'station_lat_snapshot' => -7.0,
            'station_lng_snapshot' => 110.0,
            'date' => '2026-07-20',
            'kWh' => 50.0,
            'total_cost' => 150000,
        ]);

        $response = $this->getJson('/api/v1/charging-sessions/journey');

        $response->assertOk()->assertJson(['success' => true]);
        $response->assertJsonPath('data.total_locations', 2);
        $response->assertJsonPath('data.total_sessions', 3);

        // Lokasi agregasi per kunci — urut jumlah sesi desc.
        $locations = $response->json('data.locations');
        $this->assertCount(2, $locations);
        $this->assertSame('SPKLU Rumah', $locations[0]['name']);
        $this->assertSame(2, $locations[0]['total_sessions']);
        $this->assertEqualsWithDelta(31.0, $locations[0]['total_kwh'], 0.01);
        $this->assertSame(91000, $locations[0]['total_cost']);
        $this->assertSame('2026-07-01', $locations[0]['first_visit']);
        $this->assertSame('2026-07-10', $locations[0]['last_visit']);
        $this->assertSame('PLN', $locations[0]['provider']);
        $this->assertEqualsWithDelta(-6.20, $locations[0]['latitude'], 0.0001);

        // Sesi urut kronologis & tidak memuat sesi user lain.
        $sessions = $response->json('data.sessions');
        $this->assertCount(3, $sessions);
        $this->assertSame('2026-07-01', $sessions[0]['date']);
        $this->assertSame('2026-07-10', $sessions[1]['date']);
        $this->assertSame('2026-07-15', $sessions[2]['date']);
        $this->assertNotContains('SPKLU Orang Lain', array_column($sessions, 'id'));
    }

    public function test_journey_respects_vehicle_filter(): void
    {
        $vehicle = Vehicle::factory()->for($this->authUser)->create();
        Charge::create([
            'user_id' => $this->authUser->id,
            'station_name_snapshot' => 'SPKLU Rumah',
            'vehicle_id' => $vehicle->id,
            'date' => '2026-07-01',
            'kWh' => 10,
        ]);
        Charge::create([
            'user_id' => $this->authUser->id,
            'station_name_snapshot' => 'SPKLU Kantor',
            'date' => '2026-07-02',
            'kWh' => 20,
        ]);

        $this->getJson('/api/v1/charging-sessions/journey?vehicle_id='.$vehicle->id)
            ->assertOk()
            ->assertJsonPath('data.total_locations', 1)
            ->assertJsonPath('data.locations.0.name', 'SPKLU Rumah');
    }

    public function test_journey_exposes_raw_ids_for_pln_and_custom_locations(): void
    {
        // Lokasi PLN (charging_station_id) + lokasi custom/home milik user
        // (charger_location_id) — picker create-session mobile perlu raw ID ini
        // utk dedup & reuse lokasi di sesi berikutnya.
        $station = ChargingStation::create([
            'source' => 'esdm',
            'nama_lokasi' => 'SPKLU PLN Senayan',
            'alamat' => 'Jl. Asia Afrika',
            'latitude' => -6.22,
            'longitude' => 106.80,
            'provider_name' => 'PLN',
        ]);
        $home = ChargerLocation::factory()->create([
            'user_id' => $this->authUser->id,
            'name' => 'Home Wallbox',
            'address' => 'Jl. Rumah 1',
            'latitude' => -6.20,
            'longitude' => 106.82,
            'data_source' => 'user_custom',
            'location_on' => 2,
            'status' => 1,
        ]);

        Charge::create([
            'user_id' => $this->authUser->id,
            'charging_station_id' => $station->id,
            'station_name_snapshot' => 'SPKLU PLN Senayan',
            'date' => '2026-07-01',
            'kWh' => 20,
        ]);
        Charge::create([
            'user_id' => $this->authUser->id,
            'charger_location_id' => $home->id,
            'station_name_snapshot' => 'Home Wallbox',
            'date' => '2026-07-02',
            'kWh' => 8,
        ]);

        $response = $this->getJson('/api/v1/charging-sessions/journey');

        $response->assertOk();
        $byName = collect($response->json('data.locations'))->keyBy('name');
        $this->assertCount(2, $byName);

        // Lokasi PLN: charging_station_id terisi, charger_location_id null,
        // is_home_charging false.
        $this->assertSame($station->id, $byName['SPKLU PLN Senayan']['charging_station_id']);
        $this->assertNull($byName['SPKLU PLN Senayan']['charger_location_id']);
        $this->assertFalse($byName['SPKLU PLN Senayan']['is_home_charging']);

        // Lokasi custom/home: charger_location_id terisi (uuid), charging_station_id
        // null, is_home_charging true (location_on = 2).
        $this->assertSame($home->id, $byName['Home Wallbox']['charger_location_id']);
        $this->assertNull($byName['Home Wallbox']['charging_station_id']);
        $this->assertTrue($byName['Home Wallbox']['is_home_charging']);
    }

    // --- Enforce: satu sesi berjalan per kendaraan ---

    public function test_store_rejects_unfinished_session_when_vehicle_already_has_one(): void
    {
        $vehicle = Vehicle::factory()->for($this->authUser)->create();

        // Sesi pertama: berjalan.
        Charge::create([
            'user_id' => $this->authUser->id,
            'vehicle_id' => $vehicle->id,
            'date' => now()->toDateString(),
            'is_finish_charging' => false,
            'kWh' => 10,
        ]);

        // Sesi kedua: juga berjalan → harus 409.
        $response = $this->postJson('/api/v1/charging-sessions', [
            'vehicle_id' => $vehicle->id,
            'date' => now()->toDateString(),
            'is_finish_charging' => false,
            'kwh' => 5,
            'total_cost' => 15000,
        ]);

        $response->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => 'Masih ada sesi berjalan untuk kendaraan ini. Selesaikan sesi tersebut dulu.',
            ]);
    }

    public function test_store_allows_unfinished_session_when_vehicle_has_no_existing_unfinished(): void
    {
        $vehicle = Vehicle::factory()->for($this->authUser)->create();

        $response = $this->postJson('/api/v1/charging-sessions', [
            'vehicle_id' => $vehicle->id,
            'date' => now()->toDateString(),
            'is_finish_charging' => false,
            'kwh' => 10,
            'total_cost' => 30000,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.vehicle_id', $vehicle->id);
    }

    public function test_store_allows_unfinished_session_for_different_vehicle(): void
    {
        $vehicle1 = Vehicle::factory()->for($this->authUser)->create();
        $vehicle2 = Vehicle::factory()->for($this->authUser)->create();

        // Sesi berjalan untuk vehicle1.
        Charge::create([
            'user_id' => $this->authUser->id,
            'vehicle_id' => $vehicle1->id,
            'date' => now()->toDateString(),
            'is_finish_charging' => false,
            'kWh' => 10,
        ]);

        // Sesi berjalan untuk vehicle2 → boleh (kendaraan berbeda).
        $response = $this->postJson('/api/v1/charging-sessions', [
            'vehicle_id' => $vehicle2->id,
            'date' => now()->toDateString(),
            'is_finish_charging' => false,
            'kwh' => 8,
            'total_cost' => 25000,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.vehicle_id', $vehicle2->id);
    }

    public function test_store_rejects_finished_session_when_vehicle_has_unfinished(): void
    {
        $vehicle = Vehicle::factory()->for($this->authUser)->create();

        // Sesi berjalan.
        Charge::create([
            'user_id' => $this->authUser->id,
            'vehicle_id' => $vehicle->id,
            'date' => now()->toDateString(),
            'is_finish_charging' => false,
            'kWh' => 10,
        ]);

        // Sesi baru (finished) → juga ditolak karena kendaraan punya sesi berjalan.
        $response = $this->postJson('/api/v1/charging-sessions', [
            'vehicle_id' => $vehicle->id,
            'date' => now()->toDateString(),
            'is_finish_charging' => true,
            'kwh' => 15,
            'total_cost' => 50000,
        ]);

        $response->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => 'Masih ada sesi berjalan untuk kendaraan ini. Selesaikan sesi tersebut dulu.',
            ]);
    }

    public function test_update_rejects_unfishing_when_other_session_exists_for_same_vehicle(): void
    {
        $vehicle = Vehicle::factory()->for($this->authUser)->create();

        // Sesi A: berjalan.
        $sessionA = Charge::create([
            'user_id' => $this->authUser->id,
            'vehicle_id' => $vehicle->id,
            'date' => now()->toDateString(),
            'is_finish_charging' => false,
            'kWh' => 10,
        ]);

        // Sesi B: sudah selesai.
        $sessionB = Charge::create([
            'user_id' => $this->authUser->id,
            'vehicle_id' => $vehicle->id,
            'date' => now()->toDateString(),
            'is_finish_charging' => true,
            'kWh' => 8,
        ]);

        // Coba ubah sesi B menjadi berjalan → 409 (sudah ada sesi A berjalan).
        $response = $this->putJson("/api/v1/charging-sessions/{$sessionB->id}", [
            'is_finish_charging' => false,
        ]);

        $response->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => 'Masih ada sesi berjalan lain untuk kendaraan ini. Selesaikan sesi tersebut dulu.',
            ]);
    }

    public function test_update_allows_changing_own_unfinished_session(): void
    {
        $vehicle = Vehicle::factory()->for($this->authUser)->create();

        // Sesi A: berjalan.
        $sessionA = Charge::create([
            'user_id' => $this->authUser->id,
            'vehicle_id' => $vehicle->id,
            'date' => now()->toDateString(),
            'is_finish_charging' => false,
            'kWh' => 10,
        ]);

        // Edit sesi A itu sendiri → boleh (meskipun berjalan).
        $response = $this->putJson("/api/v1/charging-sessions/{$sessionA->id}", [
            'kwh' => 12,
            'total_cost' => 36000,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.kwh', 12);
    }

    public function test_update_allows_finishing_session_regardless_of_others(): void
    {
        $vehicle = Vehicle::factory()->for($this->authUser)->create();

        // Sesi A: berjalan.
        $sessionA = Charge::create([
            'user_id' => $this->authUser->id,
            'vehicle_id' => $vehicle->id,
            'date' => now()->toDateString(),
            'is_finish_charging' => false,
            'kWh' => 10,
        ]);

        // Sesi B: berjalan.
        $sessionB = Charge::create([
            'user_id' => $this->authUser->id,
            'vehicle_id' => $vehicle->id,
            'date' => now()->toDateString(),
            'is_finish_charging' => false,
            'kWh' => 8,
        ]);

        // Selesaikan sesi A → boleh (menurunkan jumlah berjalan, bukan menambah).
        $response = $this->putJson("/api/v1/charging-sessions/{$sessionA->id}", [
            'is_finish_charging' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.is_finish_charging', true);
    }
}
