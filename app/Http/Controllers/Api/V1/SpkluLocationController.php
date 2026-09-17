<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\SpkluLocationResource;
use App\Models\ChargingStation;
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
        $query = ChargingStation::with(['chargerBoxes.connectors', 'provider'])
            ->where('source', config('spklu.serving_source'))
            ->whereNotNull('latitude')
            ->whereNotNull('longitude');

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
                'meta' => [
                    'data_version' => $this->computeDataVersion(),
                ],
            ]);
    }

    /**
     * Versi dataset — dipakai mobile utk memutuskan perlu fetch penuh atau tidak.
     * Format: md5(count + '|' + max(updated_at))
     */
    private function computeDataVersion(): string
    {
        $stats = ChargingStation::where('source', config('spklu.serving_source'))
            ->selectRaw('COUNT(*) as cnt, MAX(updated_at) as latest')
            ->first();

        $raw = ($stats->cnt ?? 0) . '|' . ($stats->latest ?? '');

        return md5($raw);
    }

    /**
     * Marker sinkronisasi delta klien — hanya berubah saat ada penambahan/
     * penghapusan lokasi atau edit master data (bukan perubahan status
     * availabilitas). Nilai timestamp dari DB (naive UTC) di-normalize ke
     * ISO-8601 UTC.
     *
     * @return array{count: int, max_created_at: string|null, max_updated_at: string|null}
     */
    private function computeSyncMarkers(): array
    {
        $stats = ChargingStation::query()
            ->where('source', config('spklu.serving_source'))
            ->selectRaw('COUNT(*) AS cnt, MAX(created_at) AS max_created, MAX(updated_at) AS max_updated')
            ->first();

        return [
            'count' => (int) ($stats->cnt ?? 0),
            'max_created_at' => $stats->max_created
                ? \Illuminate\Support\Carbon::rawParse($stats->max_created, 'UTC')->toISOString()
                : null,
            'max_updated_at' => $stats->max_updated
                ? \Illuminate\Support\Carbon::rawParse($stats->max_updated, 'UTC')->toISOString()
                : null,
        ];
    }

    /**
     * Manifest sinkron ringan (< 1 KB) — dibandingkan klien dengan marker
     * lokal untuk memutuskan perlu delta/full fetch atau tidak.
     */
    public function syncManifest()
    {
        return response()->json([
            'status' => 'success',
            'data' => $this->computeSyncMarkers(),
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
                'data_version' => $this->computeDataVersion(),
            ],
        ]);
    }
}
