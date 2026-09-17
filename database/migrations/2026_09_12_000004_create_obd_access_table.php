<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('ev')->create('obd_access', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('status', 16)->default('granted');
            $table->string('granted_by')->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamps();

            $table->unique('user_id');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('ev')->dropIfExists('obd_access');
    }
};
