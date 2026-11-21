<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Model ObdAccess memakai pluralisasi default Laravel (`obd_accesses`),
        // konsisten dengan tabel OBD lain (obd_accesses, obd_vehicles, ...).
        // Tabel aslinya dibuat tunggal (`obd_access`) oleh migrasi 2026_09_12_000004.
        Schema::connection('ev')->rename('obd_access', 'obd_accesses');
    }

    public function down(): void
    {
        Schema::connection('ev')->rename('obd_accesses', 'obd_access');
    }
};
