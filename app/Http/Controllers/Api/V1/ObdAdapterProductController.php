<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ObdAdapterProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ObdAdapterProductController extends Controller
{
    /**
     * List active OBD adapter products for the in-app store.
     */
    public function index(Request $request): JsonResponse
    {
        if (! config('obd.enabled')) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $query = ObdAdapterProduct::active();

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
                'affiliate_url' => $p->affiliate_url,
                'price_idr' => $p->price_idr,
                'description' => $p->description,
            ]),
        ]);
    }
}
