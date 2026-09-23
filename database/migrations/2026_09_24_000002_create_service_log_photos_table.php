<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Foto servis (Pro-only) — pola station_photos, FK cascade ke service_logs. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('ev')->create('service_log_photos', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('service_log_id', 36)->index();
            $table->string('path', 500);
            $table->timestamp('created_at')->nullable();

            $table->foreign('service_log_id')
                ->references('id')->on('service_logs')
                ->onDelete('cascade')->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        Schema::connection('ev')->dropIfExists('service_log_photos');
    }
};
