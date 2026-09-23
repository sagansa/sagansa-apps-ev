<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Log Service v2: `service_items` JSON list + `service_type` nullable.
 * Backfill satu kali: service_items = JSON:[service_type_lama].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('ev')->table('service_logs', function (Blueprint $table) {
            $table->json('service_items')->nullable()->after('service_type');
        });

        // Backfill baris existing (satu nilai per log — data historis hanya punya satu type).
        DB::connection('ev')->table('service_logs')
            ->whereNotNull('service_type')
            ->whereNull('service_items')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    if (! empty($row->service_type)) {
                        DB::connection('ev')->table('service_logs')
                            ->where('id', $row->id)
                            ->update(['service_items' => json_encode([$row->service_type])]);
                    }
                }
            }, 'id', 'id');

        Schema::connection('ev')->table('service_logs', function (Blueprint $table) {
            $table->string('service_type', 50)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::connection('ev')->table('service_logs', function (Blueprint $table) {
            $table->string('service_type', 50)->nullable(false)->change();
            $table->dropColumn('service_items');
        });
    }
};
