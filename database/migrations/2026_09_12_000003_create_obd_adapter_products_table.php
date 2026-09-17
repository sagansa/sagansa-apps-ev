<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('ev')->create('obd_adapter_products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('brand', 64);
            $table->string('type', 16);
            $table->string('image_url')->nullable();
            $table->string('affiliate_url')->nullable();
            $table->decimal('price_idr', 12, 2)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('ev')->dropIfExists('obd_adapter_products');
    }
};
