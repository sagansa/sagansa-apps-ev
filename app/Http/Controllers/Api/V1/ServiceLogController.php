<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ServiceLogResource;
use App\Models\ServiceLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Catatan servis kendaraan (fitur Pro) — pola V1\StateOfHealthController:
 * validasi inline, ownership dicek inline (user_id), respons
 * {success, message, data, meta}. next_due dihitung server dari interval.
 */
class ServiceLogController extends Controller
{
    public const SERVICE_TYPES = [
        'coolant',
        'tires',
        'brakes',
        'cabin_filter',
        'gearbox_oil',
        'brake_fluid',
        'battery_12v',
        'other',
    ];

    /**
     * Display a listing of the user's service logs.
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        $query = $user->serviceLogs()
            ->with(['vehicle', 'photos'])
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc');

        if ($request->has('vehicle_id')) {
            $query->where('vehicle_id', $request->vehicle_id);
        }

        $serviceLogs = $query->paginate($request->per_page ?? 100);

        return response()->json([
            'success' => true,
            'message' => 'Service logs retrieved successfully',
            'data' => ServiceLogResource::collection($serviceLogs->getCollection()),
            'meta' => [
                'current_page' => $serviceLogs->currentPage(),
                'last_page' => $serviceLogs->lastPage(),
                'per_page' => $serviceLogs->perPage(),
                'total' => $serviceLogs->total(),
                'has_more' => $serviceLogs->hasMorePages(),
            ],
        ]);
    }

    /**
     * Store a newly created service log.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vehicle_id' => 'required|uuid|exists:vehicles,id',
            'date' => 'required|date',
            'odometer_km' => 'nullable|integer|min:0',
            'service_type' => 'nullable|string|in:' . implode(',', self::SERVICE_TYPES),
            'service_items' => 'nullable|array|max:20',
            'service_items.*' => 'string|max:100',
            'workshop' => 'nullable|string|max:150',
            'cost_rp' => 'nullable|integer|min:0',
            'notes' => 'nullable|string|max:2000',
            'interval_months' => 'nullable|integer|min:1|max:60',
            'interval_km' => 'nullable|integer|min:100|max:200000',
            'next_due_km' => 'nullable|integer|min:0',
        ]);

        $user = Auth::user();

        $vehicle = $user->vehicles()->find($validated['vehicle_id']);
        if (! $vehicle) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to vehicle',
            ], 403);
        }

        $validated['service_items'] = self::sanitizeItems($validated['service_items'] ?? null);

        $hasExplicitNextDueKm = $request->has('next_due_km') && $validated['next_due_km'] !== null;
        $computed = ServiceLog::computeNextDue(
            $validated['date'],
            $validated['odometer_km'] ?? null,
            $validated['interval_months'] ?? null,
            $validated['interval_km'] ?? null,
        );
        // next_due_km eksplisit dari form baru dipakai apa adanya (tidak ditimpa hitungan).
        if ($hasExplicitNextDueKm) {
            $computed['next_due_km'] = $validated['next_due_km'];
        } else {
            unset($validated['next_due_km']);
        }

        $serviceLog = $user->serviceLogs()->create(array_merge($validated, $computed));

        $serviceLog->load(['vehicle', 'photos']);

        return response()->json([
            'success' => true,
            'message' => 'Service log created successfully',
            'data' => new ServiceLogResource($serviceLog),
        ], 201);
    }

    /**
     * Display the specified service log.
     */
    public function show(ServiceLog $serviceLog): JsonResponse
    {
        $user = Auth::user();

        if ($serviceLog->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to service log',
            ], 403);
        }

        $serviceLog->load(['vehicle', 'photos']);

        return response()->json([
            'success' => true,
            'message' => 'Service log retrieved successfully',
            'data' => new ServiceLogResource($serviceLog),
        ]);
    }

    /**
     * Trim + buang item kosong (server-side tahan-linas). Null → null (tidak diubah).
     *
     * @param  mixed  $items
     * @return array|null
     */
    private static function sanitizeItems($items): ?array
    {
        if ($items === null) {
            return null;
        }
        if (! is_array($items)) {
            return [];
        }
        $cleaned = [];
        foreach ($items as $item) {
            if (! is_string($item)) {
                continue;
            }
            $trimmed = trim($item);
            if ($trimmed !== '') {
                $cleaned[] = mb_substr($trimmed, 0, 100);
            }
            if (count($cleaned) >= 20) {
                break;
            }
        }

        return $cleaned;
    }

    /**
     * Update the specified service log in storage.
     */
    public function update(Request $request, ServiceLog $serviceLog): JsonResponse
    {
        $user = Auth::user();

        if ($serviceLog->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to service log',
            ], 403);
        }

        $validated = $request->validate([
            'date' => 'sometimes|required|date',
            'odometer_km' => 'nullable|integer|min:0',
            'service_type' => 'nullable|string|in:' . implode(',', self::SERVICE_TYPES),
            'service_items' => 'nullable|array|max:20',
            'service_items.*' => 'string|max:100',
            'workshop' => 'nullable|string|max:150',
            'cost_rp' => 'nullable|integer|min:0',
            'notes' => 'nullable|string|max:2000',
            'interval_months' => 'nullable|integer|min:1|max:60',
            'interval_km' => 'nullable|integer|min:100|max:200000',
            'next_due_km' => 'nullable|integer|min:0',
        ]);

        $data = $validated;

        if (array_key_exists('service_items', $data)) {
            $data['service_items'] = self::sanitizeItems($data['service_items']);
        }

        // Interval/odometer/tanggal berubah → hitung ulang next_due dari nilai
        // final (input baru, fallback nilai tersimpan).
        $date = $data['date'] ?? optional($serviceLog->date)->toDateString();
        $odometerKm = array_key_exists('odometer_km', $data) ? $data['odometer_km'] : $serviceLog->odometer_km;
        $intervalMonths = array_key_exists('interval_months', $data) ? $data['interval_months'] : $serviceLog->interval_months;
        $intervalKm = array_key_exists('interval_km', $data) ? $data['interval_km'] : $serviceLog->interval_km;
        $computed = ServiceLog::computeNextDue(
            $date,
            $odometerKm,
            $intervalMonths,
            $intervalKm,
        );
        $data = array_merge($data, $computed);
        // next_due_km eksplisit menang atas hitungan interval (kompat: tanpa field → perilaku lama).
        if ($request->has('next_due_km')) {
            $data['next_due_km'] = $data['next_due_km'] ?? null;
        }

        $serviceLog->update($data);

        $serviceLog->load(['vehicle', 'photos']);

        return response()->json([
            'success' => true,
            'message' => 'Service log updated successfully',
            'data' => new ServiceLogResource($serviceLog),
        ]);
    }

    /**
     * Remove the specified service log from storage.
     */
    public function destroy(ServiceLog $serviceLog): JsonResponse
    {
        $user = Auth::user();

        if ($serviceLog->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to service log',
            ], 403);
        }

        $serviceLog->delete();

        return response()->json([
            'success' => true,
            'message' => 'Service log deleted successfully',
        ]);
    }
}
