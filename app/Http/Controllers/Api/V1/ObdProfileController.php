<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ObdVehicleProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

class ObdProfileController extends Controller
{
    /**
     * List public OBD vehicle profiles with optional filtering.
     * Supports ETag for caching.
     */
    public function index(Request $request): JsonResponse
    {
        if (! config('obd.enabled')) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $query = ObdVehicleProfile::active();

        // Filter by platform
        if ($request->filled('platform')) {
            $query->forPlatform($request->input('platform'));
        }

        // Filter by grade
        if ($request->filled('grade')) {
            $query->forGrade($request->input('grade'));
        }

        // Filter by model_vehicle_id
        if ($request->filled('model_vehicle_id')) {
            $query->where('model_vehicle_id', $request->input('model_vehicle_id'));
        }

        $profiles = $query->with('modelVehicle')->get();

        // ETag support for client-side caching
        $etag = md5($profiles->toJson());
        if ($request->header('If-None-Match') === $etag) {
            return response()->null();
        }

        $response = Response::json([
            'success' => true,
            'data' => $profiles->map(fn ($p) => [
                'id' => $p->id,
                'model_vehicle_id' => $p->model_vehicle_id,
                'model_vehicle_name' => $p->modelVehicle?->name,
                'platform' => $p->platform,
                'vin_pattern' => $p->vin_pattern,
                'year_min' => $p->year_min,
                'year_max' => $p->year_max,
                'grade' => $p->grade,
                'version' => $p->version,
                'status' => $p->status,
                'pid_list' => $p->pid_list,
                'metadata' => $p->metadata,
            ]),
        ]);

        $response->headers->set('ETag', $etag);

        return $response;
    }
}
