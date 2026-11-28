<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Controller;
use App\Http\Resources\ProviderResource;
use App\Models\Provider;
use Illuminate\Http\Request;

class ProviderController extends Controller
{
    public function index(Request $request)
    {
        // Hitung dari komposisi SERVING (SpkluServing) — bukan spklu_locations —
        // agar angka "lokasi" = stasiun yang benar-benar tayang di peta:
        // master PLN minus slice ter-override (mis. (VOLTRON)) plus hasil
        // adaptor fetch (source='voltron' dst.). Count dari spklu_locations
        // membuat layar "Daftar Provider SPKLU" mobile menampilkan angka basi.
        $counts = \App\Support\SpkluServing::applyComposition(
            \App\Models\ChargingStation::query()
        )
            ->whereNotNull('latitude')   // filter koordinat SAMA dgn baseQuery
            ->whereNotNull('longitude')  // & computeSyncMarkers — count = tayang
            ->whereNotNull('provider_id')
            ->selectRaw('provider_id, COUNT(*) AS jumlah')
            ->groupBy('provider_id')
            ->pluck('jumlah', 'provider_id');

        $query = Provider::where('status', 1)
            ->whereIn('id', $counts->keys());

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where('name', 'like', "%{$search}%");
        }

        $providers = $query->get();

        // Attribute yang sama dibaca ProviderResource (location_count).
        $providers->each(
            fn (Provider $p) => $p->spklu_locations_count = (int) ($counts[$p->id] ?? 0)
        );

        return ProviderResource::collection(
            $providers->sortByDesc('spklu_locations_count')->values()
        )->additional(['status' => 'success']);
    }

    /**
     * Semua provider aktif — termasuk yg tidak punya SPKLU live. Dipakai picker
     * "Merk / Provider" di form Lokasi Custom mobile (bisa milik home charger /
     * brand charger box tanpa stasiun PLN). Tidak memerlukan whereHas spkluLocations.
     */
    public function all(Request $request)
    {
        $query = Provider::where('status', 1);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where('name', 'like', "%{$search}%");
        }

        $providers = $query->orderBy('name')->get();

        return ProviderResource::collection($providers)
            ->additional(['status' => 'success']);
    }
}
