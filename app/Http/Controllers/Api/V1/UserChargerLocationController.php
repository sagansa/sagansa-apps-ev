<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Controller;
use App\Http\Resources\UserChargerLocationResource;
use App\Models\Charge;
use App\Models\Charger;
use App\Models\ChargerLocation;
use App\Models\ChargingStation;
use App\Models\ChargingStationCharger;
use App\Models\CurrentCharger;
use App\Models\TypeCharger;
use App\Models\PowerCharger;
use App\Services\GeocodingService;
use App\Services\RegionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * CRUD lokasi custom/home milik user (user-scope) utk form "Lokasi Custom /
 * Pribadi" mobile. Alur 2-langkah: mobile create lokasi di sini dulu →
 * dapat charger_location_id → POST /charging-sessions memakai id tsb.
 *
 * Tidak menimpa admin ChargerLocationController (Filament tetap aman).
 */
class UserChargerLocationController extends Controller
{
    public function __construct(
        private readonly GeocodingService $geocoding,
        private readonly RegionResolver $regions,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $locations = ChargerLocation::with('provider', 'chargers.currentCharger', 'chargers.typeCharger', 'chargers.powerCharger')
            ->where('user_id', Auth::id())
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'My charging locations retrieved successfully',
            'data' => UserChargerLocationResource::collection($locations),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'address' => 'nullable|string|max:500',
            'provider_id' => 'required|exists:providers,id',
            'is_home_charging' => 'nullable|boolean',
            'chargers' => 'nullable|array',
            'chargers.*.current' => 'required_with:chargers|string|in:AC,DC',
            'chargers.*.type' => 'required_with:chargers|string|max:100',
            'chargers.*.power' => 'required_with:chargers|string|max:100',
            'chargers.*.unit' => 'nullable|integer|min:1',
        ]);

        $isHome = $request->boolean('is_home_charging');

        $region = $this->geocoding->resolveRegion(
            (float) $validated['latitude'],
            (float) $validated['longitude'],
        );
        $provinceId = $this->regions->resolveProvince($region['province']);
        $cityId = $this->regions->resolveCity($region['city'], $provinceId);

        $location = DB::transaction(function () use ($validated, $isHome, $region, $provinceId, $cityId) {
            $location = ChargerLocation::create([
                'name' => $validated['name'],
                'address' => $validated['address'] ?? null,
                'latitude' => $validated['latitude'],
                'longitude' => $validated['longitude'],
                'provider_id' => $validated['provider_id'] ?? null,
                'location_on' => $isHome ? 2 : 1,
                'status' => 1,
                'user_id' => Auth::id(),
                'data_source' => 'user_custom',
                'verification_status' => 'user_custom',
                'province_id' => $provinceId,
                'city_id' => $cityId,
                'province_name' => $region['province'],
                'city_name' => $region['city'],
            ]);

            if (! empty($validated['chargers'])) {
                foreach ($validated['chargers'] as $chargerData) {
                    $this->createChargerForLocation($location->id, $chargerData);
                }
            }

            return $location;
        });

        $location->load('provider', 'chargers.currentCharger', 'chargers.typeCharger', 'chargers.powerCharger');

        return response()->json([
            'success' => true,
            'message' => 'Charging location created successfully',
            'data' => new UserChargerLocationResource($location),
        ], 201);
    }

    /**
     * Buat (atau pakai ulang) lokasi charger milik user DARI data All Map
     * (charging_stations — sumber yang sama dengan map SPKLU di apps).
     *
     * Dipakai saat pencatatan sesi charging: list utama form adalah charger
     * location milik user; bila tidak ada yang cocok, user membuat lokasi baru
     * dengan mengambil data dari All Map. Data station dipindahkan ke
     * charger_locations + seluruh charger box-nya ke chargers di dalam satu
     * transaksi — reuse dulu yang sudah ada (lokasi per station, charger per
     * charger box); charger box BARU dari map ikut ditambahkan. Sesi
     * berikutnya tinggal menunjuk charger_location_id + charger_id — alur
     * sama dgn form Filament ChargeResource.
     */
    public function storeFromStation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'charging_station_id' => 'required|integer',
        ]);

        $station = ChargingStation::with('chargers.connectors')->find($validated['charging_station_id']);
        if (! $station) {
            return response()->json([
                'success' => false,
                'message' => 'Charging station tidak ditemukan.',
            ], 404);
        }

        $location = DB::transaction(function () use ($station) {
            $location = $this->resolveLocationForStation($station);

            // Pindahkan SEMUA charger box station: yang sudah ada dipakai
            // ulang, yang baru di map ditambahkan ("bila tidak ada atau ada
            // yang baru, baru ditambahkan").
            foreach ($station->chargers as $box) {
                $this->resolveChargerForBox($location, $box);
            }

            return $location;
        });

        $location->load('provider', 'chargers.currentCharger', 'chargers.typeCharger', 'chargers.powerCharger');

        return response()->json([
            'success' => true,
            'message' => 'Charging location created from station successfully',
            'data' => new UserChargerLocationResource($location),
        ], 201);
    }

    /**
     * Detail lokasi milik user (utk mobile fetch daftar charger lokasi
     * terpilih di form sesi). Resource-nya memuat chargers + current/type/
     * power — TIDAK memakai GET /charging-locations/{id} legacy karena
     * ChargerLocationResource tidak menyertakan chargers.
     */
    public function show(ChargerLocation $chargingLocation): JsonResponse
    {
        if (! $this->owns($chargingLocation)) {
            return $this->forbidden();
        }

        $chargingLocation->load('provider', 'chargers.currentCharger', 'chargers.typeCharger', 'chargers.powerCharger');

        return response()->json([
            'success' => true,
            'message' => 'Charging location retrieved successfully',
            'data' => new UserChargerLocationResource($chargingLocation),
        ]);
    }

    public function addCharger(Request $request, ChargerLocation $chargingLocation): JsonResponse
    {
        if (! $this->owns($chargingLocation)) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'current' => 'required|string|in:AC,DC',
            'type' => 'required|string|max:100',
            'power' => 'required|string|max:100',
            'unit' => 'nullable|integer|min:1',
        ]);

        $charger = $this->createChargerForLocation($chargingLocation->id, $validated);
        $charger->load('currentCharger', 'typeCharger', 'powerCharger');

        return response()->json([
            'success' => true,
            'message' => 'Charger added successfully',
            'data' => [
                'id' => $charger->id,
                'current_charger_id' => $charger->current_charger_id,
                'type_charger_id' => $charger->type_charger_id,
                'power_charger_id' => $charger->power_charger_id,
                'unit' => $charger->unit,
                'current_charger' => $charger->currentCharger ? [
                    'id' => $charger->currentCharger->id,
                    'name' => $charger->currentCharger->name,
                ] : null,
                'type_charger' => $charger->typeCharger ? [
                    'id' => $charger->typeCharger->id,
                    'name' => $charger->typeCharger->name,
                ] : null,
                'power_charger' => $charger->powerCharger ? [
                    'id' => $charger->powerCharger->id,
                    'name' => $charger->powerCharger->name,
                ] : null,
            ],
        ], 201);
    }

    public function references(): JsonResponse
    {
        $currents = CurrentCharger::orderBy('name')->get(['id', 'name']);
        $types = TypeCharger::with('currentCharger')->orderBy('name')->get(['id', 'name', 'current_charger_id']);
        $powers = PowerCharger::with('typeCharger')->orderBy('name')->get(['id', 'name', 'type_charger_id']);

        return response()->json([
            'success' => true,
            'data' => [
                'currents' => $currents,
                'types' => $types,
                'powers' => $powers,
            ],
        ]);
    }

    public function update(Request $request, ChargerLocation $chargingLocation): JsonResponse
    {
        if (! $this->owns($chargingLocation)) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'latitude' => 'sometimes|numeric',
            'longitude' => 'sometimes|numeric',
            'address' => 'sometimes|nullable|string|max:500',
            'provider_id' => 'sometimes|required|exists:providers,id',
            'is_home_charging' => 'sometimes|nullable|boolean',
        ]);

        $data = $validated;

        if (array_key_exists('latitude', $validated) || array_key_exists('longitude', $validated)) {
            $lat = $validated['latitude'] ?? $chargingLocation->latitude;
            $lng = $validated['longitude'] ?? $chargingLocation->longitude;
            if ($lat !== null && $lng !== null) {
                $region = $this->geocoding->resolveRegion((float) $lat, (float) $lng);
                $provinceId = $this->regions->resolveProvince($region['province']);
                $cityId = $this->regions->resolveCity($region['city'], $provinceId);
                $data['province_id'] = $provinceId;
                $data['city_id'] = $cityId;
                $data['province_name'] = $region['province'];
                $data['city_name'] = $region['city'];
            }
        }

        if (array_key_exists('is_home_charging', $validated)) {
            $data['location_on'] = $request->boolean('is_home_charging') ? 2 : 1;
            unset($data['is_home_charging']);
        }

        $chargingLocation->update($data);
        $chargingLocation->load('provider');

        return response()->json([
            'success' => true,
            'message' => 'Charging location updated successfully',
            'data' => new UserChargerLocationResource($chargingLocation),
        ]);
    }

    public function destroy(Request $request, ChargerLocation $chargingLocation): JsonResponse
    {
        if (! $this->owns($chargingLocation)) {
            return $this->forbidden();
        }
        $chargingLocation->delete();

        return response()->json([
            'success' => true,
            'message' => 'Charging location deleted successfully',
        ]);
    }

    /**
     * Lokasi charger milik user untuk station map yang sama. Reuse yang
     * sudah ada (dibuat lewat endpoint ini sebelumnya) — bila belum ada,
     * pindahkan data station map ke charger_locations.
     */
    private function resolveLocationForStation(ChargingStation $station): ChargerLocation
    {
        // lockForUpdate: dua request konkuren (double-tap, koneksi lambat)
        // untuk user+station yang sama diserialisasi di dalam transaksi —
        // yang kedua menunggu commit yang pertama lalu menemukan lokasinya,
        // bukan membuat duplikat.
        $existing = ChargerLocation::where('user_id', Auth::id())
            ->where('charging_station_id', $station->id)
            ->lockForUpdate()
            ->first();
        if ($existing) {
            return $existing;
        }

        $provinceId = $this->regions->resolveProvince($station->provinsi);

        return ChargerLocation::create([
            'name' => $station->nama_lokasi,
            'address' => $station->alamat,
            'latitude' => $station->latitude,
            'longitude' => $station->longitude,
            'provider_id' => $station->provider_id,
            'location_on' => 1,
            'status' => 1,
            'user_id' => Auth::id(),
            'data_source' => 'charging_station',
            'verification_status' => 'pending_verification',
            'charging_station_id' => $station->id,
            'province_name' => $station->provinsi,
            'city_name' => $station->kabupaten_kota,
            'province_id' => $provinceId,
            'city_id' => $this->regions->resolveCity($station->kabupaten_kota, $provinceId),
        ]);
    }

    /**
     * Charger untuk satu charger box station pada lokasi tersebut. Urutan:
     * (1) charger yang sudah terhubung ke box ini, (2) charger dgn
     * spesifikasi sama (arus-tipe-daya — hasil input Filament/custom yang
     * belum punya identitas box, di-backfill), (3) tambah baru dari data box
     * All Map.
     */
    private function resolveChargerForBox(ChargerLocation $location, ChargingStationCharger $box): Charger
    {
        $boxKey = filled($box->chargerbox_id) ? $box->chargerbox_id : (string) $box->id;

        $existing = Charger::where('charger_location_id', $location->id)
            ->where('station_chargerbox_id', $boxKey)
            ->first();
        if ($existing) {
            return $existing;
        }

        [$current, $type, $power] = $this->deriveChargerSpecs($box);

        $existing = Charger::where('charger_location_id', $location->id)
            ->whereHas('currentCharger', fn ($q) => $q->where('name', $current))
            ->whereHas('typeCharger', fn ($q) => $q->where('name', $type))
            ->whereHas('powerCharger', fn ($q) => $q->where('name', $power))
            ->first();
        if ($existing) {
            $existing->update(['station_chargerbox_id' => $boxKey]);

            return $existing;
        }

        $currentCharger = CurrentCharger::firstOrCreate(['name' => $current]);
        $typeCharger = TypeCharger::firstOrCreate(
            ['name' => $type, 'current_charger_id' => $currentCharger->id],
        );
        $powerCharger = PowerCharger::firstOrCreate(
            ['name' => $power, 'type_charger_id' => $typeCharger->id],
        );

        return Charger::create([
            'charger_location_id' => $location->id,
            'current_charger_id' => $currentCharger->id,
            'type_charger_id' => $typeCharger->id,
            'power_charger_id' => $powerCharger->id,
            'unit' => max(1, (int) ($box->jumlah_charger ?? 1)),
            'station_chargerbox_id' => $boxKey,
        ]);
    }

    /**
     * Turunkan (arus, tipe konektor, daya) chargers dari data charger box
     * All Map. Arus dari type_charge canonical (fallback AC); tipe konektor
     * dari konektor box (CCS2/Chademo/Type 2/...) bila tersedia; daya dari
     * normalisasi watt.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function deriveChargerSpecs(ChargingStationCharger $box): array
    {
        $current = Charge::resolveCanonicalTypeCharge($box->type_charge) ?? 'AC';
        $type = $box->connectors->first()?->nama_konektor ?: $current;

        return [$current, $type, $this->derivePowerName($box->watt)];
    }

    /** Normalisasi watt ("50 kW", "50000", "50.000") → "50 kW"; fallback "Standard". */
    private function derivePowerName(?string $watt): string
    {
        $digits = preg_replace('/[^\d.]/', '', (string) $watt);
        if ($digits !== '' && is_numeric($digits) && (float) $digits > 0) {
            $value = (float) $digits;
            if ($value >= 1000) {
                $value /= 1000;
            }
            $value = round($value, 1);

            return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.').' kW';
        }

        return 'Standard';
    }

    private function createChargerForLocation(string $locationId, array $data): Charger
    {
        $currentCharger = CurrentCharger::firstOrCreate(
            ['name' => $data['current']],
        );

        $typeCharger = TypeCharger::firstOrCreate(
            ['name' => $data['type'], 'current_charger_id' => $currentCharger->id],
            ['name' => $data['type'], 'current_charger_id' => $currentCharger->id],
        );

        $powerCharger = PowerCharger::firstOrCreate(
            ['name' => $data['power'], 'type_charger_id' => $typeCharger->id],
            ['name' => $data['power'], 'type_charger_id' => $typeCharger->id],
        );

        return Charger::create([
            'charger_location_id' => $locationId,
            'current_charger_id' => $currentCharger->id,
            'type_charger_id' => $typeCharger->id,
            'power_charger_id' => $powerCharger->id,
            'unit' => $data['unit'] ?? 1,
        ]);
    }

    private function owns(ChargerLocation $location): bool
    {
        return (string) $location->user_id === (string) Auth::id();
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized access to charging location',
        ], 403);
    }
}
