<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Multi-link marketplace per produk (2026-11-25 — 1 produk bisa punya
        // beberapa link toko: Shopee/Tokopedia/Lazada; pengganti affiliate_url tunggal).
        // Idempoten: DB lokal 2026-11-25 sudah memuat tabel ini tanpa catatan
        // migrasi (drift percobaan lama) — lewati bila skemanya sudah ada.
        if (Schema::connection('ev')->hasTable('obd_adapter_product_links')) {
            return;
        }

        Schema::connection('ev')->create('obd_adapter_product_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('obd_adapter_product_id')
                ->constrained('obd_adapter_products')
                ->cascadeOnDelete();
            // Slug kanonik: shopee | tokopedia | lazada | other
            $table->string('marketplace', 16);
            $table->string('url');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['obd_adapter_product_id', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::connection('ev')->dropIfExists('obd_adapter_product_links');
    }
};
