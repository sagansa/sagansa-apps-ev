<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('ev')->create('obd_compatibility_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('report_id')->unique();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('model_vehicle_id')->nullable();
            $table->string('platform', 32);
            $table->string('adapter_type', 16);
            $table->json('supported_pids');
            $table->json('unsupported_pids')->nullable();
            $table->boolean('opt_in')->default(false);
            $table->timestamps();

            $table->index(['model_vehicle_id', 'platform']);

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->foreign('model_vehicle_id')
                ->references('id')
                ->on('model_vehicles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('ev')->dropIfExists('obd_compatibility_reports');
    }
};
