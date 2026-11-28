<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\VoltronSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Ingest machine-to-machine — pipeline runner (Mac, APScheduler) meng-upload
 * snapshot fetch Voltron yang sudah lolos gate validate.py ke backend.
 *
 * Auth: bearer SPKLU_INGEST_TOKEN (env, constant-time compare) — pola
 * preceden SPKLU_COORD_SECRET; bukan Sanctum karena tanpa konteks user.
 * Token env kosong = endpoint menolak semua request (fitur mati).
 *
 * Defense in depth: gate runner (validate.py) + gate jumlah di
 * VoltronSyncService::import() (≥80% baseline).
 */
class InternalSpkluIngestController extends Controller
{
    public function storeVoltron(Request $request, VoltronSyncService $service): JsonResponse
    {
        $expected = (string) config('spklu.ingest_token');
        $given = (string) $request->bearerToken();

        if ($expected === '' || ! hash_equals($expected, $given)) {
            return response()->json([
                'status' => 'error',
                'message' => 'token ingest tidak valid',
            ], 401);
        }

        $snapshot = $request->json()->all();
        if (! is_array($snapshot) || ! isset($snapshot['points']) || ! is_array($snapshot['points'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'body harus snapshot {"metadata":..., "points":[]}',
            ], 422);
        }

        try {
            $summary = $service->sync($snapshot);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'snapshot ditolak',
                'detail' => $e->errors(),
            ], 422);
        } catch (\Carbon\Exceptions\InvalidFormatException $e) {
            // metadata.diambil_pada bukan tanggal valid — masalah snapshot,
            // bukan masalah server: balas 422 agar runner tahu payload-nya
            // yang bermasalah. Kegagalan lain (DB down, dll) bubble ke handler
            // 500 framework agar incident server tetap tampak sebagai 5xx.
            return response()->json([
                'status' => 'error',
                'message' => 'snapshot ditolak',
                'detail' => ['metadata.diambil_pada' => 'bukan tanggal valid'],
            ], 422);
        }

        return response()->json([
            'status' => 'ok',
            'import' => $summary['import'],
            'hydrate' => $summary['hydrate'],
        ]);
    }
}
