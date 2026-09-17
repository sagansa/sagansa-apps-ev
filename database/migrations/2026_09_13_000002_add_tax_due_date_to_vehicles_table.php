<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `tax_due_date` di `vehicles` — jatuh tempo pajak kendaraan (fitur Pro).
 * Sumber jadwal pengingat pajak H-30/H-7/H-1 di mobile; diperbarui user
 * setelah bayar ke periode berikutnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('ev')->table('vehicles', function (Blueprint $table) {
            $table->date('tax_due_date')->nullable()->after('initial_odometer');
        });
    }

    public function down(): void
    {
        Schema::connection('ev')->table('vehicles', function (Blueprint $table) {
            $table->dropColumn('tax_due_date');
        });
    }
};
