<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Controller;
use App\Models\PlnChargerLocation;
use Illuminate\Http\Request;

class PlnChargerLocationController extends Controller
{
    /**
     * Display a listing of PLN charging locations.
     */
    public function index(Request $request)
    {
        $activeChargerValues = $this->activeChargerValues();

        $query = PlnChargerLocation::with([
            'provider',
            'province',
            'locationCategory',
            'plnChargerLocationDetails' => function ($detailQuery) use ($activeChargerValues) {
                $detailQuery->whereIn('is_active_charger', $activeChargerValues);
            },
            'plnChargerLocationDetails.chargerCategory',
            'plnChargerLocationDetails.merkCharger',
            'plnChargerLocationDetails.chargingType',
        ])
            ->whereHas('plnChargerLocationDetails', function ($detailQuery) use ($activeChargerValues) {
                $detailQuery->whereIn('is_active_charger', $activeChargerValues);
            });

        if ($request->filled('provider_id')) {
            $query->where('provider_id', $request->provider_id);
        }

        if ($request->filled('province_id')) {
            $query->where('province_id', $request->province_id);
        }

        if ($request->filled('cluster_island_id')) {
            $query->where('cluster_island_id', $request->cluster_island_id);
        }

        if ($request->filled('location_category_id')) {
            $query->where('location_category_id', $request->location_category_id);
        }

        if ($request->filled('kategori_tol')) {
            $query->where('kategori_tol', $request->kategori_tol);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                    ->orWhere('address', 'like', '%' . $search . '%');
            });
        }

        $plnLocations = $query->get()->map(fn (PlnChargerLocation $location) => $this->transformLocation($location, $request));

        return response()->json([
            'success' => true,
            'message' => 'PLN charging locations retrieved successfully',
            'data' => $plnLocations,
        ]);
    }

    /**
     * Transform the PLN location into a consistent API response structure.
     */
    private function transformLocation(PlnChargerLocation $location, ?\Illuminate\Http\Request $request = null): array
    {
        $id = (string) $location->id;
        $encode = false;
        if ($request) {
            $encode = config('spklu.coord_codec_enforce', false)
                || strtolower(trim((string) $request->header('X-Coord-Codec'))) === 'v1';
        }

        $data = [
            'id' => $id,
            'name' => $location->name,
            'provider' => $location->provider ? [
                'image' => $location->provider->image,
                'name' => $location->provider->name,
            ] : null,
            'address' => $location->address ?? '',
            'kategori_tol' => $location->kategori_tol,
            'province' => $location->province ? [
                'name' => $location->province->name,
            ] : null,
            'location_category_name' => $location->locationCategory?->name,
            'details' => $location->plnChargerLocationDetails->map(function ($detail) {
                return [
                    'power' => $detail->power,
                    'is_active_charger' => $this->isActiveCharger($detail->is_active_charger),
                    'count_connector_charger' => $detail->count_connector_charger,
                    'operation_date' => $detail->operation_date,
                    'year' => $detail->year,
                    'charger_category' => $detail->chargerCategory?->name,
                    'merk_charger' => $detail->merkCharger?->name,
                    'charging_type' => $detail->chargingType?->name,
                ];
            })->all(),
        ];

        if ($encode) {
            $codec = new \App\Support\CoordinateCodec;
            $data['loc'] = $codec->encode($id, (float) $location->latitude, (float) $location->longitude);
        } else {
            $data['latitude'] = (float) $location->latitude;
            $data['longitude'] = (float) $location->longitude;
        }

        return $data;
    }

    private function activeChargerValues(): array
    {
        return ['Y', 'y', '1', 1, true, 'true', 'TRUE', 'aktif', 'Aktif', 'AKTIF'];
    }

    private function isActiveCharger(mixed $value): bool
    {
        return in_array($value, $this->activeChargerValues(), true);
    }
}
