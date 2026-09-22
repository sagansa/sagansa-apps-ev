<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\SpkluLocationResource;
use App\Models\ChargingStation;
use App\Support\SpkluServing;
use Illuminate\Http\Request;

/**
 * Serving SPKLU dari lapisan KANONIK (charging_stations + charging_station_chargers).
 *
 * Contract mobile dipertahankan: GET /api/v1/spklu → {status, data:[…], links,
 * meta}; id publik = charging_stations.id. Source bisa berganti (ESDM → lainnya)
 * tanpa mengubah kontrak ini — cukup rehydrate tabel kanonik.
 */
class SpkluLocationController extends Controller
{
    public function index(Request $request)
    {
        $query = SpkluServing::baseQuery();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nama_lokasi', 'like', "%{$search}%")
                    ->orWhere('alamat', 'like', "%{$search}%");
            });
        }

        if ($request->filled('provinsi')) {
            $query->where('provinsi', trim($request->provinsi));
        }

        if ($request->filled('type_charge')) {
            $types = $this->expandTypeChargeFilter($request->type_charge);
            $query->whereIn('type_charge', $types);
        }

        if ($request->filled('watt')) {
            $query->where('watt', $request->watt);
        }

        if ($request->filled('toll_category') || $request->filled('kategori_tol')) {
            $val = trim((string) ($request->toll_category ?? $request->kategori_tol));
            $query->where(function ($q) use ($val) {
                $q->where('toll_category', $val)
                  ->orWhere('kategori_tol', $val);
            });
        }

        if ($request->filled('location_category') || $request->filled('kategori_lokasi')) {
            $val = trim((string) ($request->location_category ?? $request->kategori_lokasi));
            $query->where(function ($q) use ($val) {
                $q->where('location_category', $val)
                  ->orWhere('kategori_lokasi', $val);
            });
        }

        // Marker WAJIB dihitung SEBELUM query baris dieksekusi (paginate):
        // perubahan yang terjadi setelah snapshot ini tertangkap sinkron
        // berikutnya (lihat D5 di spec).
        $markers = SpkluServing::computeSyncMarkers();

        $updatedSince = null;
        if ($request->filled('updated_since')) {
            try {
                $updatedSince = \Illuminate\Support\Carbon::rawParse(
                    $request->string('updated_since')->toString(),
                    'UTC'
                );
            } catch (\Throwable) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'updated_since harus ISO-8601 UTC yang valid',
                ], 422);
            }
            $query->where(function ($q) use ($updatedSince) {
                $q->where('created_at', '>', $updatedSince)
                    ->orWhere('updated_at', '>', $updatedSince);
            });
        }

        $lat = $request->filled('lat') ? (float) $request->lat : null;
        $lng = $request->filled('lng') ? (float) $request->lng : null;
        $radius = $request->filled('radius') ? (float) $request->radius : null;

        if ($lat !== null && $lng !== null && $radius !== null) {
            $haversine = "(6371 * acos(cos(radians($lat)) * cos(radians(latitude)) * cos(radians(longitude) - radians($lng)) + sin(radians($lat)) * sin(radians(latitude))))";

            $query->select('charging_stations.*')
                ->selectRaw($haversine.' AS distance')
                ->whereRaw($haversine.' <= '.$radius)
                ->orderBy('distance');
        }

        $perPage = $request->integer('per_page', 50);
        $page = $request->integer('page', 1);

        $locations = $query->paginate($perPage, ['*'], 'page', $page);

        return SpkluLocationResource::collection($locations)
            ->additional([
                'status' => 'success',
                'meta' => array_merge($markers, [
                'data_version' => SpkluServing::computeDataVersion(),
                ]),
            ]);
    }

    /**
     * Manifest sinkron ringan (< 1 KB) — dibandingkan klien dengan marker
     * lokal untuk memutuskan perlu delta/full fetch atau tidak.
     */
    public function syncManifest()
    {
        return response()->json([
            'status' => 'success',
            'data' => SpkluServing::computeSyncMarkers(),
        ]);
    }

    /**
     * Map filter speed id (dipakai mobile: "medium"/"fast"/"ultra_fast") ke
     * label type_charge verbatim ESDM yang tersimpan di canonical.
     */
    private function expandTypeChargeFilter(string $typeCharge): array
    {
        $map = [
            'medium' => ['Medium Charging', 'Slow Charging'],
            'standard' => ['Medium Charging', 'Slow Charging'],
            'fast' => ['Fast Charging'],
            'ultrafast' => ['Ultra Fast Charging'],
            'ultra_fast' => ['Ultra Fast Charging'],
        ];

        return $map[strtolower(trim($typeCharge))] ?? [$typeCharge];
    }

    public function show($id)
    {
        $location = ChargingStation::with(['chargerBoxes.connectors', 'provider'])
            ->where('source', config('spklu.serving_source'))
            ->findOrFail($id);

        return SpkluLocationResource::make($location)
            ->additional(['status' => 'success']);
    }

    public function metaFilters()
    {
        $provinces = ChargingStation::select('provinsi')
            ->where('source', config('spklu.serving_source'))
            ->whereNotNull('provinsi')
            ->distinct()
            ->orderBy('provinsi')
            ->pluck('provinsi');

        $chargeTypes = ChargingStation::select('type_charge')
            ->where('source', config('spklu.serving_source'))
            ->whereNotNull('type_charge')
            ->distinct()
            ->orderBy('type_charge')
            ->pluck('type_charge');

        $kategoriTol = ChargingStation::select('kategori_tol')
            ->where('source', config('spklu.serving_source'))
            ->whereNotNull('kategori_tol')
            ->distinct()
            ->orderBy('kategori_tol')
            ->pluck('kategori_tol');

        $kategoriLokasi = ChargingStation::select('kategori_lokasi')
            ->where('source', config('spklu.serving_source'))
            ->whereNotNull('kategori_lokasi')
            ->distinct()
            ->orderBy('kategori_lokasi')
            ->pluck('kategori_lokasi');

        return response()->json([
            'status' => 'success',
            'data' => [
                'provinces' => $provinces,
                'charge_types' => $chargeTypes->values(),
                'kategori_tol' => $kategoriTol->values(),
                'kategori_lokasi' => $kategoriLokasi->values(),
                'data_version' => SpkluServing::computeDataVersion(),
            ],
        ]);
    }
}
