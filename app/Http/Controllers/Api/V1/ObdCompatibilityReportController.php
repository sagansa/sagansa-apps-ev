<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ObdCompatibilityReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ObdCompatibilityReportController extends Controller
{
    /**
     * Submit an anonymous OBD compatibility report.
     * Opt-in users include their user_id; anonymous reports have null user_id.
     */
    public function store(Request $request): JsonResponse
    {
        if (! config('obd.enabled')) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $request->validate([
            'model_vehicle_id' => 'nullable|exists:model_vehicles,id',
            'platform' => 'required|string|max:32',
            'adapter_type' => 'required|in:wifi,ble,spp',
            'supported_pids' => 'required|array',
            'supported_pids.*.mode' => 'required|integer',
            'supported_pids.*.pid' => 'required|integer',
            'unsupported_pids' => 'nullable|array',
            'opt_in' => 'boolean',
        ]);

        $user = Auth::user();
        $optIn = $request->boolean('opt_in', false);

        $report = ObdCompatibilityReport::create([
            'report_id' => (string) Str::uuid(),
            'user_id' => ($optIn && $user) ? $user->id : null,
            'model_vehicle_id' => $request->input('model_vehicle_id'),
            'platform' => $request->input('platform'),
            'adapter_type' => $request->input('adapter_type'),
            'supported_pids' => $request->input('supported_pids'),
            'unsupported_pids' => $request->input('unsupported_pids'),
            'opt_in' => $optIn,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Compatibility report submitted successfully',
            'data' => [
                'report_id' => $report->report_id,
            ],
        ], 201);
    }
}
