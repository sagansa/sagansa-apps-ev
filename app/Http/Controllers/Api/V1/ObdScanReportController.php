<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ObdScanReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * POST /api/v1/obd/scan-reports — hasil scan/koneksi OBD2 dari mobile
 * (user ter-auth), otomatis dikirim setelah sesi berjalan. Kill switch
 * `OBD_ENABLED` (pola ObdAccessController): mati → 404, app fail-open.
 *
 * Payload dipercaya sebatas validasi ini — VIN boleh penuh (toggle privasi
 * user) atau null; sisanya data telemetri untuk database kendaraan.
 */
class ObdScanReportController extends Controller
{
    /** Nilai valid `profile_match` — mengikuti kontrak payload mobile. */
    public const PROFILE_MATCHES = ['seed', 'backend', 'universal', 'unknown'];

    public function store(Request $request): JsonResponse
    {
        if (! config('obd.enabled')) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'model_vehicle_id' => 'nullable|integer|min:1',
            'vin' => 'nullable|string|size:17',
            'vin_shared' => 'nullable|boolean',
            'adapter_type' => 'required|string|in:WIFI,BLE,SPP',
            'elm_protocol' => 'nullable|string|max:64',
            'profile_key' => 'nullable|string|max:64',
            'profile_id' => 'nullable|integer|min:1',
            'profile_version' => 'nullable|string|max:16',
            'profile_match' => 'required|string|in:' . implode(',', self::PROFILE_MATCHES),
            'ecus_responded' => 'nullable|array|max:32',
            'ecus_responded.*' => 'string|max:16',
            'supported_pids' => 'nullable|array|max:200',
            'unsupported_pids' => 'nullable|array|max:200',
            'supported_pids.*.mode' => 'required_with:supported_pids|integer|min:0|max:255',
            'supported_pids.*.pid' => 'required_with:supported_pids|integer|min:0|max:65535',
            'supported_pids.*.description' => 'nullable|string|max:120',
            'supported_pids.*.supported' => 'nullable|boolean',
            'supported_pids.*.raw_response' => 'nullable|string|max:200',
            'unsupported_pids.*.mode' => 'required_with:unsupported_pids|integer|min:0|max:255',
            'unsupported_pids.*.pid' => 'required_with:unsupported_pids|integer|min:0|max:65535',
            'unsupported_pids.*.description' => 'nullable|string|max:120',
            'unsupported_pids.*.supported' => 'nullable|boolean',
            'unsupported_pids.*.raw_response' => 'nullable|string|max:200',
            'discovery' => 'nullable|array|max:64',
            'dtc_codes' => 'nullable|array|max:64',
            'dtc_codes.*' => 'string|max:16',
            'atrv_voltage' => 'nullable|numeric|between:0,30',
            'app_version' => 'nullable|string|max:32',
            'device_platform' => 'nullable|string|in:android,ios',
            'session_started_at' => 'nullable|integer|min:0',
            'duration_ms' => 'nullable|integer|min:0|max:86400000',
            'captured_at' => 'nullable|integer|min:0',
        ]);

        // Raw hex respons bisa membengkak payload — buang sebelum simpan;
        // deskripsi PID cukup untuk analisis developer.
        foreach (['supported_pids', 'unsupported_pids'] as $key) {
            if (is_array($validated[$key] ?? null)) {
                $validated[$key] = array_map(static function (array $pid): array {
                    unset($pid['raw_response']);

                    return $pid;
                }, $validated[$key]);
            }
        }

        $report = Auth::user()->obdScanReports()->create([
            ...$validated,
            'vin_shared' => $request->boolean('vin_shared', true),
            'captured_at' => isset($validated['captured_at'])
                ? date('Y-m-d H:i:s', (int) ($validated['captured_at'] / 1000))
                : now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Scan report recorded',
            'data' => ['id' => $report->id],
        ], 201);
    }
}
