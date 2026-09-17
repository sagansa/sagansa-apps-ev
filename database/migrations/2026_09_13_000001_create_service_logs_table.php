<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel `service_logs` — catatan servis kendaraan (fitur Pro). Tiap entri bisa
 * punya interval berulang (`interval_months` dan/atau `interval_km`); server
 * hitung `next_due_date`/`next_due_km` saat store/update — sumber jadwal
 * pengingat servis di mobile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('ev')->create('service_logs', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('vehicle_id', 36)->index();
            // Nullable tanpa FK — preseden cross-connection `batteries`:
            // ownership di-enforce di application layer (Auth::user()->serviceLogs()).
            $table->bigInteger('user_id')->unsigned()->nullable()->index();
            $table->date('date');
            $table->bigInteger('odometer_km')->nullable();
            $table->string('service_type', 50);
            $table->string('workshop', 150)->nullable();
            $table->bigInteger('cost_rp')->nullable();
            $table->text('notes')->nullable();
            // Interval berulang — salah satu atau keduanya boleh null (tanpa pengingat).
            $table->unsignedSmallInteger('interval_months')->nullable();
            $table->bigInteger('interval_km')->nullable();
            // Hasil hitung server: date + interval_months / odometer_km + interval_km.
            $table->date('next_due_date')->nullable();
            $table->bigInteger('next_due_km')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();

            $table->foreign('vehicle_id')
                ->references('id')->on('vehicles')
                ->onDelete('cascade')->onUpdate('cascade');

            $table->index(['vehicle_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::connection('ev')->dropIfExists('service_logs');
    }
};
