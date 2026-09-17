<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ObdAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ObdAccessController extends Controller
{
    /**
     * Check if the authenticated user has OBD2 access.
     *
     * Kill switch: returns 404 when OBD_ENABLED=false.
     * Access mode: nonaktif=denied, tester=allowlist check, semua=granted.
     */
    public function index(Request $request): JsonResponse
    {
        if (! config('obd.enabled')) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $user = Auth::user();
        if (! $user) {
            return response()->json(['granted' => false], 401);
        }

        $accessMode = config('obd.access_mode', 'nonaktif');

        // Mode "semua" — everyone gets access
        if ($accessMode === 'semua') {
            return response()->json([
                'granted' => true,
                'mode' => 'semua',
            ]);
        }

        // Mode "nonaktif" — no one gets access
        if ($accessMode === 'nonaktif') {
            return response()->json([
                'granted' => false,
                'mode' => 'nonaktif',
            ]);
        }

        // Mode "tester" — check allowlist (email or user ID) + obd_access table
        $allowlist = config('obd.allowlist', []);
        $isInAllowlist = in_array($user->email, $allowlist)
            || in_array((string) $user->id, $allowlist);

        if (! $isInAllowlist) {
            return response()->json([
                'granted' => false,
                'mode' => 'tester',
            ]);
        }

        // Check or create access record
        $access = ObdAccess::firstOrCreate(
            ['user_id' => $user->id],
            [
                'status' => 'granted',
                'granted_by' => 'allowlist',
                'granted_at' => now(),
            ]
        );

        return response()->json([
            'granted' => $access->isGranted(),
            'mode' => 'tester',
        ]);
    }
}
