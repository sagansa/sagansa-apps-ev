<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom workflow review di `obd_scan_reports`:
 *
 * - `review_status` — siklus hidup kurasi via Filament:
 *   new → reviewed → promoted (dipakai jadi/menyuntik baris
 *   `obd_vehicle_profiles`) | discarded (tidak layak).
 *   Promosi profil tetap manual via ObdVehicleProfileResource — laporan hanya
 *   berisi mode/pid/description, tanpa formula, jadi tidak cukup untuk
 *   melahirkan profil utuh secara otomatis.
 *
 * - `supported_count` / `unsupported_count` — hitungan PID per laporan yang
 *   diisi saat ingest (controller), supaya agregat penanda dukungan per model
 *   (rasio sukses untuk badge garage picker) murni SQL SUM/COUNT lintas
 *   koneksi (MySQL prod, sqlite testing) tanpa JSON_LENGTH.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('ev')->table('obd_scan_reports', function (Blueprint $table) {
            $table->string('review_status', 16)->default('new')->index()->after('profile_match'); // new | reviewed | promoted | discarded
            $table->unsignedInteger('supported_count')->default(0)->after('unsupported_pids');
            $table->unsignedInteger('unsupported_count')->default(0)->after('supported_count');
        });
    }

    public function down(): void
    {
        Schema::connection('ev')->table('obd_scan_reports', function (Blueprint $table) {
            $table->dropIndex(['review_status']);
            $table->dropColumn(['review_status', 'supported_count', 'unsupported_count']);
        });
    }
};
