<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Controller;
use App\Models\OpenchargemapPoi;
use App\Services\OcmHarvestService;
use Illuminate\Http\Request;

class OcmNearbyController extends Controller
{
    public function __construct(private OcmHarvestService $harvestService) {}

    public function nearby(Request $request)
    {
        $lat = $request->query('lat');
        $lng = $request->query('lng');
        $radiusKm = $request->query('radius_km', 5);

        if ($lat === null || $lng === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'lat and lng are required',
            ], 400);
        }

        $lat = (float) $lat;
        $lng = (float) $lng;
        $radiusKm = min((float) $radiusKm, 20);

        $query = OpenchargemapPoi::query()
            ->select('openchargemap_pois.*')
            ->selectRaw('(6371000 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) AS distance', [$lat, $lng, $lat])
            ->whereRaw('(6371000 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) <= ?', [$lat, $lng, $lat, $radiusKm * 1000])
            ->orderBy('distance');

        $pois = $query->paginate(min(max($request->integer('per_page', 50), 1), 200));

        $items = $pois->getCollection()->map(function ($poi) {
            return [
                'id' => $poi->id,
                'ocm_id' => $poi->ocm_id,
                'title' => $poi->title,
                'latitude' => $poi->latitude,
                'longitude' => $poi->longitude,
                'address' => $poi->address_line1,
                'operator' => $poi->operator,
                'source' => 'openchargemap',
                'distance' => round($poi->distance, 1),
            ];
        });

        $harvestResult = $this->harvestService->harvestNearby($lat, $lng, $radiusKm);

        return response()->json([
            'status' => 'success',
            'data' => $items,
            'meta' => [
                'total' => $pois->total(),
                'per_page' => $pois->perPage(),
                'current_page' => $pois->currentPage(),
                'radius_km' => $radiusKm,
                'attribution' => 'Contains information by Open Charge Map, openchargemap.org, licensed under CC BY 4.0',
                'harvest' => $harvestResult,
            ],
        ]);
    }
}
