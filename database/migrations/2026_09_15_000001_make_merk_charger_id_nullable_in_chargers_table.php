<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('ev')->table('chargers', function (Blueprint $table) {
            $table->char('merk_charger_id', 36)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::connection('ev')->table('chargers', function (Blueprint $table) {
            $table->char('merk_charger_id', 36)->nullable(false)->change();
        });
    }
};
