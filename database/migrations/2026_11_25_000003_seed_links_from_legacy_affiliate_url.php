<?php

use App\Models\ObdAdapterProduct;
use App\Models\ObdAdapterProductLink;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Migrasi data (2026-11-25): affiliate_url lama yang sudah terisi → 1 baris
 * link pada tabel baru, supaya produk yang dikelola sebelumnya tetap punya
 * link tanpa input ulang di Filament. Kolom affiliate_url tetap dipertahankan
 * sebagai fallback app versi lama.
 */
return new class extends Migration {
    /**
     * Slug kanonik dari domain URL — paritas dgn marketplaceFromUrl() mobile
     * (putaran 3 §5), hanya beda: hasil slug, bukan label tampilan.
     */
    public static function marketplaceSlugFromUrl(string $url): string
    {
        if (stripos($url, 'shopee') !== false) {
            return 'shopee';
        }
        if (stripos($url, 'tokopedia') !== false) {
            return 'tokopedia';
        }
        if (stripos($url, 'lazada') !== false) {
            return 'lazada';
        }

        return 'other';
    }

    public function up(): void
    {
        if (! Schema::connection('ev')->hasTable('obd_adapter_products')) {
            return;
        }

        ObdAdapterProduct::query()
            ->whereNotNull('affiliate_url')
            ->where('affiliate_url', '!=', '')
            ->each(function (ObdAdapterProduct $product) {
                $exists = ObdAdapterProductLink::query()
                    ->where('obd_adapter_product_id', $product->id)
                    ->exists();
                if ($exists) {
                    return;
                }

                ObdAdapterProductLink::create([
                    'obd_adapter_product_id' => $product->id,
                    'marketplace' => self::marketplaceSlugFromUrl($product->affiliate_url),
                    'url' => $product->affiliate_url,
                    'is_active' => true,
                    'sort_order' => 0,
                ]);
            });
    }

    public function down(): void
    {
        // Data link hasil seed ikut hilang bersama tabelnya di migration down sebelumnya.
    }
};
