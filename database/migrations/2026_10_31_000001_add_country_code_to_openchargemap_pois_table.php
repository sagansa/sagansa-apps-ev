<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tandai negara asal tiap POI OpenChargeMap — sync ocm:sync berjalan per
 * negara (countrycode), jadi identitas negara harus melekat di baris POI
 * (OCM ID sendiri global, tidak menyandikan negara).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('ev')->table('openchargemap_pois', function (Blueprint $table) {
            if (! Schema::connection('ev')->hasColumn('openchargemap_pois', 'country_code')) {
                $table->string('country_code', 2)->nullable()->index()->after('ocm_id');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('ev')->table('openchargemap_pois', function (Blueprint $table) {
            if (Schema::connection('ev')->hasColumn('openchargemap_pois', 'country_code')) {
                $table->dropColumn('country_code');
            }
        });
    }
};
