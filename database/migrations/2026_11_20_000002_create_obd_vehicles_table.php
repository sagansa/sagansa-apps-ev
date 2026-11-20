<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel `obd_vehicles` — registry kendaraan OBD2 PER-USER (2026-11-20):
 * daftar kendaraan yang tampil di tab Cockpit mobile, dipindahkan dari
 * internal storage mobile ke database backend agar tidak hilang saat
 * reinstall/pindah perangkat, dan bisa ditambahkan manual dari Filament
 * bila ada data OBD2 baru.
 *
 * Desain: PROFIL (metrik PID) TIDAK diduplikasi di sini — mobile me-resolve
 * profil dari katalog `obd_vehicle_profiles` via `model_vehicle_id` /
 * brand+model, dengan fallback Universal otomatis (lihat ObdGarageLink
 * mobile). Yang tersimpan: identitas kendaraan + preferensi user +
 * hasil discovery + konfigurasi metrik + statistik konsumsi (JSON).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('ev')->create('obd_vehicles', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            // Nullable tanpa FK — preseden `obd_scan_reports` / `service_logs`:
            // ownership di-enforce di application layer.
            $table->bigInteger('user_id')->unsigned()->nullable()->index();
            $table->unsignedBigInteger('model_vehicle_id')->nullable();
            // Kendaraan garasi terkait (vehicles.id, uuid; koneksi sama `ev`
            // — tanpa FK agar longgar saat garasi dihapus, registry tetap ada).
            $table->char('garage_vehicle_id', 36)->nullable()->index();
            $table->string('nickname')->nullable();
            // Brand/model nama mentah — bahan fallback Universal ter-personal.
            $table->string('brand_name')->nullable();
            $table->string('model_name')->nullable();
            $table->string('platform', 32)->nullable();
            $table->decimal('usable_kwh', 6, 2)->nullable();
            $table->decimal('efficiency_km_per_kwh', 5, 2)->nullable();
            $table->boolean('invert_current')->default(false);
            // Konfigurasi metrik per-kendaraan (array VehicleMetric mobile).
            $table->json('metric_config')->nullable();
            // Hasil discovery (speed/aux PID + ECU/DID hit).
            $table->json('discovered')->nullable();
            // Statistik konsumsi (odometer terakhir, efisiensi terukur).
            $table->json('usage')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->bigInteger('last_used_at')->nullable(); // epoch millis

            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('model_vehicle_id')
                ->references('id')->on('model_vehicles')
                ->nullOnDelete();

            $table->index(['user_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::connection('ev')->dropIfExists('obd_vehicles');
    }
};
