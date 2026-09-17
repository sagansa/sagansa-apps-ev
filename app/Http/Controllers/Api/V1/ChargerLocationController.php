<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Controller;
use App\Http\Resources\ChargerLocationResource;
use App\Models\ChargerLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ChargerLocationController extends Controller
{
    /**
     * Display a listing of charging locations.
     */
    public function index(Request $request)
    {
        $query = ChargerLocation::with([
            'provider',
            'province',
            'city',
            'district',
            'subdistrict',
            'postalCode',
            'chargers.powerCharger',
            'chargers.currentCharger',
            'chargers.typeCharger',
        ]);

        // Apply filters if provided
        if ($request->has('provider_id')) {
            $query->where('provider_id', $request->provider_id);
        }
        
        if ($request->has('province_id')) {
            $query->where('province_id', $request->province_id);
        }
        
        if ($request->has('city_id')) {
            $query->where('city_id', $request->city_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter for public locations only (location_on = 1 or 3)
        $query->whereIn('location_on', [1, 3]);

        $chargerLocations = $query->paginate($request->per_page ?? 15);

        return ChargerLocationResource::collection($chargerLocations)
            ->additional([
                'success' => true,
                'message' => 'Charging locations retrieved successfully',
            ]);
    }

    /**
     * Store a newly created charging location.
     */
    public function store(Request $request)
    {
        // Only admins can create charging locations
        if (!Auth::user()->hasRole('admin') && !Auth::user()->hasRole('super_admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access',
            ], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'provider_id' => 'required|exists:providers,id',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'province_id' => 'required|exists:provinces,id',
            'city_id' => 'required|exists:cities,id',
            'address' => 'required|string|max:500',
            'status' => 'required|integer',
            'location_on' => 'required|integer',
        ]);

        $chargerLocation = ChargerLocation::create([
            'name' => $request->name,
            'provider_id' => $request->provider_id,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'province_id' => $request->province_id,
            'city_id' => $request->city_id,
            'address' => $request->address,
            'status' => $request->status,
            'location_on' => $request->location_on,
            'user_id' => Auth::id(),
        ]);

        $chargerLocation->load([
            'provider',
            'province',
            'city',
            'chargers'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Charging location created successfully',
            'data' => $chargerLocation,
        ], 201);
    }

    /**
     * Display the specified charging location.
     */
    public function show(ChargerLocation $chargerLocation)
    {
        $chargerLocation->load([
            'provider',
            'province',
            'city',
            'district',
            'subdistrict',
            'postalCode',
            'chargers.powerCharger',
            'chargers.currentCharger',
            'chargers.typeCharger',
        ]);

        return ChargerLocationResource::make($chargerLocation)
            ->additional([
                'success' => true,
                'message' => 'Charging location retrieved successfully',
            ]);
    }

    /**
     * Update the specified charging location in storage.
     */
    public function update(Request $request, ChargerLocation $chargerLocation)
    {
        // Only admins can update charging locations
        if (!Auth::user()->hasRole('admin') && !Auth::user()->hasRole('super_admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access',
            ], 403);
        }

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'provider_id' => 'sometimes|exists:providers,id',
            'latitude' => 'sometimes|numeric',
            'longitude' => 'sometimes|numeric',
            'province_id' => 'sometimes|exists:provinces,id',
            'city_id' => 'sometimes|exists:cities,id',
            'address' => 'sometimes|string|max:500',
            'status' => 'sometimes|integer',
            'location_on' => 'sometimes|integer',
        ]);

        $chargerLocation->update($request->only([
            'name',
            'provider_id',
            'latitude',
            'longitude',
            'province_id',
            'city_id',
            'address',
            'status',
            'location_on'
        ]));

        $chargerLocation->load([
            'provider',
            'province',
            'city',
            'chargers'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Charging location updated successfully',
            'data' => $chargerLocation,
        ]);
    }

    /**
     * Remove the specified charging location from storage.
     */
    public function destroy(ChargerLocation $chargerLocation)
    {
        // Only admins can delete charging locations
        if (!Auth::user()->hasRole('admin') && !Auth::user()->hasRole('super_admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access',
            ], 403);
        }

        $chargerLocation->delete();

        return response()->json([
            'success' => true,
            'message' => 'Charging location deleted successfully',
        ]);
    }

    /**
     * Get charging locations near the specified coordinates.
     */
    public function nearby(Request $request)
    {
        $request->validate([
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'radius' => 'nullable|numeric|min:1|max:50', // in kilometers
        ]);

        $latitude = $request->latitude;
        $longitude = $request->longitude;
        $radius = $request->radius ?? 10; // default to 10 km if not provided

        $nearbyChargers = ChargerLocation::with([
            'provider',
            'city',
            'chargers.powerCharger',
            'chargers.currentCharger',
            'chargers.typeCharger',
        ])
        ->whereIn('location_on', [1, 3])  // Only public locations
        ->where('status', '<>', 3)        // Exclude closed locations
        ->near($latitude, $longitude, $radius)
        ->get();

        // Add distance to each location
        $nearbyChargers->each(function ($charger) use ($latitude, $longitude) {
            $charger->distance = $this->calculateDistance(
                $latitude,
                $longitude,
                $charger->latitude,
                $charger->longitude
            );
        });

        return ChargerLocationResource::collection($nearbyChargers)
            ->additional([
                'success' => true,
                'message' => 'Nearby charging locations retrieved successfully',
            ]);
    }

    /**
     * Calculate distance between two coordinates using Haversine formula.
     */
    private function calculateDistance($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371; // km

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c; // Distance in km
    }
}