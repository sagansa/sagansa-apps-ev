<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Negara asal stasiun canonical — row existing (esdm/pln) seluruhnya
 * Indonesia sehingga default 'ID'. Row source='ocm' dari ocm:sync negara
 * lain membawa kode negara sendiri. Serving per negara (masa depan)
 * memfilter lewat (country_code, source).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('ev')->table('charging_stations', function (Blueprint $table) {
            if (! Schema::connection('ev')->hasColumn('charging_stations', 'country_code')) {
                $table->string('country_code', 2)->default('ID')->after('source_station_id');
                $table->index(['country_code', 'source']);
            }
        });
    }

    public function down(): void
    {
        Schema::connection('ev')->table('charging_stations', function (Blueprint $table) {
            if (Schema::connection('ev')->hasColumn('charging_stations', 'country_code')) {
                $table->dropIndex(['country_code', 'source']);
                $table->dropColumn('country_code');
            }
        });
    }
};
