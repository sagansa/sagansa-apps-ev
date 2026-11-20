<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Endpoint POST /obd/compatibility-reports tidak pernah dipakai: mobile
     * hanya membangun payload-nya (CompatibilityReport.kt) tanpa pernah
     * mengirim, sehingga tabel ini selalu kosong. Dihapus bersama controller,
     * model, dan Filament resource-nya (hasil audit referensi kendaraan).
     */
    public function up(): void
    {
        Schema::connection('ev')->dropIfExists('obd_compatibility_reports');
    }

    public function down(): void
    {
        // Tidak dikembalikan — fitur tidak pernah hidup di client mana pun.
    }
};
