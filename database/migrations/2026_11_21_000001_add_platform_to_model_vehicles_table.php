<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Platform teknis model (cth. "E-GMP", "BYD e-Platform 3.0") — pengikat
     * profil OBD level-platform: baris obd_vehicle_profiles dgn
     * model_vehicle_id = null dipakai bersama oleh SEMUA model yang platform-
     nya sama (M6/Atto 3/Seal berbagi layout DID BMS identik).
     */
    public function up(): void
    {
        Schema::connection('ev')->table('model_vehicles', function (Blueprint $table) {
            $table->string('platform', 32)->nullable()->after('size_class');
            $table->index('platform');
        });
    }

    public function down(): void
    {
        Schema::connection('ev')->table('model_vehicles', function (Blueprint $table) {
            $table->dropIndex(['platform']);
            $table->dropColumn('platform');
        });
    }
};
