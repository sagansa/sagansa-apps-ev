<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * State sinkronisasi OCM per negara — pengganti file
 * data/openchargemap_sync_state.json (yang hanya sanggup satu negara).
 * `last_sync_utc` jadi sumber parameter modifiedsince utk sync incremental.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('ev')->create('ocm_sync_state', function (Blueprint $table) {
            $table->id();
            $table->string('country', 2)->unique();

            $table->datetime('last_sync_utc')->nullable();
            $table->unsignedInteger('poi_count')->default(0);
            // penuh | incremental — mode sync terakhir yang sukses
            $table->string('mode', 16)->default('penuh');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('ev')->dropIfExists('ocm_sync_state');
    }
};
