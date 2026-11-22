<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Many-to-many dua arah (keputusan owner 2026-11-22):
        // - satu profil katalog → banyak model (mis. BYD Atto 3 + Dolphin)
        // - satu model → beberapa profil, dibedakan rentang tahun
        //   (cth. Accent IV (2010-2017) vs Accent V (2018-2022))
        Schema::connection('ev')->create('obd_profile_model_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('obd_vehicle_profile_id')->constrained('obd_vehicle_profiles')->cascadeOnDelete();
            $table->foreignId('model_vehicle_id')->constrained('model_vehicles')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['obd_vehicle_profile_id', 'model_vehicle_id']);
            $table->index('model_vehicle_id');
        });

        // Backfill: mapping lama (model_vehicle_id tunggal) menjadi baris pivot.
        $rows = DB::connection('ev')->table('obd_vehicle_profiles')
            ->whereNotNull('model_vehicle_id')
            ->get(['id', 'model_vehicle_id'])
            ->map(fn ($p) => [
                'obd_vehicle_profile_id' => $p->id,
                'model_vehicle_id' => $p->model_vehicle_id,
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->all();

        if ($rows !== []) {
            DB::connection('ev')->table('obd_profile_model_vehicles')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        Schema::connection('ev')->dropIfExists('obd_profile_model_vehicles');
    }
};
