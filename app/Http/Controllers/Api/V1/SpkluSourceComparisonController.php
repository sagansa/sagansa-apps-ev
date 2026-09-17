<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Controller;
use App\Services\SpkluSourceComparisonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * GET /api/v1/admin/spklu-source-comparison
 *
 * Perbandingan titik SPKLU antar sumber (esdm ↔ pln ↔ ocm) untuk admin:
 * kategori match_exact / match_near / candidate / conflict_far / unique,
 * ringkasan per pasangan + daftar item yang bisa difilter & dipaginasi.
 */
class SpkluSourceComparisonController extends Controller
{
    public function __construct(private SpkluSourceComparisonService $service) {}

    public function index(Request $request): JsonResponse
    {
        if (! Auth::user()?->hasRole('super_admin')) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'pair' => ['nullable', 'in:'.implode(',', SpkluSourceComparisonService::PAIRS)],
            'category' => ['nullable', 'in:'.implode(',', SpkluSourceComparisonService::CATEGORIES)],
            'source' => ['nullable', 'in:'.implode(',', [
                SpkluSourceComparisonService::SOURCE_ESDM,
                SpkluSourceComparisonService::SOURCE_PLN,
                SpkluSourceComparisonService::SOURCE_OCM,
            ])],
            'q' => ['nullable', 'string', 'max:200'],
            'min_distance_m' => ['nullable', 'integer', 'min:0'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
            'refresh' => ['nullable', 'boolean'],
        ]);

        $result = $this->service->compare(
            pair: $validated['pair'] ?? null,
            category: $validated['category'] ?? null,
            source: $validated['source'] ?? null,
            q: $validated['q'] ?? null,
            minDistanceM: $validated['min_distance_m'] ?? null,
            page: $validated['page'] ?? 1,
            perPage: $validated['per_page'] ?? 50,
            refresh: (bool) ($validated['refresh'] ?? false),
        );

        return response()->json([
            'status' => 'success',
            'data' => [
                'summary' => $result['summary'],
                'items' => $result['items'],
            ],
            'meta' => [
                'page' => $result['page'],
                'per_page' => $result['per_page'],
                'total' => $result['total'],
                'last_page' => $result['last_page'],
                'computed_at' => $result['computed_at'],
            ],
        ]);
    }
}
