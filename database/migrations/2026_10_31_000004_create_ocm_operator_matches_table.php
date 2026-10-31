<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entity-resolution operator OpenChargeMap → providers.
 *
 * Operator OCM punya ID stabil (OperatorInfo.ID) tapi banyak POI jalur import
 * file lama hanya menyimpan judul string — maka `ocm_operator_id` nullable
 * dan match by-title diizinkan. Ribuan operator worldwide tidak dikelola
 * manual: OcmOperatorMatchService auto-match (guessProvider) + auto-provision
 * provider minimal; baris `pending` menunggu review admin di Filament.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('ev')->create('ocm_operator_matches', function (Blueprint $table) {
            $table->id();

            // ID operator OCM dari raw OperatorInfo.ID; null = match by-title
            // saja (POI jalur import file tanpa OperatorInfo).
            $table->unsignedBigInteger('ocm_operator_id')->nullable()->unique();
            $table->string('operator_title')->nullable()->index();

            $table->char('provider_id', 36)->nullable();
            // auto | manual | pending
            $table->string('match_status', 16)->default('pending')->index();
            // guess | auto_provision | manual
            $table->string('match_method', 24)->nullable();

            $table->timestamps();

            $table->foreign('provider_id')
                ->references('id')
                ->on('providers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('ev')->dropIfExists('ocm_operator_matches');
    }
};
