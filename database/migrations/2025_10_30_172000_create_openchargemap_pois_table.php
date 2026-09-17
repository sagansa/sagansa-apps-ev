<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POI OpenChargeMap (https://openchargemap.org) — sumber pihak ketiga,
 * CC BY 4.0 (atribusi wajib di tampilan map). Diisi via:
 *  - `ocm:import` (bulk dari data/spklu_points_openchargemap.json)
 *  - OcmHarvestService (lazy-sync saat navigasi map /ocm/nearby)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('ev')->create('openchargemap_pois', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ocm_id')->unique();
            $table->string('uuid')->nullable();
            $table->string('title')->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('town')->nullable();
            $table->string('state_or_province')->nullable();
            $table->string('postcode')->nullable();
            $table->decimal('latitude', 12, 8)->nullable()->index();
            $table->decimal('longitude', 12, 8)->nullable()->index();
            $table->string('operator')->nullable();
            $table->string('usage_cost')->nullable();
            $table->string('status_title')->nullable();
            $table->integer('number_of_points')->nullable();
            $table->unsignedTinyInteger('data_quality_level')->default(1);
            $table->json('connections')->nullable();
            $table->json('raw_payload')->nullable();
            $table->datetime('date_created')->nullable();
            $table->datetime('date_last_status_update')->nullable();
            $table->datetime('date_last_verified')->nullable();
            $table->timestamps();

            $table->index(['latitude', 'longitude']);
        });
    }

    public function down(): void
    {
        Schema::connection('ev')->dropIfExists('openchargemap_pois');
    }
};
