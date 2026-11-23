<?php

use App\Services\ObdVehicleDedupeService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Satu kendaraan garasi = maksimal satu baris OBD per user (2026-11-24).
 *
 * Unique index (user_id, garage_vehicle_id) menahan create ganda di level DB.
 * Controller sudah idempoten, tapi tulisan lain (admin, klien lama, dua
 * perangkat bersamaan) tetap tidak boleh melanggar. NULL tidak pernah
 * bertabrakan (MySQL & SQLite menganggap NULL berbeda) → baris tanpa garasi
 * (klien lama / kendaraan tanpa profil katalog) tidak terpengaruh.
 *
 * Prasyarat index: ganda (user, garage_vehicle_id) yang SUDAH ada digabung
 * lebih dulu — di sini, bukan di perintah terpisah, agar urutan deploy tidak
 * bisa terbalik dan `migrate` tidak gagal di data lama. Entri tanpa garasi
 * TIDAK disentuh oleh migrasi (tugas `obd:dedupe-vehicles`, dry-run dulu).
 */
return new class extends Migration
{
    public function up(): void
    {
        app(ObdVehicleDedupeService::class)->collapseGarageDuplicates();

        Schema::connection('ev')->table('obd_vehicles', function (Blueprint $table) {
            $table->unique(['user_id', 'garage_vehicle_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('ev')->table('obd_vehicles', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'garage_vehicle_id']);
        });
    }
};
