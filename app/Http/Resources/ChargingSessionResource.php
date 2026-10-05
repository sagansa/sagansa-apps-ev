<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Serializer untuk sesi charging (Charge) sebagai pencatat sesi mobile.
 *
 * Mengikuti kontrak eksplisit-array (preseden SpkluLocationResource): tipe
 * field di-cast eksplisit, relasi hanya serialize saat eager-loaded. Field
 * snapshot station (`station_*`) selalu di-serve agar riwayat user utuh walau
 * charging_stations canonical berubah/dihapus.
 */
class ChargingSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date ? Carbon::parse($this->date)->toDateString() : null,

            // Lokasi — dua kemungkinan: charging_stations (mobile SPKLU) atau
            // charger_locations (legacy Filament admin). Snapshot dipakai utk
            // sesi mobile; utk sesi legacy (snapshot NULL) fallback ke relasi
            // chargerLocation supaya nama/alamat/provider tetap terisi.
            'charging_station_id' => $this->charging_station_id,
            'charger_location_id' => $this->charger_location_id,
            // Charger terstruktur yang terhubung sesi — hasil resolve-or-create
            // dari data All Map (atau pilihan user di lokasi custom/home).
            'charger_id' => $this->charger_id,
            'station_name' => $this->station_name_snapshot ?? $this->chargerLocation?->name,
            'station_address' => $this->station_address_snapshot ?? $this->chargerLocation?->address,
            'station_latitude' => isset($this->station_lat_snapshot)
                ? (float) $this->station_lat_snapshot
                : ($this->chargerLocation?->latitude !== null ? (float) $this->chargerLocation->latitude : null),
            'station_longitude' => isset($this->station_lng_snapshot)
                ? (float) $this->station_lng_snapshot
                : ($this->chargerLocation?->longitude !== null ? (float) $this->chargerLocation->longitude : null),
            'station_provider' => $this->station_provider_snapshot ?? $this->chargingStation?->provider?->name ?? $this->whenLoaded('chargerLocation', fn () => $this->chargerLocation?->provider?->name),
            'station_provider_logo' => $this->chargingStation?->provider?->logo
                ?? $this->whenLoaded('chargerLocation', fn () => $this->chargerLocation?->provider?->logo)
                ?? ($this->station_provider_snapshot ? \App\Models\Provider::where('name', $this->station_provider_snapshot)->value('image') : null),

            // Region denormalized utk lokasi custom/home (bila reverse-geocode
            // berhasil). Dipakai mobile utk display "Kota, Provinsi" di riwayat.
            'province_name' => $this->whenLoaded('chargerLocation', fn () => $this->chargerLocation?->province_name),
            'city_name' => $this->whenLoaded('chargerLocation', fn () => $this->chargerLocation?->city_name),

            // Home vs public. Charging_station sessions (public SPKLU) are
            // always public; charger_location sessions are "home" when
            // location_on = 2 (private). Used by the dashboard for the
            // home/public cost split.
            'is_home_charging' => $this->charging_station_id !== null
                ? false
                : (int) ($this->chargerLocation?->location_on ?? 0) === 2,

            // Charger box spesifik terpilih user (mobile picker) — snapshot
            // per-sesi. Sumber kebenaran AC/DC paling akurat (mengalahkan
            // tipe stasiun campuran). Null utk sesi lama/tanpa pilihan.
            'station_chargerbox_id' => $this->station_chargerbox_id_snapshot,
            'station_chargerbox_name' => $this->station_chargerbox_name_snapshot,
            'station_chargerbox_type' => $this->station_chargerbox_type_snapshot,

            // Tipe arus (AC/DC) deterministik — turunan cascade yang sama dgn
            // filter backend (charger.typeCharger → station.type_charge → snapshot).
            // UI pakai ini utk badge tanpa heuristic sendiri.
            'charging_type' => $this->charging_type,

            // Kendaraan (opsional — sesi mobile tanpa vehicle).
            'vehicle_id' => $this->vehicle_id,
            'battery_id' => $this->battery_id,
            'battery' => $this->whenLoaded('battery', function () {
                return new BatteryResource($this->battery);
            }),
            'vehicle' => $this->whenLoaded('vehicle', function () {
                return [
                    'id' => $this->vehicle?->id,
                    'license_plate' => $this->vehicle?->license_plate,
                    // model_vehicle wajib di-serve: client (VehicleDto.modelVehicle)
                    // memerlukan field ini utk filter by model + badge nama model
                    // di list riwayat. Sebelumnya absent → filter modelVehicleId
                    // mobile selalu null (tidak pernah match).
                    'model_vehicle' => $this->vehicle?->modelVehicle ? [
                        'id' => $this->vehicle->modelVehicle->id,
                        'name' => $this->vehicle->modelVehicle->name,
                        'brand_vehicle_id' => $this->vehicle->modelVehicle->brand_vehicle_id,
                    ] : null,
                    // type_vehicle + battery_capacity dibutuhkan client utk
                    // hitung losses (%) & efisiensi (km/kWh). Preseden LossesChart.
                    // Di-serve langsung (bukan whenLoaded nested) karena sudah
                    // eager-load via controller.
                    'type_vehicle' => $this->vehicle?->typeVehicle ? [
                        'id' => $this->vehicle->typeVehicle->id,
                        'name' => $this->vehicle->typeVehicle->name,
                        'battery_capacity' => isset($this->vehicle->typeVehicle->battery_capacity)
                            ? (float) $this->vehicle->typeVehicle->battery_capacity
                            : null,
                    ] : null,
                ];
            }),

            // Pengukuran sesi (semua opsional — input cepat lapangan).
            'km_before' => isset($this->km_before) ? (float) $this->km_before : null,
            'km_now' => isset($this->km_now) ? (float) $this->km_now : null,
            'start_charging_now' => isset($this->start_charging_now) ? (float) $this->start_charging_now : null,
            'finish_charging_now' => isset($this->finish_charging_now) ? (float) $this->finish_charging_now : null,
            'finish_charging_before' => isset($this->finish_charging_before) ? (float) $this->finish_charging_before : null,
            'is_finish_charging' => (bool) $this->is_finish_charging,

            // Energi & biaya.
            'kwh' => isset($this->kWh) ? (float) $this->kWh : null,
            'is_kwh_measured' => (bool) $this->is_kwh_measured,
            'meter_before' => isset($this->meter_before) ? (float) $this->meter_before : null,
            'tariff_id' => $this->tariff_id,
            'parking_cost' => isset($this->parking) ? (float) $this->parking : null,
            'street_lighting_tax' => isset($this->street_lighting_tax) ? (float) $this->street_lighting_tax : null,
            'value_added_tax' => isset($this->value_added_tax) ? (float) $this->value_added_tax : null,
            'admin_cost' => isset($this->admin_cost) ? (float) $this->admin_cost : null,
            'total_cost' => isset($this->total_cost) ? (float) $this->total_cost : null,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
