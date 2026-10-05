<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tautan data All Map → data charger terstruktur (alur ChargeResource):
 * sesi charging dari mobile harus merujuk charger_location_id + charger_id
 * yang berbasis data yang ada, bukan hanya snapshot string.
 *
 *  1. charger_locations.charging_station_id — soft-link (tanpa FK, id bisa
 *     di-rehydrate ulang oleh sync sumber) ke charging_stations.id agar
 *     lokasi hasil "pindahan" All Map bisa di-reuse antar sesi.
 *  2. chargers.station_chargerbox_id — soft-link ke identitas charger box
 *     canonical (charging_station_chargers.chargerbox_id, fallback id row)
 *     agar charger hasil pindahan bisa di-match saat sesi berikutnya, dan
 *     charger box baru dari map ditambahkan sebagai baris charger baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('ev')->table('charger_locations', function (Blueprint $table) {
            if (! Schema::connection('ev')->hasColumn('charger_locations', 'charging_station_id')) {
                $table->unsignedBigInteger('charging_station_id')->nullable()->after('master_location_id');
                $table->index('charging_station_id', 'charger_locations_charging_station_id_index');
            }
        });

        Schema::connection('ev')->table('chargers', function (Blueprint $table) {
            if (! Schema::connection('ev')->hasColumn('chargers', 'station_chargerbox_id')) {
                $table->string('station_chargerbox_id')->nullable()->after('unit');
                $table->index('station_chargerbox_id', 'chargers_station_chargerbox_id_index');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('ev')->table('chargers', function (Blueprint $table) {
            if (Schema::connection('ev')->hasColumn('chargers', 'station_chargerbox_id')) {
                $table->dropIndex('chargers_station_chargerbox_id_index');
                $table->dropColumn('station_chargerbox_id');
            }
        });

        Schema::connection('ev')->table('charger_locations', function (Blueprint $table) {
            if (Schema::connection('ev')->hasColumn('charger_locations', 'charging_station_id')) {
                $table->dropIndex('charger_locations_charging_station_id_index');
                $table->dropColumn('charging_station_id');
            }
        });
    }
};
