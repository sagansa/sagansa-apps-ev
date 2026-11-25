<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Official Store (2026-11-25): link ke toko resmi kami per marketplace
        // — tampil sebagai baris toko di kartu gerbang, bukan halaman produk.
        Schema::connection('ev')->create('obd_official_stores', function (Blueprint $table) {
            $table->id();
            // Slug kanonik: shopee | tokopedia | lazada | other
            $table->string('marketplace', 16);
            $table->string('label', 64)->nullable();
            $table->string('store_url');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('ev')->dropIfExists('obd_official_stores');
    }
};
