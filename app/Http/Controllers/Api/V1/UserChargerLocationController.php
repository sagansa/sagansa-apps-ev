<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Controller;
use App\Http\Resources\UserChargerLocationResource;
use App\Models\Charger;
use App\Models\ChargerLocation;
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
