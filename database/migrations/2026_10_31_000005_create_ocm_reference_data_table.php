<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot katalog referensi OCM (/v3/referencedata/) — operator,
 * tipe konektor, level, arus. Respons compact POI hanya memuat ID integer
 * (OperatorID/ConnectionTypeID/LevelID), nama hanya ada di katalog ini.
 * Disimpan sebagai map [{id,title}] per scope, di-refresh ocm:import-operators.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('ev')->create('ocm_reference_data', function (Blueprint $table) {
            $table->string('scope', 32)->primary(); // operators|connection_types|levels|current_types
            $table->json('payload');                 // [{id, title}, ...]
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('ev')->dropIfExists('ocm_reference_data');
    }
};
