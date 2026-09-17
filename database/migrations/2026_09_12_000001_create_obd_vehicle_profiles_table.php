<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('ev')->create('obd_vehicle_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('model_vehicle_id')->nullable();
            $table->string('platform', 32);
            $table->string('vin_pattern', 64)->nullable();
            $table->unsignedSmallInteger('year_min')->nullable();
            $table->unsignedSmallInteger('year_max')->nullable();
            $table->string('grade', 16)->default('community');
            $table->string('version', 16)->default('1.0');
            $table->string('status', 16)->default('active');
            $table->json('pid_list');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['platform', 'status']);

            $table->foreign('model_vehicle_id')
                ->references('id')
                ->on('model_vehicles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('ev')->dropIfExists('obd_vehicle_profiles');
    }
};
