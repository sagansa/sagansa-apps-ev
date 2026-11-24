<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ObdVehicle;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * CRUD registry kendaraan OBD2 PER-USER — dipindahkan dari internal storage
 * mobile ke backend (2026-11-20). Server-first: mobile memuat daftar ini saat
 * tab Cockpit/Kendaraan dibuka, dan setiap tambah/ubah/hapus ditulis ke sini
 * (cache lokal hanya fallback offline).
 *
 * Kill switch `OBD_ENABLED` (pola ObdAccessController): mati → 404.
 * Profil PID tidak disimpan di sini — mobile me-resolve dari katalog
 * `/obd/profiles` via model_vehicle_id/brand+model dengan fallback Universal.
 */
class ObdVehicleController extends Controller
{
    public function index(): JsonResponse
    {
        if (! config('obd.enabled')) {
            return response()->json(['message' => 'Not found'], 404);
        }

        // Rekonsiliasi registry (spec penyatuan 2026-11-24 §4): satu kendaraan
        // garasi = satu baris. Fail-open — kegagalan rekonsiliasi tidak boleh
        // menggagalkan daftar (app versi lama juga memanggil endpoint ini).
        try {
            app(\App\Services\Obd\VehicleRegistryReconciler::class)
                ->reconcileFor((int) Auth::id());
        } catch (\Throwable $e) {
            report($e);
        }

        $vehicles = ObdVehicle::query()
            ->where('user_id', Auth::id())
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $vehicles->map(fn (ObdVehicle $v) => self::serialize($v)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! config('obd.enabled')) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = self::validatePayload($request);
        $garageId = $validated['garage_vehicle_id'] ?? null;

        // Satu kendaraan garasi = satu baris (2026-11-24): create untuk
        // garage_vehicle_id yang SUDAH dipunyai user — dua perangkat, atau
        // respons create sebelumnya hilang di jaringan — mengembalikan baris
        // yang sudah ada alih-alih menambah baris baru (dulu: tiap create
        // melahirkan kendaraan ganda yang menetap di pemilih Cockpit).
        if ($garageId !== null && ($existing = self::ownedByGarage($garageId)) !== null) {
            return response()->json([
                'success' => true,
                'message' => 'OBD vehicle already exists',
                'data' => self::serialize($existing),
            ]);
        }

        try {
            $vehicle = ObdVehicle::create([
                ...$validated,
                'user_id' => Auth::id(),
                'sort_order' => $validated['sort_order'] ?? 0,
                'last_used_at' => $validated['last_used_at'] ?? null,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // Dua create bersamaan lolos dari cek di atas — unique index
            // (user_id, garage_vehicle_id) yang menahan; kembalikan pemenangnya.
            $vehicle = ($garageId !== null ? self::ownedByGarage($garageId) : null) ?? throw $e;
        }

        return response()->json([
            'success' => true,
            'message' => 'OBD vehicle created',
            'data' => self::serialize($vehicle),
        ], 201);
    }

    /** Baris user login untuk satu kendaraan garasi (yang paling terakhir dipakai duluan — data prasesudah unique index). */
    private static function ownedByGarage(string $garageId): ?ObdVehicle
    {
        return ObdVehicle::query()
            ->where('user_id', Auth::id())
            ->where('garage_vehicle_id', $garageId)
            ->orderByDesc('last_used_at')
            ->first();
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if (! config('obd.enabled')) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $vehicle = ObdVehicle::query()
            ->where('user_id', Auth::id())
            ->where('id', $id)
            ->first();

        if ($vehicle === null) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $validated = self::validatePayload($request);
        $vehicle->fill($validated)->save();

        return response()->json([
            'success' => true,
            'message' => 'OBD vehicle updated',
            'data' => self::serialize($vehicle),
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        if (! config('obd.enabled')) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $deleted = ObdVehicle::query()
            ->where('user_id', Auth::id())
            ->where('id', $id)
            ->delete();

        if (! $deleted) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'OBD vehicle deleted',
        ]);
    }

    /** Validasi payload mobile/Filament — ketat tapi longgar utk JSON bentukan. */
    private static function validatePayload(Request $request): array
    {
        return $request->validate([
            'model_vehicle_id' => 'nullable|integer|min:1',
            'garage_vehicle_id' => 'nullable|string|max:36',
            'nickname' => 'nullable|string|max:120',
            'brand_name' => 'nullable|string|max:80',
            'model_name' => 'nullable|string|max:120',
            'platform' => 'nullable|string|max:32',
            'usable_kwh' => 'nullable|numeric|between:0,200',
            'efficiency_km_per_kwh' => 'nullable|numeric|between:0,50',
            'invert_current' => 'nullable|boolean',
            'metric_config' => 'nullable|array|max:200',
            'discovered' => 'nullable|array',
            'usage' => 'nullable|array',
            'sort_order' => 'nullable|integer|min:0|max:10000',
            'last_used_at' => 'nullable|integer|min:0',
        ]);
    }

    /** Bentuk JSON kontrak mobile — field snake_case, JSON bentukan apa adanya. */
    private static function serialize(ObdVehicle $v): array
    {
        return [
            'id' => $v->id,
            'model_vehicle_id' => $v->model_vehicle_id !== null ? (string) $v->model_vehicle_id : null,
            'garage_vehicle_id' => $v->garage_vehicle_id,
            'nickname' => $v->nickname,
            'brand_name' => $v->brand_name,
            'model_name' => $v->model_name,
            'platform' => $v->platform,
            'usable_kwh' => $v->usable_kwh !== null ? (float) $v->usable_kwh : null,
            'efficiency_km_per_kwh' => $v->efficiency_km_per_kwh !== null ? (float) $v->efficiency_km_per_kwh : null,
            'invert_current' => (bool) $v->invert_current,
            'metric_config' => $v->metric_config,
            'discovered' => $v->discovered,
            'usage' => $v->usage,
            'sort_order' => $v->sort_order,
            'last_used_at' => $v->last_used_at,
            'created_at' => $v->created_at?->getTimestampMs(),
        ];
    }
}
