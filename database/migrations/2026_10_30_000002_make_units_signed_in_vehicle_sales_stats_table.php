<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * units jadi SIGNED: file GAIKINDO memuat koreksi negatif (mis. TOYOTA
 * bZ4X EV 2024: MAR (70) = -70 retur unit). Tanpa ini, import XLSX
 * konverter akan gagal menulis koreksi dan Σ bulan di DB bengkak vs
 * rekap resmi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('ev')->table('vehicle_sales_stats', function (Blueprint $table) {
            $table->integer('units')->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::connection('ev')->table('vehicle_sales_stats', function (Blueprint $table) {
            $table->unsignedInteger('units')->default(0)->change();
        });
    }
};
