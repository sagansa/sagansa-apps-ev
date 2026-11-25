<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ObdAdapterProduct;
use App\Models\ObdOfficialStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ObdAdapterProductController extends Controller
{
    /**
     * List active OBD adapter products + official stores for the in-app shop.
     *
     * 2026-11-25: 1 produk punya beberapa link marketplace (`links[]`, slug
     * kanonik) dan `official_stores[]` membawa link toko resmi per marketplace.
     * `affiliate_url` tetap diisi link aktif pertama sebagai fallback app
     * versi lama yang belum kenal bentuk baru.
     */
    public function index(Request $request): JsonResponse
    {
        if (! config('obd.enabled')) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $query = ObdAdapterProduct::active()->with(['links' => fn ($q) => $q->active()]);

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        $products = $query->get();

        return response()->json([
            'success' => true,
            'data' => $products->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'brand' => $p->brand,
                'type' => $p->type,
                'image_url' => $p->image_url,
                'affiliate_url' => $p->affiliate_url ?: $p->links->first()?->url,
                'price_idr' => $p->price_idr,
                'description' => $p->description,
                'links' => $p->links->map(fn ($link) => [
                    'marketplace' => $link->marketplace,
                    'url' => $link->url,
                ])->values(),
            ]),
            'official_stores' => ObdOfficialStore::active()
                ->get()
                ->map(fn ($store) => [
                    'marketplace' => $store->marketplace,
                    'label' => $store->label,
                    'store_url' => $store->store_url,
                ])
                ->values(),
        ]);
    }
}
