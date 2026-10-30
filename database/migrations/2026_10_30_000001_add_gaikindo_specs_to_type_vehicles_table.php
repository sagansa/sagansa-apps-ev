<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom spesifikasi GAIKINDO di type_vehicles — diisi otomatis saat
 * vehicle-sales:import-csv membaca file XLSX hasil gaikindo-converter
 * (kebijakan: nilai non-kosong terbaru menimpa, sel kosong tidak
 * menghapus). Kolom kurasi manual (image, battery_capacity, type_charger)
 * tidak tersentuh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('ev')->table('type_vehicles', function (Blueprint $table) {
            // Tanpa ->after(): DB lokal/dev bisa tertinggal migrasi powertrain
            // — posisi kolom kosmetik, kemandirian migration lebih penting.
            $table->unsignedInteger('cc')->nullable();
            $table->string('trans', 20)->nullable();
            $table->string('fuel', 20)->nullable();
            $table->string('tank', 30)->nullable();
            $table->unsignedBigInteger('gvw')->nullable();
            $table->string('gear_ratio', 30)->nullable();
            $table->string('wheel_tyre', 60)->nullable();
            $table->string('ps_hp', 30)->nullable();
            $table->unsignedInteger('wheel_base')->nullable();
            $table->string('dimension', 60)->nullable();
            $table->unsignedSmallInteger('seater')->nullable();
            $table->string('drive_sys', 30)->nullable();
            $table->unsignedSmallInteger('speed')->nullable();
            $table->unsignedTinyInteger('door')->nullable();
            $table->unsignedTinyInteger('wheels')->nullable();
            $table->string('cbu_ckd', 10)->nullable();
            $table->string('origin_country', 60)->nullable();
            $table->string('specs_source', 20)->nullable();
            $table->timestamp('specs_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('ev')->table('type_vehicles', function (Blueprint $table) {
            $table->dropColumn([
                'cc', 'trans', 'fuel', 'tank', 'gvw', 'gear_ratio', 'wheel_tyre',
                'ps_hp', 'wheel_base', 'dimension', 'seater', 'drive_sys',
                'speed', 'door', 'wheels', 'cbu_ckd', 'origin_country',
                'specs_source', 'specs_updated_at',
            ]);
        });
    }
};
