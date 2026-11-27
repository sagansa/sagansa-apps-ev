<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Titik SPKLU Voltron — sumber kebenaran jaringan Voltron (bukan slice master
 * PLN). Diisi via `spklu:sync-voltron` / POST /api/v1/internal/spklu/
 * voltron-import (snapshot data/spklu_points_voltron.json hasil
 * tools/fetch_voltron.py). Upsert by external_id — replace-semantics:
 * titik yang hilang dari snapshot dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('ev')->create('voltron_points', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('external_id')->unique();
            $table->string('nama')->nullable();
            $table->text('alamat')->nullable();
            $table->string('kota')->nullable();
            $table->string('provinsi')->nullable();
            $table->decimal('latitude', 12, 8)->nullable()->index();
            $table->decimal('longitude', 12, 8)->nullable()->index();
            $table->string('operator')->nullable();
            $table->string('status')->nullable();
            $table->unsignedInteger('jumlah_poin')->default(0);
            $table->json('konektor')->nullable();
            $table->json('raw_payload')->nullable();
            $table->datetime('diambil_pada')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('ev')->dropIfExists('voltron_points');
    }
};
