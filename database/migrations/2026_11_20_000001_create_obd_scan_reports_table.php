<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel `obd_scan_reports` — hasil scan/koneksi OBD2 yang dikirim otomatis
 * dari mobile (user ter-auth Sanctum) untuk membangun database dukungan
 * kendaraan. Menggantikan `obd_compatibility_reports` yang di-drop
 * (2026_11_19_000001) karena tidak pernah terisi: jalur kirimnya kini aktif
 * (POST /api/v1/obd/scan-reports).
 *
 * Isi: identitas kendaraan (VIN opsional — toggle privasi user), adapter +
 * protokol ELM, PID yang merespons/tidak selama sesi, hasil sweep discovery
 * (JSON, nullable — hanya kendaraan unknown), DTC, dan konteks profil yang
 * dipakai sesi (seed|backend|universal|unknown). Bahan review developer di
 * Filament untuk melahirkan baris `obd_vehicle_profiles` baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('ev')->create('obd_scan_reports', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            // Nullable tanpa FK — preseden cross-connection `service_logs`:
            // ownership di-enforce di application layer (Auth::user()->obdScanReports()).
            $table->bigInteger('user_id')->unsigned()->nullable()->index();
            $table->unsignedBigInteger('model_vehicle_id')->nullable();
            $table->char('vin', 17)->nullable()->index();
            $table->boolean('vin_shared')->default(true);
            $table->string('adapter_type', 8); // WIFI | BLE | SPP
            $table->string('elm_protocol', 64)->nullable();
            $table->string('profile_key', 64)->nullable();
            $table->unsignedBigInteger('profile_id')->nullable();
            $table->string('profile_version', 16)->nullable();
            $table->string('profile_match', 16); // seed | backend | universal | unknown
            $table->json('ecus_responded')->nullable();
            $table->json('supported_pids')->nullable();
            $table->json('unsupported_pids')->nullable();
            $table->json('discovery')->nullable();
            $table->json('dtc_codes')->nullable();
            $table->decimal('atrv_voltage', 5, 1)->nullable();
            $table->string('app_version', 32)->nullable();
            $table->string('device_platform', 16)->nullable(); // android | ios
            $table->bigInteger('session_started_at')->nullable(); // epoch millis
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('captured_at')->nullable();

            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('model_vehicle_id')
                ->references('id')->on('model_vehicles')
                ->nullOnDelete();

            $table->index(['model_vehicle_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('ev')->dropIfExists('obd_scan_reports');
    }
};
