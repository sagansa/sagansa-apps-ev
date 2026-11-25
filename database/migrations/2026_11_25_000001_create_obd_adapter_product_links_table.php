<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Multi-link marketplace per produk (2026-11-25 — 1 produk bisa punya
        // beberapa link toko: Shopee/Tokopedia/Lazada; pengganti affiliate_url tunggal).
        // Idempoten: produksi 2026-11-25 sempat setengah jadi — CREATE berhasil
        // lalu ALTER index gagal (nama index otomatis Laravel >64 karakter,
        // limit identifier MySQL hosting shared) — lewati bila tabel sudah ada.
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

            // TANPA index komposit tambahan: nama otomatis Laravel melebihi
            // limit 64 karakter identifier MySQL shared hosting (1059). Index
            // FK pada obd_adapter_product_id (otomatis MySQL) cukup utk pola
            // akses per-produk — tabel ini hanya beberapa baris per produk.
        });
    }

    public function down(): void
    {
        Schema::connection('ev')->dropIfExists('obd_adapter_product_links');
    }
};
